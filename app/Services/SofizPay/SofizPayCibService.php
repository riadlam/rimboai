<?php

namespace App\Services\SofizPay;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * SofizPay CIB (Algerian DZD) client. Server-side GET create + server-to-server
 * status check. There is no callback signature — authenticity is established by
 * querying the status endpoint and validating paid status, amount and destination.
 *
 * Ported from the DiasZone integration.
 */
class SofizPayCibService
{
    public function baseUrl(): string
    {
        return rtrim((string) config('services.sofizpay.base_url', 'https://sofizpay.com'), '/');
    }

    public function isEnabled(): bool
    {
        return (bool) config('services.sofizpay.enabled', true);
    }

    public function isSandbox(): bool
    {
        return (bool) config('services.sofizpay.sandbox', false);
    }

    public function merchantAccount(): string
    {
        return (string) config('services.sofizpay.merchant_account', '');
    }

    public function isConfigured(): bool
    {
        return $this->merchantAccount() !== '';
    }

    public function minAmountDzd(): float
    {
        return (float) config('services.sofizpay.min_amount_dzd', 75);
    }

    public function createPath(): string
    {
        return $this->isSandbox() ? '/sandbox/make-cib-transaction/' : '/make-cib-transaction/';
    }

    public function checkPath(): string
    {
        return $this->isSandbox() ? '/sandbox/cib-transaction-check/' : '/cib-transaction-check/';
    }

    /**
     * @param  array<string, mixed>  $queryParams
     * @return array{success: bool, data: array|null, raw: string|null, http_status: int|null}
     */
    public function createCibTransaction(array $queryParams): array
    {
        $url = $this->baseUrl() . $this->createPath();
        $timeout = (int) config('services.sofizpay.timeout', 30);

        try {
            $response = Http::timeout($timeout)
                ->acceptJson()
                ->withoutRedirecting()
                ->get($url, $queryParams);

            $body = $response->body();
            $decoded = $response->json();

            if (! is_array($decoded)) {
                Log::warning('SofizPay CIB create: non-JSON response', ['http_status' => $response->status(), 'snippet' => substr($body, 0, 500)]);

                return ['success' => false, 'data' => null, 'raw' => $body, 'http_status' => $response->status()];
            }

            $ok = $response->successful() && ($decoded['success'] ?? false) === true;

            return ['success' => $ok, 'data' => $decoded, 'raw' => $body, 'http_status' => $response->status()];
        } catch (\Throwable $e) {
            Log::error('SofizPay CIB create request failed', ['error' => $e->getMessage()]);

            return ['success' => false, 'data' => null, 'raw' => null, 'http_status' => null];
        }
    }

    /**
     * @return array{success: bool, data: array|null, raw: string|null, http_status: int|null}
     */
    public function checkCibTransaction(string $orderNumber): array
    {
        $url = $this->baseUrl() . $this->checkPath();
        $timeout = (int) config('services.sofizpay.timeout', 30);

        try {
            $response = Http::timeout($timeout)
                ->acceptJson()
                ->withoutRedirecting()
                ->get($url, ['order_number' => $orderNumber]);

            $body = $response->body();
            $decoded = $response->json();

            if (! is_array($decoded)) {
                Log::warning('SofizPay CIB check: non-JSON response', ['http_status' => $response->status(), 'snippet' => substr($body, 0, 500)]);

                return ['success' => false, 'data' => null, 'raw' => $body, 'http_status' => $response->status()];
            }

            $ok = $response->successful() && ! isset($decoded['error']);

            return ['success' => $ok, 'data' => $decoded, 'raw' => $body, 'http_status' => $response->status()];
        } catch (\Throwable $e) {
            Log::error('SofizPay CIB check request failed', ['error' => $e->getMessage(), 'order_number' => $orderNumber]);

            return ['success' => false, 'data' => null, 'raw' => null, 'http_status' => null];
        }
    }

    /**
     * SofizPay CIB check success shape (from docs / live samples).
     *
     * @param  array<string, mixed>  $data
     */
    public function isPaidCheck(array $data): bool
    {
        $resp = (string) ($data['respCode'] ?? '');
        $err = $data['errorCode'] ?? null;
        $orderStatus = $data['orderStatus'] ?? null;

        $errOk = $err === 0 || $err === '0';
        $statusOk = $orderStatus === 2 || $orderStatus === '2';

        return $resp === '00' && $errOk && $statusOk;
    }

    /**
     * A clearer user-facing failure message when {@see isPaidCheck} is false.
     *
     * @param  array<string, mixed>  $data
     */
    public function parsePaymentFailureHint(array $data): ?string
    {
        $resp = (string) ($data['respCode'] ?? '');
        if ($resp !== '' && $resp !== '00') {
            $desc = trim((string) ($data['ResponseDescription'] ?? $data['responseDescription'] ?? $data['message'] ?? ''));

            return $desc !== '' ? $desc : ('Bank response code: ' . $resp . '. Payment was not completed.');
        }

        $err = $data['errorCode'] ?? null;
        if ($err !== null && $err !== '' && $err !== 0 && $err !== '0') {
            $msg = trim((string) ($data['errorMessage'] ?? $data['ErrorMessage'] ?? $data['message'] ?? ''));

            return $msg !== '' ? $msg : 'Payment could not be completed (gateway error).';
        }

        $rc = $data['ResponseCode'] ?? $data['responseCode'] ?? null;
        if ($rc !== null && (string) $rc !== '' && (string) $rc !== '0' && (string) $rc !== '00') {
            $desc = trim((string) ($data['ResponseDescription'] ?? $data['responseDescription'] ?? ''));

            return $desc !== '' ? $desc : ('Payment was not accepted (code ' . (string) $rc . ').');
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function parsePaidAmountDzd(array $data): ?float
    {
        $raw = $data['Amount'] ?? $data['amount'] ?? null;
        if ($raw === null || $raw === '') {
            return null;
        }

        return round((float) $raw, 2);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function parseDestinationAccount(array $data): ?string
    {
        $d = $data['destination_account'] ?? null;

        return $d ? (string) $d : null;
    }
}
