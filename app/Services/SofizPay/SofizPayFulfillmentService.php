<?php

namespace App\Services\SofizPay;

use App\Models\Payment;
use App\Models\User;
use App\Services\Tokens\TokenService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Single source of truth for verifying a SofizPay payment and (after admin
 * Accept) crediting tokens.
 *
 * Used by BOTH the browser return URL and the reconciliation cron.
 *
 * Security model (never trust the browser):
 *  - Payment amount + token count are frozen server-side at create time.
 *  - We re-query SofizPay server-to-server and require respCode/errorCode/orderStatus.
 *  - We validate the paid amount against our stored amount.
 *  - We validate the destination account is our merchant.
 *  - Bank-paid moves to status=review; tokens credit only via creditApprovedPayment.
 *  - Crediting is atomic + idempotent (row lock + unique token_transactions index).
 *  - canceled/failed are written only from terminal CIB codes, never from the return URL hit.
 *  - paid always wins over canceled/failed (except admin-declined rows).
 */
class SofizPayFulfillmentService
{
    public function __construct(
        private SofizPayCibService $sofizPay,
        private TokenService $tokens,
        private SofizPayPaymentTelegramNotifier $telegram,
    ) {}

    /**
     * Verify CIB status. On bank-paid, hold in review (no tokens yet).
     *
     * @return array{status: 'success'|'failed'|'error'|'canceled'|'pending', message: string, credited: bool}
     */
    public function verifyAndFulfill(Payment $payment): array
    {
        if ($payment->status === 'paid') {
            return ['status' => 'success', 'message' => __('messages.payment_confirmed'), 'credited' => false];
        }

        if ($payment->isAwaitingReview()) {
            return ['status' => 'pending', 'message' => __('messages.payment_pending'), 'credited' => false];
        }

        if ($payment->wasDeclined()) {
            return ['status' => 'failed', 'message' => __('messages.payment_not_completed'), 'credited' => false];
        }

        $cibOrderNumber = $payment->cib_order_number;
        if ($cibOrderNumber === null || $cibOrderNumber === '') {
            Log::error('SofizPay fulfil: missing cib_order_number', ['payment_id' => $payment->id]);

            return ['status' => 'error', 'message' => __('messages.payment_session_invalid'), 'credited' => false];
        }

        $check = $this->sofizPay->checkCibTransaction((string) $cibOrderNumber);
        $checkData = is_array($check['data'] ?? null) ? $check['data'] : [];

        $payment->update(['last_check_response' => $checkData]);

        if (! $check['success']) {
            $hint = $this->sofizPay->parsePaymentFailureHint($checkData);

            return [
                'status' => 'pending',
                'message' => $hint ?? __('messages.payment_pending'),
                'credited' => false,
            ];
        }

        $classification = $this->sofizPay->classifyCheck($checkData);

        if ($classification === 'pending') {
            $hint = $this->sofizPay->parsePaymentFailureHint($checkData);

            return [
                'status' => 'pending',
                'message' => $hint ?? __('messages.payment_pending'),
                'credited' => false,
            ];
        }

        if ($classification === 'canceled' || $classification === 'failed') {
            $hint = $this->sofizPay->parsePaymentFailureHint($checkData)
                ?? __('messages.payment_not_completed');
            $this->persistTerminalStatus($payment, $classification, $hint);

            return [
                'status' => $classification,
                'message' => $hint,
                'credited' => false,
            ];
        }

        $paidAmount = $this->sofizPay->parsePaidAmountDzd($checkData);
        $expected = round((float) $payment->amount, 2);

        if ($paidAmount === null) {
            return ['status' => 'error', 'message' => __('messages.payment_amount_unverified'), 'credited' => false];
        }

        if (abs($paidAmount - $expected) > 1.0) {
            Log::warning('SofizPay fulfil: amount mismatch', [
                'payment_id' => $payment->id,
                'paid' => $paidAmount,
                'expected' => $expected,
            ]);

            return ['status' => 'error', 'message' => __('messages.payment_amount_mismatch'), 'credited' => false];
        }

        $merchant = $this->sofizPay->merchantAccount();
        $dest = $this->sofizPay->parseDestinationAccount($checkData);
        if ($merchant !== '' && $dest !== null && $dest !== '' && $dest !== $merchant) {
            Log::warning('SofizPay fulfil: destination mismatch', [
                'payment_id' => $payment->id,
                'dest' => $dest,
            ]);

            return ['status' => 'error', 'message' => __('messages.payment_destination_mismatch'), 'credited' => false];
        }

        $moved = $this->markForReview($payment);

        if ($moved) {
            $this->telegram->notifyStatusChanged($payment->fresh() ?? $payment);
        }

        // Anonymous to the customer: bank settled, but show generic pending.
        return ['status' => 'pending', 'message' => __('messages.payment_pending'), 'credited' => false];
    }

