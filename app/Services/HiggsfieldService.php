<?php

namespace App\Services;

use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;
use RuntimeException;

/**
 * Server-side gateway to the Higgsfield async API.
 *
 * Credentials stay server-side only (HIGGSFIELD_KEY = KEY_ID:KEY_SECRET).
 */
class HiggsfieldService
{
    public const GENJUTSU_MOTION_TRANSFER = 'higgsfield/genjutsu/motion-transfer/v1.0';

    private string $key;

    private string $baseUrl;

    public function __construct()
    {
        $this->key = (string) config('services.higgsfield.key', '');
        $this->baseUrl = rtrim((string) config('services.higgsfield.base_url', 'https://api.higgsfield.ai'), '/');
    }

    public function configured(): bool
    {
        return $this->key !== '' && str_contains($this->key, ':');
    }

    /**
     * Public HTTPS URL Higgsfield should POST when the job finishes.
     */
    public function webhookUrl(): ?string
    {
        $configured = config('services.higgsfield.webhook_url');
        if (is_string($configured) && $configured !== '') {
            $configured = trim($configured);
            if (str_starts_with($configured, 'https://')) {
                return $configured;
            }

            Log::warning('higgsfield.webhook_url_ignored_not_https', ['url' => $configured]);
        }

        $appUrl = rtrim((string) config('app.url'), '/');
        if (str_starts_with($appUrl, 'https://')) {
            return $appUrl.'/webhooks/higgsfield';
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array{
     *   request_id: string|null,
     *   status_url: string|null,
     *   cancel_url: string|null,
     *   response_url: string|null,
     *   status: string|null,
     *   raw: array<string, mixed>
     * }
     *
     * @throws RequestException|RuntimeException
     */
    public function submit(string $modelId, array $input, ?string $webhookUrl = null): array
    {
        if (! $this->configured()) {
            throw new RuntimeException('Higgsfield is not configured.');
        }

        $modelId = trim($modelId, '/');
        $url = $this->baseUrl.'/'.$modelId;
        $hook = $webhookUrl ?? $this->webhookUrl();
        if (is_string($hook) && $hook !== '') {
            $url .= (str_contains($url, '?') ? '&' : '?').'hf_webhook='.rawurlencode($hook);
        }

        Log::info('higgsfield.submit', [
            'model' => $modelId,
            'webhook' => $hook ? true : false,
        ]);

        $response = Http::withHeaders($this->headers())
            ->timeout(45)
            ->post($url, $input);

        $response->throw();
        $json = $response->json() ?? [];
        if (! is_array($json)) {
            $json = [];
        }

        $requestId = isset($json['request_id']) && is_string($json['request_id'])
            ? $json['request_id']
            : null;
        $statusUrl = isset($json['status_url']) && is_string($json['status_url'])
            ? $json['status_url']
            : ($requestId ? $this->baseUrl.'/requests/'.$requestId.'/status' : null);

        Log::info('higgsfield.submitted', [
            'model' => $modelId,
            'request_id' => $requestId,
            'status' => $json['status'] ?? null,
            'webhook' => $hook ? true : false,
        ]);

        return [
            'request_id' => $requestId,
            'status_url' => $statusUrl,
            'cancel_url' => isset($json['cancel_url']) && is_string($json['cancel_url']) ? $json['cancel_url'] : null,
            'response_url' => $statusUrl,
            'status' => isset($json['status']) ? (string) $json['status'] : null,
            'raw' => $json,
        ];
    }

    /**
     * @return array<string, mixed>
     *
     * @throws RequestException
     */
    public function statusByUrl(string $statusUrl): array
    {
        $this->assertHiggsfieldUrl($statusUrl);

        $response = Http::withHeaders($this->headers())
            ->timeout(25)
            ->get($statusUrl);

        $response->throw();

        $json = $response->json() ?? [];

        return is_array($json) ? $json : [];
    }

    /**
     * @return array<string, mixed>
     *
     * @throws RequestException
     */
    public function status(string $requestId): array
    {
        $requestId = trim($requestId);
        if ($requestId === '') {
            throw new InvalidArgumentException('Higgsfield request_id is empty.');
        }

        return $this->statusByUrl($this->baseUrl.'/requests/'.$requestId.'/status');
    }

    public static function isHiggsfieldEndpoint(?string $endpointId): bool
    {
        $id = strtolower(trim((string) $endpointId));

        return $id !== '' && str_starts_with($id, 'higgsfield/');
    }

    /**
     * USD per ceil(input second) for Genjutsu Motion Transfer.
     */
    public static function genjutsuUnitPriceUsd(string $resolution): float
    {
        $rates = config('services.higgsfield.genjutsu_motion_transfer', []);
        $key = strtolower(trim($resolution));
        if (! in_array($key, ['480p', '720p', '1080p'], true)) {
            $key = '720p';
        }

        return (float) ($rates[$key] ?? match ($key) {
            '480p' => 0.318,
            '1080p' => 1.632,
            default => 0.681,
        });
    }

    /**
     * @return array{fal_cost_usd: float, billable_units: float, unit: string, unit_price: float, breakdown: array<string, mixed>}
     */
    public static function estimateGenjutsuUsd(int $inputSeconds, string $resolution): array
    {
        $seconds = max(1, (int) ceil(max(0, $inputSeconds)));
        $unitPrice = self::genjutsuUnitPriceUsd($resolution);
        $usd = round($seconds * $unitPrice, 6);

        return [
            'fal_cost_usd' => $usd,
            'billable_units' => (float) $seconds,
            'unit' => 'input_video_seconds',
            'unit_price' => $unitPrice,
            'breakdown' => [
                'provider' => 'higgsfield',
                'model' => self::GENJUTSU_MOTION_TRANSFER,
                'resolution' => strtolower($resolution) ?: '720p',
                'input_seconds_ceil' => $seconds,
                'unit_price_usd' => $unitPrice,
                'formula' => 'ceil(input_video_seconds) * unit_price',
            ],
        ];
    }

    /**
     * @return array<string, string>
     */
    private function headers(): array
    {
        return [
            'Authorization' => 'Key '.$this->key,
            'Content-Type' => 'application/json',
            'Accept' => 'application/json',
        ];
    }

    private function assertHiggsfieldUrl(string $url): void
    {
        $host = strtolower((string) (parse_url($url, PHP_URL_HOST) ?: ''));
        if ($host === '' || (! str_ends_with($host, 'higgsfield.ai') && ! str_ends_with($host, 'higgsfield.com'))) {
            throw new InvalidArgumentException('Refusing non-Higgsfield status URL.');
        }
    }
}
