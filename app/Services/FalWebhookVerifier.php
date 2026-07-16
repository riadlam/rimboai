<?php

namespace App\Services;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use ParagonIE\Sodium\Compat;
use RuntimeException;

/**
 * Verifies fal.ai webhook ED25519 signatures (JWKS).
 *
 * @see https://fal.ai/docs/model-apis/model-endpoints/webhooks
 */
class FalWebhookVerifier
{
    private const JWKS_URL = 'https://rest.fal.ai/.well-known/jwks.json';

    private const JWKS_CACHE_KEY = 'fal:webhook:jwks';

    private const TIMESTAMP_TOLERANCE_SECONDS = 300;

    public function verify(Request $request): bool
    {
        $requestId = (string) $request->header('X-Fal-Webhook-Request-Id', '');
        $userId = (string) $request->header('X-Fal-Webhook-User-Id', '');
        $timestamp = (string) $request->header('X-Fal-Webhook-Timestamp', '');
        $signatureHex = (string) $request->header('X-Fal-Webhook-Signature', '');
        $body = $request->getContent();

        if ($requestId === '' || $userId === '' || $timestamp === '' || $signatureHex === '') {
            Log::warning('fal.webhook.missing_headers', [
                'has_request_id' => $requestId !== '',
                'has_user_id' => $userId !== '',
                'has_timestamp' => $timestamp !== '',
                'has_signature' => $signatureHex !== '',
            ]);

            return false;
        }

        if (! ctype_digit($timestamp)) {
            Log::warning('fal.webhook.invalid_timestamp', ['timestamp' => $timestamp]);

            return false;
        }

        $ts = (int) $timestamp;
        if (abs(time() - $ts) > self::TIMESTAMP_TOLERANCE_SECONDS) {
            Log::warning('fal.webhook.timestamp_out_of_range', [
                'timestamp' => $ts,
                'server_time' => time(),
            ]);

            return false;
        }

        try {
            $signature = hex2bin($signatureHex);
        } catch (\Throwable) {
            $signature = false;
        }

        if ($signature === false || $signature === '') {
            Log::warning('fal.webhook.invalid_signature_encoding');

            return false;
        }

        $message = implode("\n", [
            $requestId,
            $userId,
            $timestamp,
            hash('sha256', $body),
        ]);

        try {
            $keys = $this->publicKeys();
        } catch (\Throwable $e) {
            Log::error('fal.webhook.jwks_fetch_failed', [
                'error' => $e->getMessage(),
            ]);

            return false;
        }

        foreach ($keys as $publicKey) {
            try {
                if (Compat::crypto_sign_verify_detached($signature, $message, $publicKey)) {
                    return true;
                }
            } catch (\Throwable) {
                continue;
            }
        }

        Log::warning('fal.webhook.signature_mismatch', [
            'request_id' => $requestId,
            'keys_tried' => count($keys),
        ]);

        return false;
    }

    /**
     * @return list<string> raw 32-byte ED25519 public keys
     */
    private function publicKeys(): array
    {
        // Cache base64url "x" strings only — MySQL CACHE_STORE rejects raw binary
        // key bytes (utf8mb4 Incorrect string value).
        /** @var list<string>|null $cached */
        $cached = Cache::get(self::JWKS_CACHE_KEY);
        if (is_array($cached) && $cached !== []) {
            return $this->decodeCachedPublicKeys($cached);
        }

        $response = Http::timeout(10)->acceptJson()->get(self::JWKS_URL);
        if (! $response->successful()) {
            throw new RuntimeException('JWKS HTTP '.$response->status());
        }

        $keysJson = $response->json('keys');
        if (! is_array($keysJson) || $keysJson === []) {
            throw new RuntimeException('JWKS contained no keys');
        }

        $encoded = [];
        foreach ($keysJson as $keyInfo) {
            if (! is_array($keyInfo) || ! is_string($keyInfo['x'] ?? null) || $keyInfo['x'] === '') {
                continue;
            }
            $decoded = $this->base64UrlDecode($keyInfo['x']);
            if ($decoded !== null && strlen($decoded) === 32) {
                $encoded[] = $keyInfo['x'];
            }
        }

        if ($encoded === []) {
            throw new RuntimeException('JWKS contained no usable ED25519 keys');
        }

        // Max 24h per fal docs; refresh sooner so key rotation is safe.
        Cache::put(self::JWKS_CACHE_KEY, $encoded, now()->addHours(12));

        return $this->decodeCachedPublicKeys($encoded);
    }

    /**
     * @param  list<string>  $encoded
     * @return list<string>
     */
    private function decodeCachedPublicKeys(array $encoded): array
    {
        $keys = [];
        foreach ($encoded as $x) {
            if (! is_string($x) || $x === '') {
                continue;
            }
            $decoded = $this->base64UrlDecode($x);
            if ($decoded !== null && strlen($decoded) === 32) {
                $keys[] = $decoded;
            }
        }

        if ($keys === []) {
            throw new RuntimeException('Cached JWKS contained no usable ED25519 keys');
        }

        return $keys;
    }

    private function base64UrlDecode(string $value): ?string
    {
        $padded = strtr($value, '-_', '+/');
        $pad = strlen($padded) % 4;
        if ($pad > 0) {
            $padded .= str_repeat('=', 4 - $pad);
        }

        $decoded = base64_decode($padded, true);

        return $decoded === false ? null : $decoded;
    }
}
