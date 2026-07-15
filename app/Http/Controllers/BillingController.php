<?php

namespace App\Http\Controllers;

use App\Models\Payment;
use App\Models\TokenPackage;
use App\Models\User;
use App\Services\SofizPay\SofizPayCibService;
use App\Services\SofizPay\SofizPayFulfillmentService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;

class BillingController extends Controller
{
    /**
     * Show only the authenticated user's safe billing data. Provider payloads,
     * transaction IDs and gateway order IDs are intentionally never exposed.
     */
    public function history(Request $request): Response
    {
        /** @var User $user */
        $user = $request->user();
        $status = (string) $request->query('status', 'all');
        $allowedStatuses = ['all', 'paid', 'pending', 'failed', 'canceled'];

        if (! in_array($status, $allowedStatuses, true)) {
            $status = 'all';
        }

        $base = Payment::query()->where('user_id', $user->id);
        $payments = (clone $base)
            ->when($status !== 'all', fn ($query) => $query->where('status', $status))
            ->latest('id')
            ->paginate(12)
            ->withQueryString()
            ->through(fn (Payment $payment) => [
                'reference' => $payment->reference,
                'package' => $payment->package_slug,
                'tokens' => (int) $payment->tokens,
                'amount' => (float) $payment->amount,
                'currency' => $payment->currency,
                'status' => $payment->status,
                'created_at' => $payment->created_at?->toIso8601String(),
                'paid_at' => $payment->paid_at?->toIso8601String(),
            ]);

        $stats = (clone $base)
            ->selectRaw('COUNT(*) as total_count')
            ->selectRaw("SUM(CASE WHEN status = 'paid' THEN 1 ELSE 0 END) as paid_count")
            ->selectRaw("COALESCE(SUM(CASE WHEN status = 'paid' THEN amount ELSE 0 END), 0) as paid_amount")
            ->selectRaw("COALESCE(SUM(CASE WHEN status = 'paid' THEN tokens ELSE 0 END), 0) as purchased_tokens")
            ->first();

        return Inertia::render('BillingHistory', [
            'payments' => $payments,
            'filters' => ['status' => $status],
            'stats' => [
                'total_count' => (int) ($stats?->total_count ?? 0),
                'paid_count' => (int) ($stats?->paid_count ?? 0),
                'paid_amount' => (float) ($stats?->paid_amount ?? 0),
                'purchased_tokens' => (int) ($stats?->purchased_tokens ?? 0),
            ],
        ]);
    }

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

        // SofizPay rejects empty phone/email ("Full name, phone, and email are required").
        $phone = trim((string) ($user->phone ?? ''));
        if ($phone === '') {
            $phone = '+213000000000';
        }

        $email = trim((string) ($user->email ?? ''));
        if ($email === '') {
            $email = 'customer@rimboai.com';
        }

        $query = [
            'account' => $sofizPay->merchantAccount(),
            'amount' => number_format($amount, 2, '.', ''),
            'full_name' => trim((string) ($user->name ?: 'RIMBOAI User')) ?: 'RIMBOAI User',
            'phone' => $phone,
            'email' => $email,
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
            'return_url' => $returnUrl,
        ]);

        $create = $sofizPay->createCibTransaction($query);
        $data = is_array($create['data'] ?? null) ? $create['data'] : [];

        $paymentUrl = $data['payment_url'] ?? null;

        if (! $create['success'] || ! $paymentUrl || ! filter_var($paymentUrl, FILTER_VALIDATE_URL)) {
            $payment->update([
                'status' => 'failed',
                'create_response' => $data !== [] ? $data : ['raw' => $create['raw'] ?? null],
            ]);

            $gatewayError = null;
            foreach (['error', 'message', 'detail'] as $key) {
                if (isset($data[$key]) && is_string($data[$key]) && $data[$key] !== '') {
                    $gatewayError = $data[$key];
                    break;
                }
            }

            Log::warning('SofizPay CIB create failed', [
                'payment_id' => $payment->id,
                'http_status' => $create['http_status'] ?? null,
                'gateway_error' => $gatewayError,
                'data' => $data,
            ]);

            return response()->json([
                'message' => $gatewayError ?: 'Could not start the payment. Please try again.',
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
    public function sofizpayReturn(Request $request, SofizPayFulfillmentService $fulfillment): RedirectResponse
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

        $result = $fulfillment->verifyAndFulfill($payment);

        $tokensCredited = $result['status'] === 'success' ? $payment->fresh()->tokens : null;

        return $this->redirectResult($result['status'], $result['message'], $tokensCredited);
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
}
