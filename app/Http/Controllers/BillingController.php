<?php

namespace App\Http\Controllers;

use App\Models\Payment;
use App\Models\TokenPackage;
use App\Models\User;
use App\Services\SofizPay\SofizPayCibService;
use App\Services\TelegramNotifier;
use App\Services\Tokens\TokenService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class BillingController extends Controller
{
    /**
     * Create a SofizPay CIB (DZD) checkout for a token pack and return its URL.
     */
    public function createSofizPay(Request $request, SofizPayCibService $sofizPay): JsonResponse
    {
        if (! $sofizPay->isEnabled() || ! $sofizPay->isConfigured()) {
            return response()->json([
                'message' => 'DZD payment is not available right now. Please try again later.',
            ], 503);
        }

        $validated = $request->validate([
            'pack' => ['required', 'string', 'max:64'],
        ]);

        /** @var User $user */
        $user = $request->user();

        $package = TokenPackage::query()
            ->where('slug', $validated['pack'])
            ->where('is_active', true)
            ->first();

        if (! $package) {
            return response()->json(['message' => 'This token pack is not available.'], 404);
        }

        // Canonical, server-owned amount — never trust a client-sent price.
        $amount = round((float) $package->price_dzd, 2);

        if ($amount < $sofizPay->minAmountDzd()) {
            return response()->json([
                'message' => 'This pack is below the minimum payment amount.',
            ], 422);
        }

        $payment = Payment::create([
            'user_id' => $user->id,
            'reference' => $this->generateReference(),
            'provider' => 'sofizpay',
            'package_slug' => $package->slug,
            'tokens' => (int) $package->tokens,
            'amount' => $amount,
            'currency' => 'DZD',
            'status' => 'pending',
        ]);

        $returnUrl = route('billing.sofizpay.return', [], true)
            . '?eid=' . rawurlencode(Crypt::encryptString((string) $payment->id));

        $query = [
            'account' => $sofizPay->merchantAccount(),
            'amount' => number_format($amount, 2, '.', ''),
            'full_name' => $user->name ?: 'RIMBOAI User',
            'phone' => '',
            'email' => $user->email ?: '',
            'return_url' => $returnUrl,
            'memo' => 'RIMBOAI '.$payment->reference,
            'redirect' => (string) config('services.sofizpay.redirect', 'no'),
            'keep_return_url' => (string) config('services.sofizpay.keep_return_url', 'True'),
        ];

        Log::info('Creating SofizPay CIB transaction', [
            'payment_id' => $payment->id,
            'reference' => $payment->reference,
            'amount' => $query['amount'],
            'sandbox' => $sofizPay->isSandbox(),
        ]);

        $create = $sofizPay->createCibTransaction($query);
        $data = is_array($create['data'] ?? null) ? $create['data'] : [];

        $paymentUrl = $data['payment_url'] ?? null;

        if (! $create['success'] || ! $paymentUrl || ! filter_var($paymentUrl, FILTER_VALIDATE_URL)) {
            $payment->update([
                'status' => 'failed',
                'create_response' => $data,
            ]);

            Log::warning('SofizPay CIB create failed', [
                'payment_id' => $payment->id,
                'http_status' => $create['http_status'] ?? null,
            ]);

            return response()->json([
                'message' => 'Could not start the payment. Please try again.',
            ], 422);
        }

        $cibOrderNumber = $data['cib_transaction_id'] ?? null;
        $cibOrderId = null;
        if (! empty($data['cib_response']) && is_array($data['cib_response'])) {
            $cibOrderId = $data['cib_response']['orderId'] ?? null;
        }

        $payment->update([
            'transaction_id' => isset($data['transaction_id']) ? (string) $data['transaction_id'] : null,
            'cib_order_number' => ($cibOrderNumber !== null && $cibOrderNumber !== '') ? (string) $cibOrderNumber : null,
            'cib_order_id' => $cibOrderId ? (string) $cibOrderId : null,
            'create_response' => $data,
        ]);

        return response()->json([
            'success' => true,
            'checkout_url' => $paymentUrl,
        ]);
    }

    /**
     * Browser return URL from SofizPay/SATIM. Verified server-to-server; the
     * browser's claim is ignored — we query the CIB status endpoint ourselves.
     */
    public function sofizpayReturn(Request $request, SofizPayCibService $sofizPay, TokenService $tokens): RedirectResponse
    {
        $eid = (string) $request->query('eid', '');
        $paymentId = null;

        if ($eid !== '') {
            try {
                $paymentId = (int) Crypt::decryptString($eid);
            } catch (\Throwable $e) {
                $paymentId = null;
            }
        }

        $payment = $paymentId ? Payment::find($paymentId) : null;

        if (! $payment) {
            return $this->redirectResult('error', 'We could not find your payment session.');
        }

        // Already fulfilled — safe to show success again.
        if ($payment->isPaid()) {
            return $this->redirectResult('success', 'Payment confirmed. Your tokens have been added.', $payment->tokens);
        }

        $cibOrderNumber = $payment->cib_order_number;
        if ($cibOrderNumber === null || $cibOrderNumber === '') {
            Log::error('SofizPay CIB return: missing cib_order_number', ['payment_id' => $payment->id]);

            return $this->redirectResult('error', 'Payment session is invalid. Please start again.');
        }

        $check = $sofizPay->checkCibTransaction((string) $cibOrderNumber);
        $checkData = is_array($check['data'] ?? null) ? $check['data'] : [];

        $payment->update(['last_check_response' => $checkData]);

        if (! $check['success'] || ! $sofizPay->isPaidCheck($checkData)) {
            $hint = $sofizPay->parsePaymentFailureHint($checkData);
            $message = $hint !== null
                ? $hint
                : 'Payment not confirmed yet. If you already paid, wait a moment and try again.';

            return $this->redirectResult('failed', $message);
        }

        $paidAmount = $sofizPay->parsePaidAmountDzd($checkData);
        $expected = round((float) $payment->amount, 2);

        if ($paidAmount === null) {
            return $this->redirectResult('error', 'Could not verify the payment amount. Contact support.');
        }

        if (abs($paidAmount - $expected) > 1.0) {
            Log::warning('SofizPay CIB return: amount mismatch', [
                'payment_id' => $payment->id,
                'paid' => $paidAmount,
                'expected' => $expected,
            ]);

            return $this->redirectResult('error', 'Paid amount does not match this checkout. Contact support with your reference.');
        }

        $merchant = $sofizPay->merchantAccount();
        $dest = $sofizPay->parseDestinationAccount($checkData);
        if ($merchant !== '' && $dest !== null && $dest !== '' && $dest !== $merchant) {
            Log::warning('SofizPay CIB return: destination mismatch', [
                'payment_id' => $payment->id,
                'dest' => $dest,
            ]);

            return $this->redirectResult('error', 'Payment destination mismatch. Contact support.');
        }

        // Mark paid + credit tokens atomically and idempotently.
        DB::transaction(function () use ($payment, $tokens) {
            /** @var Payment|null $p */
            $p = Payment::where('id', $payment->id)->lockForUpdate()->first();
            if (! $p || $p->status === 'paid') {
                return;
            }

            /** @var User|null $user */
            $user = User::find($p->user_id);
            if ($user) {
                $tokens->credit($user, (int) $p->tokens, 'payment', $p->id, [
                    'reason' => 'sofizpay_purchase',
                    'reference' => $p->reference,
                    'package' => $p->package_slug,
                ]);
            }

            $p->status = 'paid';
            $p->paid_at = now();
            $p->save();
        }, 3);

        $this->notifyPurchase($payment->fresh());

        return $this->redirectResult('success', 'Payment confirmed. Your tokens have been added.', $payment->tokens);
    }

    private function generateReference(): string
    {
        do {
            $reference = 'RB'.now()->format('ymd').strtoupper(Str::random(8));
        } while (Payment::where('reference', $reference)->exists());

        return $reference;
    }

    private function redirectResult(string $status, string $message, ?int $tokens = null): RedirectResponse
    {
        $params = ['payment' => $status, 'message' => $message];
        if ($tokens !== null) {
            $params['tokens'] = $tokens;
        }

        return redirect()->to(route('pricing').'?'.http_build_query($params));
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
