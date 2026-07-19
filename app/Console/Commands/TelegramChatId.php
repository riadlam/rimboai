<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;

/**
 * Helper to discover chat IDs for the pricing / creations Telegram bots.
 *
 * Send any message to the bot first (or add it to a group), then run this.
 */
class TelegramChatId extends Command
{
    protected $signature = 'telegram:chat-id
                            {--channel=pricing : pricing|creations}';

    protected $description = 'Show recent chat IDs that have messaged a Telegram bot';

    public function handle(): int
    {
        $channel = strtolower((string) $this->option('channel'));
        if (! in_array($channel, ['pricing', 'creations'], true)) {
            $this->error('Channel must be "pricing" or "creations".');

            return self::FAILURE;
        }

        $token = $channel === 'creations'
            ? config('services.telegram.creations_bot_token')
            : config('services.telegram.bot_token');

        $envToken = $channel === 'creations' ? 'TELEGRAM_CREATIONS_BOT_TOKEN' : 'TELEGRAM_BOT_TOKEN';
        $envChat = $channel === 'creations' ? 'TELEGRAM_CREATIONS_CHAT_ID' : 'TELEGRAM_CHAT_ID';

        if (! $token) {
            $this->error("{$envToken} is not set in .env");

            return self::FAILURE;
        }

        $this->info("Fetching recent updates for [{$channel}] bot...");
        $this->line('(If nothing shows, send a message to that bot first, then re-run.)');

        try {
            $response = Http::timeout(20)->get("https://api.telegram.org/bot{$token}/getUpdates");
        } catch (\Throwable $e) {
            $this->error('Request failed: '.$e->getMessage());

            return self::FAILURE;
        }

        if (! $response->successful()) {
            $this->error("Telegram getUpdates failed (HTTP {$response->status()}): ".$response->body());

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
                ?? trim(($chat['first_name'] ?? '').' '.($chat['last_name'] ?? ''))
                ?: ($chat['username'] ?? 'unknown');

            $this->line(sprintf('  chat_id=%s   type=%s   name=%s', $id, $chat['type'] ?? '?', $name));
        }

        $this->newLine();
        $this->info("Copy the chat_id you want into .env as {$envChat}, then run: php artisan config:clear");

        return self::SUCCESS;
    }
}
