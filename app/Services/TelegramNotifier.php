<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Thin wrapper around the Telegram Bot API sendMessage endpoint.
 *
 * Used by the pricing cron to report each run's result. Failures are logged
 * and swallowed so a Telegram outage never breaks the sync itself.
 */
class TelegramNotifier
{
    private ?string $token;

    private ?string $chatId;

    public function __construct()
    {
        $this->token = config('services.telegram.bot_token');
        $this->chatId = config('services.telegram.chat_id');
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
            Log::warning('TelegramNotifier skipped — TELEGRAM_BOT_TOKEN or TELEGRAM_CHAT_ID is missing');

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
                Log::error('Telegram sendMessage failed', [
                    'status' => $response->status(),
                    'body' => $response->body(),
                ]);

                return false;
            }

            return true;
        } catch (\Throwable $e) {
            Log::error('Telegram sendMessage error: ' . $e->getMessage());

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
                $current = $current === '' ? $line : $current . "\n" . $line;
            }
        }

        if ($current !== '') {
            $chunks[] = $current;
        }

        return $chunks;
    }
}
