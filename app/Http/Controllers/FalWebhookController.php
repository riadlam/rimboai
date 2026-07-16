<?php

namespace App\Http\Controllers;

use App\Services\FalWebhookProcessor;
use App\Services\FalWebhookVerifier;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class FalWebhookController extends Controller
{
    public function __invoke(
        Request $request,
        FalWebhookVerifier $verifier,
        FalWebhookProcessor $processor,
    ): JsonResponse {
        $started = microtime(true);

        if (! $verifier->verify($request)) {
            Log::warning('fal.webhook.rejected', [
                'ip' => $request->ip(),
                'request_id' => $request->header('X-Fal-Webhook-Request-Id'),
            ]);

            return response()->json(['message' => 'Invalid signature'], 401);
        }

        /** @var array<string, mixed> $payload */
        $payload = $request->all();

        Log::info('fal.webhook.received', [
            'request_id' => $payload['request_id'] ?? $request->header('X-Fal-Webhook-Request-Id'),
            'status' => $payload['status'] ?? null,
            'gateway_request_id' => $payload['gateway_request_id'] ?? null,
        ]);

        try {
            $processor->handle($payload);
        } catch (\Throwable $e) {
            report($e);
            Log::error('fal.webhook.processing_failed', [
                'request_id' => $payload['request_id'] ?? null,
                'error' => $e->getMessage(),
            ]);

            // Non-2xx triggers fal retries (up to ~2h) — good for transient DB issues.
            return response()->json(['message' => 'Processing failed'], 500);
        }

        Log::info('fal.webhook.ok', [
            'request_id' => $payload['request_id'] ?? null,
            'ms' => (int) round((microtime(true) - $started) * 1000),
        ]);

        return response()->json(['ok' => true]);
    }
}
