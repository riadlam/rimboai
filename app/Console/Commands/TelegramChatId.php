<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;

/**
 * Helper to discover the chat ID for TELEGRAM_CHAT_ID.
 *
 * Send any message to your bot first (or add it to a group), then run this.
 */
class TelegramChatId extends Command
{
    protected $signature = 'telegram:chat-id';

    protected $description = 'Show recent chat IDs that have messaged the Telegram bot (for TELEGRAM_CHAT_ID)';

    public function handle(): int
    {
        $token = config('services.telegram.bot_token');

        if (! $token) {
            $this->error('TELEGRAM_BOT_TOKEN is not set in .env');

            return self::FAILURE;
        }

        $this->info('Fetching recent updates from Telegram...');
        $this->line('(If nothing shows, send a message to your bot first, then re-run.)');

        try {
            $response = Http::timeout(20)->get("https://api.telegram.org/bot{$token}/getUpdates");
        } catch (\Throwable $e) {
            $this->error('Request failed: ' . $e->getMessage());

            return self::FAILURE;
        }

        if (! $response->successful()) {
            $this->error("Telegram getUpdates failed (HTTP {$response->status()}): " . $response->body());

            return self::FAILURE;
        }

        $updates = $response->json('result', []);

        if (empty($updates)) {
            $this->warn('No updates found. Open your bot in Telegram, send it a message, then run this again.');

            return self::SUCCESS;
        }

        $seen = [];

        foreach ($updates as $update) {
            $chat = $update['message']['chat']
                ?? $update['channel_post']['chat']
                ?? $update['my_chat_member']['chat']
                ?? null;

            if (! is_array($chat) || ! isset($chat['id'])) {
                continue;
            }

            $id = (string) $chat['id'];

            if (isset($seen[$id])) {
                continue;
            }

            $seen[$id] = true;

            $name = $chat['title']
                ?? trim(($chat['first_name'] ?? '') . ' ' . ($chat['last_name'] ?? ''))
                ?: ($chat['username'] ?? 'unknown');

            $this->line(sprintf('  chat_id=%s   type=%s   name=%s', $id, $chat['type'] ?? '?', $name));
        }

        $this->newLine();
        $this->info('Copy the chat_id you want into .env as TELEGRAM_CHAT_ID, then run: php artisan config:clear');

        return self::SUCCESS;
    }
}
