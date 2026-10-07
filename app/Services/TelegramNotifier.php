<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Thin wrapper around the Telegram Bot API sendMessage endpoint.
 *
 * Two bots are supported:
 * - pricing  → TELEGRAM_BOT_TOKEN / TELEGRAM_CHAT_ID (model pricing sync)
 * - creations → TELEGRAM_CREATIONS_BOT_TOKEN / TELEGRAM_CREATIONS_CHAT_ID
 *               (user creations + payments; chat falls back to TELEGRAM_CHAT_ID)
 *
 * Failures are logged and swallowed so Telegram outages never break the app.
 */
class TelegramNotifier
{
    private ?string $token;

    private ?string $chatId;

    private string $channel;

    public function __construct(?string $token = null, ?string $chatId = null, string $channel = 'pricing')
    {
        $this->channel = $channel;
        $this->token = $token ?? config('services.telegram.bot_token');
        $this->chatId = $chatId ?? config('services.telegram.chat_id');
    }

    /** Model pricing / fal sync reports. */
    public static function forPricing(): self
    {
        return new self(
            config('services.telegram.bot_token'),
            config('services.telegram.chat_id'),
            'pricing',
        );
    }

    /** User creations + token purchases. */
    public static function forCreations(): self
    {
        $chatId = config('services.telegram.creations_chat_id')
            ?: config('services.telegram.chat_id');

        return new self(
            config('services.telegram.creations_bot_token'),
            $chatId,
            'creations',
        );
    }

    public function isConfigured(): bool
    {
        return ! empty($this->token) && ! empty($this->chatId);
    }

    public function chatId(): ?string
    {
        return $this->chatId !== null && $this->chatId !== '' ? (string) $this->chatId : null;
    }

    /**
     * Send a message. Long messages are split to respect Telegram's 4096-char limit.
     *
     * @param  array<string, mixed>|null  $replyMarkup
     */
    public function send(string $message, ?array $replyMarkup = null): bool
    {
        if (! $this->isConfigured()) {
            $this->logUnconfigured();

            return false;
        }

        $ok = true;

        foreach ($this->chunk($message) as $i => $part) {
            // Only attach keyboard to the first chunk.
            $ok = $this->postSendMessage($part, $i === 0 ? $replyMarkup : null)['ok'] && $ok;
        }

        return $ok;
    }

    /**
     * Send a message and return Telegram's message_id (first chunk).
     *
     * @param  array<string, mixed>|null  $replyMarkup
     */
    public function sendReturningId(string $text, ?array $replyMarkup = null): ?int
    {
        if (! $this->isConfigured()) {
            $this->logUnconfigured();

            return null;
        }

        $firstId = null;

        foreach ($this->chunk($text) as $i => $part) {
            $result = $this->postSendMessage($part, $i === 0 ? $replyMarkup : null);
            if (! $result['ok']) {
                return $firstId;
            }
            if ($i === 0) {
                $firstId = $result['message_id'];
            }
        }

        return $firstId;
    }

    /**
     * Edit an existing message in the same chat. Returns false if the message
     * is gone so the caller can send a replacement.
     *
     * @param  array<string, mixed>|null  $replyMarkup  Pass [] inline_keyboard via removeKeyboard
     */
    public function edit(int $messageId, string $text, ?array $replyMarkup = null, bool $removeKeyboard = false): bool
    {
        if (! $this->isConfigured() || $messageId <= 0) {
            return false;
        }

        try {
            $payload = [
                'chat_id' => $this->chatId,
                'message_id' => $messageId,
                'text' => $text,
                'parse_mode' => 'HTML',
                'disable_web_page_preview' => true,
            ];

            if ($removeKeyboard) {
                $payload['reply_markup'] = json_encode(['inline_keyboard' => []], JSON_THROW_ON_ERROR);
            } elseif ($replyMarkup !== null) {
                $payload['reply_markup'] = json_encode($replyMarkup, JSON_THROW_ON_ERROR);
            }

            $response = Http::asForm()
                ->timeout(15)
                ->post("https://api.telegram.org/bot{$this->token}/editMessageText", $payload);

            if ($response->successful()) {
                return true;
            }

            $description = strtolower((string) ($response->json('description') ?? ''));
            if (str_contains($description, 'message is not modified')) {
                return true;
            }

            Log::error("Telegram [{$this->channel}] editMessageText failed", [
                'status' => $response->status(),
                'body' => $response->body(),
                'message_id' => $messageId,
            ]);

            return false;
        } catch (\Throwable $e) {
            Log::error("Telegram [{$this->channel}] editMessageText error: ".$e->getMessage());

            return false;
        }
    }

