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

    /**
     * Send a message. Long messages are split to respect Telegram's 4096-char limit.
     */
    public function send(string $message): bool
    {
        if (! $this->isConfigured()) {
            $hint = $this->channel === 'creations'
                ? 'TELEGRAM_CREATIONS_BOT_TOKEN or TELEGRAM_CREATIONS_CHAT_ID / TELEGRAM_CHAT_ID'
                : 'TELEGRAM_BOT_TOKEN or TELEGRAM_CHAT_ID';
            Log::warning("TelegramNotifier [{$this->channel}] skipped — {$hint} is missing");

            return false;
        }

        $ok = true;

        foreach ($this->chunk($message) as $part) {
            $ok = $this->sendChunk($part) && $ok;
        }

        return $ok;
    }

    private function sendChunk(string $text): bool
    {
        try {
            $response = Http::asForm()
                ->timeout(15)
                ->post("https://api.telegram.org/bot{$this->token}/sendMessage", [
                    'chat_id' => $this->chatId,
                    'text' => $text,
                    'parse_mode' => 'HTML',
                    'disable_web_page_preview' => true,
                ]);

            if (! $response->successful()) {
                Log::error("Telegram [{$this->channel}] sendMessage failed", [
                    'status' => $response->status(),
                    'body' => $response->body(),
                ]);

                return false;
            }

            return true;
        } catch (\Throwable $e) {
            Log::error("Telegram [{$this->channel}] sendMessage error: ".$e->getMessage());

            return false;
        }
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
