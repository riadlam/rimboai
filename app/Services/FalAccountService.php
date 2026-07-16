<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * fal Platform APIs (admin key) for wallet balance and per-request billing.
 */
class FalAccountService
{
    private string $adminKey;

    private string $base = 'https://api.fal.ai/v1';

    public function __construct()
    {
        $this->adminKey = (string) config('services.fal.admin_key', '');
    }

    public function configured(): bool
    {
        return $this->adminKey !== '';
    }

    /**
     * Current fal credit balance in USD (credits.current_balance).
     */
    public function getCreditBalance(): ?float
    {
        if (! $this->configured()) {
            return null;
        }

        try {
            $response = Http::withHeaders($this->headers())
                ->timeout(15)
                ->get("{$this->base}/account/billing", [
                    'expand' => 'credits',
                ]);

            if (! $response->successful()) {
                Log::warning('fal account billing failed', [
                    'status' => $response->status(),
                    'body' => $response->body(),
                ]);

                return null;
            }

            $balance = data_get($response->json(), 'credits.current_balance');

            return is_numeric($balance) ? (float) $balance : null;
        } catch (\Throwable $e) {
            report($e);

            return null;
        }
    }

    /**
     * Actual fal cost for a request from billing-events (nano USD → USD).
     */
    public function getRequestCostUsd(string $requestId): ?float
    {
        if (! $this->configured() || $requestId === '') {
            return null;
        }

        try {
            $response = Http::withHeaders($this->headers())
                ->timeout(15)
                ->get("{$this->base}/models/billing-events", [
                    'request_id' => $requestId,
                    'limit' => 1,
                ]);

            if (! $response->successful()) {
                Log::warning('fal billing-events failed', [
                    'request_id' => $requestId,
                    'status' => $response->status(),
                    'body' => $response->body(),
                ]);

                return null;
            }

            $events = data_get($response->json(), 'billing_events', []);
            if (! is_array($events) || $events === []) {
                return null;
            }

            $nano = $events[0]['cost_estimate_nano_usd'] ?? null;
            if (! is_numeric($nano)) {
                return null;
            }

            return ((float) $nano) / 1_000_000_000;
        } catch (\Throwable $e) {
            report($e);

            return null;
        }
    }

    /**
     * @return array<string, string>
     */
    private function headers(): array
    {
        return [
            'Authorization' => 'Key '.$this->adminKey,
            'Accept' => 'application/json',
        ];
    }
}