    public function answerCallbackQuery(string $callbackQueryId, ?string $text = null, bool $showAlert = false): bool
    {
        if (! $this->isConfigured() || $callbackQueryId === '') {
            return false;
        }

        try {
            $payload = [
                'callback_query_id' => $callbackQueryId,
                'show_alert' => $showAlert ? 'true' : 'false',
            ];
            if ($text !== null && $text !== '') {
                $payload['text'] = $text;
            }

            $response = Http::asForm()
                ->timeout(10)
                ->post("https://api.telegram.org/bot{$this->token}/answerCallbackQuery", $payload);

            return $response->successful();
        } catch (\Throwable $e) {
            Log::error("Telegram [{$this->channel}] answerCallbackQuery error: ".$e->getMessage());

            return false;
        }
    }

    /**
     * @param  array<string, mixed>|null  $replyMarkup
     * @return array{ok: bool, message_id: int|null}
     */
    private function postSendMessage(string $text, ?array $replyMarkup = null): array
    {
        try {
            $payload = [
                'chat_id' => $this->chatId,
                'text' => $text,
                'parse_mode' => 'HTML',
                'disable_web_page_preview' => true,
            ];
            if ($replyMarkup !== null) {
                $payload['reply_markup'] = json_encode($replyMarkup, JSON_THROW_ON_ERROR);
            }

            $response = Http::asForm()
                ->timeout(15)
                ->post("https://api.telegram.org/bot{$this->token}/sendMessage", $payload);

            if (! $response->successful()) {
                Log::error("Telegram [{$this->channel}] sendMessage failed", [
                    'status' => $response->status(),
                    'body' => $response->body(),
                ]);

                return ['ok' => false, 'message_id' => null];
            }

            $id = $response->json('result.message_id');

            return [
                'ok' => true,
                'message_id' => is_numeric($id) && (int) $id > 0 ? (int) $id : null,
            ];
        } catch (\Throwable $e) {
            Log::error("Telegram [{$this->channel}] sendMessage error: ".$e->getMessage());

            return ['ok' => false, 'message_id' => null];
        }
    }

    private function logUnconfigured(): void
    {
        $hint = $this->channel === 'creations'
            ? 'TELEGRAM_CREATIONS_BOT_TOKEN or TELEGRAM_CREATIONS_CHAT_ID / TELEGRAM_CHAT_ID'
            : 'TELEGRAM_BOT_TOKEN or TELEGRAM_CHAT_ID';
        Log::warning("TelegramNotifier [{$this->channel}] skipped — {$hint} is missing");
    }

    /**
     * Split into <=4096 char chunks, preferring to break on newlines.
     *
     * @return list<string>
     */
    private function chunk(string $message, int $limit = 4000): array
    {
        if (mb_strlen($message) <= $limit) {
            return [$message];
        }

        $chunks = [];
        $current = '';

        foreach (explode("\n", $message) as $line) {
            if (mb_strlen($current) + mb_strlen($line) + 1 > $limit) {
                if ($current !== '') {
                    $chunks[] = $current;
                }
                $current = $line;
            } else {
                $current = $current === '' ? $line : $current."\n".$line;
            }
        }

        if ($current !== '') {
            $chunks[] = $current;
        }

        return $chunks;
    }
}
