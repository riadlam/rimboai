<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;

/**
 * Point the creations Telegram bot at our Accept/Decline webhook.
 */
class TelegramSetCreationsWebhook extends Command
{
    protected $signature = 'telegram:set-creations-webhook
                            {--url= : Full HTTPS webhook URL (default: APP_URL/webhooks/telegram/creations)}
                            {--drop-pending : Drop pending updates when setting the webhook}';

    protected $description = 'setWebhook for the creations bot (SofizPay Accept/Decline)';

    public function handle(): int
    {
        $token = (string) config('services.telegram.creations_bot_token', '');
        $secret = (string) config('services.telegram.creations_webhook_secret', '');

        if ($token === '') {
            $this->error('TELEGRAM_CREATIONS_BOT_TOKEN is not set.');

            return self::FAILURE;
        }

        if ($secret === '') {
            $this->error('TELEGRAM_CREATIONS_WEBHOOK_SECRET is not set.');

            return self::FAILURE;
        }

        $url = (string) ($this->option('url') ?: rtrim((string) config('app.url'), '/').'/webhooks/telegram/creations');

        if (! str_starts_with($url, 'https://')) {
            $this->error('Webhook URL must be HTTPS: '.$url);

            return self::FAILURE;
        }

        $payload = [
            'url' => $url,
            'secret_token' => $secret,
            'allowed_updates' => json_encode(['callback_query'], JSON_THROW_ON_ERROR),
        ];

        if ($this->option('drop-pending')) {
            $payload['drop_pending_updates'] = true;
        }

        $this->info('Setting webhook → '.$url);

        try {
            $response = Http::asForm()
                ->timeout(20)
                ->post("https://api.telegram.org/bot{$token}/setWebhook", $payload);
        } catch (\Throwable $e) {
            $this->error('Request failed: '.$e->getMessage());

            return self::FAILURE;
        }

        if (! $response->successful() || ! $response->json('ok')) {
            $this->error('setWebhook failed: '.$response->body());

            return self::FAILURE;
        }

        $this->info('Webhook set.');
        $this->line((string) json_encode($response->json(), JSON_PRETTY_PRINT));

        return self::SUCCESS;
    }
}
