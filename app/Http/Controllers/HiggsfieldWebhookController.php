<?php

namespace App\Http\Controllers;

use App\Services\HiggsfieldWebhookProcessor;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class HiggsfieldWebhookController extends Controller
{
    public function __invoke(
        Request $request,
        HiggsfieldWebhookProcessor $processor,
    ): JsonResponse {
        $started = microtime(true);

        /** @var array<string, mixed> $payload */
        $payload = $request->json()->all();
        if ($payload === []) {
            $payload = $request->all();
        }

        Log::info('higgsfield.webhook.received', [
            'request_id' => $payload['request_id'] ?? null,
            'status' => $payload['status'] ?? null,
            'content_type' => $request->header('Content-Type'),
            'bytes' => strlen($request->getContent()),
        ]);

        try {
            $processor->handle($payload);
        } catch (\Throwable $e) {
            report($e);
            Log::error('higgsfield.webhook.processing_failed', [
                'request_id' => $payload['request_id'] ?? null,
                'error' => $e->getMessage(),
            ]);

            return response()->json(['message' => 'Processing failed'], 500);
        }

        Log::info('higgsfield.webhook.ok', [
            'request_id' => $payload['request_id'] ?? null,
            'ms' => (int) round((microtime(true) - $started) * 1000),
        ]);

        return response()->json(['ok' => true]);
    }
}
