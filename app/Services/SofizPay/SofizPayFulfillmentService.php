<?php

namespace App\Services\SofizPay;

use App\Models\Payment;
use App\Models\User;
use App\Services\TelegramNotifier;
use App\Services\Tokens\TokenService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Single source of truth for verifying a SofizPay payment and crediting tokens.
 *
 * Used by BOTH the browser return URL and the reconciliation cron, so a payment
 * is fulfilled exactly once regardless of whether the user came back to the site.
 *
 * Security model (never trust the browser):
 *  - Payment amount + token count are frozen server-side at create time.
 *  - We re-query SofizPay server-to-server and require respCode/errorCode/orderStatus.
 *  - We validate the paid amount against our stored amount.
 *  - We validate the destination account is our merchant.
 *  - Crediting is atomic + idempotent (row lock + unique token_transactions index).
 */
class SofizPayFulfillmentService
{
    public function __construct(
        private SofizPayCibService $sofizPay,
        private TokenService $tokens,
    ) {}

    /**
     * Verify and (if paid) fulfil a payment.
     *
     * @return array{status: 'success'|'failed'|'error', message: string, credited: bool}
     */
    public function verifyAndFulfill(Payment $payment): array
    {
        if ($payment->status === 'paid') {
            return ['status' => 'success', 'message' => 'Payment confirmed. Your tokens have been added.', 'credited' => false];
        }

        $cibOrderNumber = $payment->cib_order_number;
        if ($cibOrderNumber === null || $cibOrderNumber === '') {
            Log::error('SofizPay fulfil: missing cib_order_number', ['payment_id' => $payment->id]);

            return ['status' => 'error', 'message' => 'Payment session is invalid. Please start again.', 'credited' => false];
        }

        $check = $this->sofizPay->checkCibTransaction((string) $cibOrderNumber);
        $checkData = is_array($check['data'] ?? null) ? $check['data'] : [];

        $payment->update(['last_check_response' => $checkData]);

        if (! $check['success'] || ! $this->sofizPay->isPaidCheck($checkData)) {
            $hint = $this->sofizPay->parsePaymentFailureHint($checkData);

            return [
                'status' => 'failed',
                'message' => $hint ?? 'Payment not confirmed yet. If you already paid, wait a moment and try again.',
                'credited' => false,
            ];
        }

        $paidAmount = $this->sofizPay->parsePaidAmountDzd($checkData);
        $expected = round((float) $payment->amount, 2);

        if ($paidAmount === null) {
            return ['status' => 'error', 'message' => 'Could not verify the payment amount. Contact support.', 'credited' => false];
        }

        if (abs($paidAmount - $expected) > 1.0) {
            Log::warning('SofizPay fulfil: amount mismatch', [
                'payment_id' => $payment->id,
                'paid' => $paidAmount,
                'expected' => $expected,
            ]);

            return ['status' => 'error', 'message' => 'Paid amount does not match this checkout. Contact support with your reference.', 'credited' => false];
        }

        $merchant = $this->sofizPay->merchantAccount();
        $dest = $this->sofizPay->parseDestinationAccount($checkData);
        if ($merchant !== '' && $dest !== null && $dest !== '' && $dest !== $merchant) {
            Log::warning('SofizPay fulfil: destination mismatch', [
                'payment_id' => $payment->id,
                'dest' => $dest,
            ]);

            return ['status' => 'error', 'message' => 'Payment destination mismatch. Contact support.', 'credited' => false];
        }

        $credited = false;

        DB::transaction(function () use ($payment, &$credited) {
            /** @var Payment|null $p */
            $p = Payment::where('id', $payment->id)->lockForUpdate()->first();
            if (! $p || $p->status === 'paid') {
                return;
            }

            /** @var User|null $user */
            $user = User::find($p->user_id);
            if ($user && (int) $p->tokens > 0) {
                $this->tokens->credit($user, (int) $p->tokens, 'payment', $p->id, [
                    'reason' => 'sofizpay_purchase',
                    'reference' => $p->reference,
                    'package' => $p->package_slug,
                ]);
            }

            $p->status = 'paid';
            $p->paid_at = now();
            $p->save();

            $credited = true;
        }, 3);

        if ($credited) {
            $this->notifyPurchase($payment->fresh());
        }

        return ['status' => 'success', 'message' => 'Payment confirmed. Your tokens have been added.', 'credited' => $credited];
    }

    private function notifyPurchase(?Payment $payment): void
    {
        if (! $payment) {
            return;
        }

        try {
            $notifier = app(TelegramNotifier::class);
            if (! $notifier->isConfigured()) {
                return;
            }

            $user = User::find($payment->user_id);
            $notifier->send(implode("\n", [
                '<b>💳 New token purchase</b>',
                'Pack: '.htmlspecialchars((string) $payment->package_slug, ENT_QUOTES, 'UTF-8'),
                'Tokens: '.number_format((int) $payment->tokens),
                'Amount: '.number_format((float) $payment->amount, 2).' DZD',
                'User: '.htmlspecialchars((string) ($user->email ?? $payment->user_id), ENT_QUOTES, 'UTF-8'),
                'Ref: '.htmlspecialchars((string) $payment->reference, ENT_QUOTES, 'UTF-8'),
            ]));
        } catch (\Throwable $e) {
            report($e);
        }
    }
}
