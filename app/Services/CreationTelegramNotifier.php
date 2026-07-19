<?php

namespace App\Services;

use App\Models\User;
use App\Models\UserImageCreation;
use App\Models\UserMusicCreation;
use App\Models\UserVideoCreation;
use App\Models\UserVoiceCreation;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Telegram alerts for Lab / Tools / Trends creations.
 * Failures are logged and never break generation.
 */
class CreationTelegramNotifier
{
    private TelegramNotifier $telegram;

    public function __construct()
    {
        $this->telegram = TelegramNotifier::forCreations();
    }

    public function notifyStarted(User $user, string $creationType, Model $creation): void
    {
        if (! $this->telegram->isConfigured()) {
            return;
        }

        try {
            $creation->refresh();
            $settings = is_array($creation->getAttribute('settings')) ? $creation->getAttribute('settings') : [];
            $estimatedUsd = $settings['fal_cost_usd'] ?? null;
            $credits = $creation->getAttribute('credits_charged') ?? ($settings['credits'] ?? null);
            $mode = (string) ($creation->getAttribute('mode') ?? '');
            $toolSlug = str_starts_with($mode, 'tool:') ? substr($mode, 5) : null;

            $lines = [
                '<b>New creation</b>',
                'Type: '.$this->e($creationType),
                'ID: '.(int) $creation->getKey(),
                'Status: '.$this->e((string) ($creation->getAttribute('status') ?? '')),
                'User: '.$this->e((string) ($user->email ?? '')).' (#'.(int) $user->getKey().')',
                'Balance: '.number_format((int) $user->tokens).' tokens',
                'Tokens charged: '.$this->fmtNum($credits),
                'Est. fal USD: '.$this->fmtUsd($estimatedUsd),
                'cost_usd: '.$this->fmtUsd($creation->getAttribute('cost_usd')),
                'Mode: '.$this->e($mode !== '' ? $mode : '—'),
            ];

            if ($toolSlug) {
                $lines[] = 'Tool: '.$this->e($toolSlug);
            }

            $lines[] = 'Model: '.$this->e((string) ($creation->getAttribute('model_name') ?? '—'));
            $lines[] = 'Endpoint: '.$this->e((string) ($creation->getAttribute('endpoint_id') ?? '—'));

            $aspect = $creation->getAttribute('aspect_ratio') ?? ($settings['aspect'] ?? null);
            $resolution = $creation->getAttribute('resolution') ?? ($settings['resolution'] ?? null);
            $duration = $creation->getAttribute('duration_seconds')
                ?? $creation->getAttribute('duration_value')
                ?? ($settings['duration'] ?? null);

            if ($aspect !== null && $aspect !== '') {
                $lines[] = 'Aspect: '.$this->e((string) $aspect);
            }
            if ($resolution !== null && $resolution !== '') {
                $lines[] = 'Resolution: '.$this->e((string) $resolution);
            }
            if ($duration !== null && $duration !== '') {
                $lines[] = 'Duration: '.$this->e((string) $duration);
            }

            $prompt = (string) ($creation->getAttribute('prompt') ?? '');
            if ($prompt !== '') {
                $lines[] = 'Prompt: '.$this->e($this->truncate($prompt, 500));
            }

            $this->telegram->send(implode("\n", $lines));
        } catch (Throwable $e) {
            report($e);
            Log::warning('CreationTelegramNotifier notifyStarted failed', [
                'type' => $creationType,
                'creation_id' => $creation->getKey(),
                'error' => $e->getMessage(),
            ]);
        }
    }

    public function notifyCostSettled(string $creationType, Model $creation): void
    {
        if (! $this->telegram->isConfigured()) {
            return;
        }

        try {
            $creation->refresh();
            $user = User::query()->find($creation->getAttribute('user_id'));
            $settings = is_array($creation->getAttribute('settings')) ? $creation->getAttribute('settings') : [];
            $credits = $creation->getAttribute('credits_charged') ?? ($settings['credits'] ?? null);
            $mode = (string) ($creation->getAttribute('mode') ?? '');

            $lines = [
                '<b>Creation cost settled</b>',
                'Type: '.$this->e($creationType),
                'ID: '.(int) $creation->getKey(),
                'Status: '.$this->e((string) ($creation->getAttribute('status') ?? '')),
                'User: '.$this->e((string) ($user?->email ?? '—')).' (#'.(int) ($creation->getAttribute('user_id') ?? 0).')',
                'Balance: '.number_format((int) ($user?->tokens ?? 0)).' tokens',
                'Tokens charged: '.$this->fmtNum($credits),
                'cost_usd: '.$this->fmtUsd($creation->getAttribute('cost_usd')),
                'Wallet before: '.$this->fmtUsd($creation->getAttribute('fal_wallet_balance_before')),
                'Wallet after: '.$this->fmtUsd($creation->getAttribute('fal_wallet_balance_after')),
                'Deducted: '.$this->fmtUsd($creation->getAttribute('deducted_amount_from_main_wallet')),
                'Mode: '.$this->e($mode !== '' ? $mode : '—'),
                'Model: '.$this->e((string) ($creation->getAttribute('model_name') ?? '—')),
            ];

            $this->telegram->send(implode("\n", $lines));
        } catch (Throwable $e) {
            report($e);
            Log::warning('CreationTelegramNotifier notifyCostSettled failed', [
                'type' => $creationType,
                'creation_id' => $creation->getKey(),
                'error' => $e->getMessage(),
            ]);
        }
    }

    public static function typeFromModel(Model $creation): ?string
    {
        return match (true) {
            $creation instanceof UserImageCreation => 'image',
            $creation instanceof UserVideoCreation => 'video',
            $creation instanceof UserMusicCreation => 'music',
            $creation instanceof UserVoiceCreation => 'voice',
            default => null,
        };
    }

    private function e(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
    }

    private function truncate(string $value, int $max): string
    {
        if (mb_strlen($value) <= $max) {
            return $value;
        }

        return mb_substr($value, 0, $max).'…';
    }

    private function fmtUsd(mixed $value): string
    {
        if ($value === null || $value === '') {
            return '—';
        }
        if (! is_numeric($value)) {
            return $this->e((string) $value);
        }

        return '$'.number_format((float) $value, 6, '.', '');
    }

    private function fmtNum(mixed $value): string
    {
        if ($value === null || $value === '') {
            return '—';
        }
        if (! is_numeric($value)) {
            return $this->e((string) $value);
        }

        return number_format((float) $value, 4, '.', '');
    }
}