    /**
     * Telegram Accept: credit tokens once and mark paid.
     *
     * @return array{ok: bool, status: string, credited: bool, message: string}
     */
    public function creditApprovedPayment(Payment $payment): array
    {
        $credited = false;
        $status = (string) $payment->status;

        DB::transaction(function () use ($payment, &$credited, &$status) {
            /** @var Payment|null $p */
            $p = Payment::where('id', $payment->id)->lockForUpdate()->first();
            if (! $p) {
                return;
            }

            $status = (string) $p->status;

            if ($p->status === 'paid') {
                return;
            }

            if ($p->status !== 'review') {
                return;
            }

            /** @var User|null $user */
            $user = User::find($p->user_id);
            if ($user && (int) $p->tokens > 0) {
                $this->tokens->credit($user, (int) $p->tokens, 'payment', $p->id, [
                    'reason' => 'sofizpay_purchase',
                    'reference' => $p->reference,
                    'package' => $p->package_slug,
                    'payment_id' => $p->id,
                    'amount_dzd' => (float) $p->amount,
                ]);
            }

            $p->status = 'paid';
            $p->paid_at = now();
            $p->reviewed_at = now();
            $p->review_decision = 'accepted';
            $p->save();

            $status = 'paid';
            $credited = true;
        }, 3);

        if ($status === 'paid') {
            $this->telegram->notifyStatusChanged($payment->fresh() ?? $payment);
        }

        return [
            'ok' => $status === 'paid',
            'status' => $status,
            'credited' => $credited,
            'message' => $status === 'paid'
                ? 'Tokens credited.'
                : 'Payment is not awaiting review.',
        ];
    }

    /**
     * Telegram Decline: mark failed, never credit. No automatic refund.
     *
     * @return array{ok: bool, status: string, message: string}
     */
    public function declinePayment(Payment $payment): array
    {
        $changed = false;
        $status = (string) $payment->status;

        DB::transaction(function () use ($payment, &$changed, &$status) {
            /** @var Payment|null $p */
            $p = Payment::where('id', $payment->id)->lockForUpdate()->first();
            if (! $p) {
                return;
            }

            $status = (string) $p->status;

            if ($p->status === 'paid') {
                return;
            }

            if ($p->status !== 'review') {
                return;
            }

            $p->status = 'failed';
            $p->reviewed_at = now();
            $p->review_decision = 'declined';
            $p->save();

            $status = 'failed';
            $changed = true;
        }, 3);

        if ($changed) {
            $this->telegram->notifyStatusChanged($payment->fresh() ?? $payment, 'Admin declined — tokens not credited');
        }

        return [
            'ok' => $changed || $status === 'failed',
            'status' => $status,
            'message' => $changed
                ? 'Declined — tokens not credited.'
                : ($status === 'paid' ? 'Already paid; cannot decline.' : 'Payment is not awaiting review.'),
        ];
    }

    /**
     * Mark a pending checkout canceled after the reconcile window. Telegram is
     * updated once; paid/review still wins if a later CIB check proves settlement.
     */
    public function abandonStale(Payment $payment, string $reason): void
    {
        if ($payment->isAwaitingReview() || $payment->isPaid()) {
            return;
        }

        $this->persistTerminalStatus($payment, 'canceled', $reason);
    }

    private function markForReview(Payment $payment): bool
    {
        $moved = false;

        DB::transaction(function () use ($payment, &$moved) {
            /** @var Payment|null $p */
            $p = Payment::where('id', $payment->id)->lockForUpdate()->first();
            if (! $p || $p->status === 'paid' || $p->status === 'review') {
                return;
            }

            // Admin-declined rows stay failed even if CIB still reports paid.
            if ($p->status === 'failed' && $p->review_decision === 'declined') {
                return;
            }

            $p->status = 'review';
            $p->save();
            $moved = true;
        }, 3);

        return $moved;
    }

    private function persistTerminalStatus(Payment $payment, string $status, ?string $hint): void
    {
        $changed = false;

        DB::transaction(function () use ($payment, $status, &$changed) {
            /** @var Payment|null $p */
            $p = Payment::where('id', $payment->id)->lockForUpdate()->first();
            if (! $p || $p->status === 'paid' || $p->status === 'review') {
                return;
            }
            if ($p->status === $status) {
                return;
            }

            $p->status = $status;
            $p->save();
            $changed = true;
        }, 3);

        if ($changed) {
            $this->telegram->notifyStatusChanged($payment->fresh(), $hint);
        }
    }
}
