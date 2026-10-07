<?php

namespace App\Http\Controllers;

use App\Models\Payment;
use App\Services\SofizPay\SofizPayFulfillmentService;
use App\Services\TelegramNotifier;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * Inbound updates for the creations Telegram bot (Accept / Decline payments).
 */
class TelegramCreationsWebhookController extends Controller
{
    public function __invoke(Request $request, SofizPayFulfillmentService $fulfillment): JsonResponse
    {
        $secret = (string) config('services.telegram.creations_webhook_secret', '');
        if ($secret === '') {
            Log::warning('telegram.creations.webhook.unconfigured');

            return response()->json(['message' => 'Webhook not configured'], 503);
        }

        $header = (string) $request->header('X-Telegram-Bot-Api-Secret-Token', '');
        if (! hash_equals($secret, $header)) {
            Log::warning('telegram.creations.webhook.rejected', ['ip' => $request->ip()]);

            return response()->json(['message' => 'Unauthorized'], 401);
        }

        $callback = $request->input('callback_query');
        if (! is_array($callback)) {
            // Ignore messages / other update types.
            return response()->json(['ok' => true]);
        }

        $telegram = TelegramNotifier::forCreations();
        $callbackId = (string) ($callback['id'] ?? '');
        $data = (string) ($callback['data'] ?? '');
        $chatId = (string) data_get($callback, 'message.chat.id', '');

        $expectedChat = $telegram->chatId();
        if ($expectedChat === null || $chatId === '' || $chatId !== $expectedChat) {
            $telegram->answerCallbackQuery($callbackId, 'Wrong chat.', true);

            return response()->json(['ok' => true]);
        }

        if (! preg_match('/^pay:(ok|no):(\d+)$/', $data, $m)) {
            $telegram->answerCallbackQuery($callbackId, 'Unknown action.', true);

            return response()->json(['ok' => true]);
        }

        $action = $m[1];
        $paymentId = (int) $m[2];
        $payment = Payment::query()->find($paymentId);

        if (! $payment || $payment->provider !== 'sofizpay') {
            $telegram->answerCallbackQuery($callbackId, 'Payment not found.', true);

            return response()->json(['ok' => true]);
        }

        try {
            if ($action === 'ok') {
                $result = $fulfillment->creditApprovedPayment($payment);
                $telegram->answerCallbackQuery(
                    $callbackId,
                    $result['credited']
                        ? 'Accepted — tokens credited.'
                        : ($result['status'] === 'paid' ? 'Already credited.' : $result['message']),
                    ! $result['ok'],
                );
            } else {
                $result = $fulfillment->declinePayment($payment);
                $telegram->answerCallbackQuery(
                    $callbackId,
                    $result['message'],
                    ! ($result['ok'] ?? false),
                );
            }
        } catch (\Throwable $e) {
            report($e);
            Log::error('telegram.creations.webhook.failed', [
                'payment_id' => $paymentId,
                'action' => $action,
                'error' => $e->getMessage(),
            ]);
            $telegram->answerCallbackQuery($callbackId, 'Server error.', true);

            return response()->json(['ok' => false], 500);
        }

        return response()->json(['ok' => true]);
    }
}
