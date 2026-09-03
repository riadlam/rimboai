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

    public function notifyNewRegistration(User $user, string $via = 'email'): void
    {
        if (! $this->telegram->isConfigured()) {
            return;
        }

        try {
            $user->refresh();
            $viaLabel = match (strtolower($via)) {
                'google' => 'Google',
                'email' => 'Email / password',
                default => $via,
            };

            $lines = [
                '<b>🆕 New user registered</b>',
                'Via: '.$this->e($viaLabel),
                'ID: #'.(int) $user->getKey(),
                'Name: '.$this->e((string) ($user->name ?? '—')),
                'Email: '.$this->e((string) ($user->email ?? '—')),
                'Tokens: '.number_format((int) ($user->tokens ?? 0)),
                'Google ID: '.$this->e((string) ($user->google_id ?: '—')),
            ];

            $avatar = is_string($user->avatar) ? trim($user->avatar) : '';
            if ($avatar !== '') {
                $lines[] = 'Avatar: '.$this->e($this->truncate($avatar, 200));
            }

            $created = $user->created_at?->timezone(config('app.timezone'))->format('Y-m-d H:i:s');
            if ($created) {
                $lines[] = 'Created: '.$this->e($created);
            }

            $ip = request()?->ip();
            if (is_string($ip) && $ip !== '') {
                $lines[] = 'IP: '.$this->e($ip);
            }

            $this->telegram->send(implode("\n", $lines));
        } catch (Throwable $e) {
            report($e);
            Log::warning('CreationTelegramNotifier notifyNewRegistration failed', [
                'user_id' => $user->getKey(),
                'error' => $e->getMessage(),
            ]);
        }
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

        if ($creation->getAttribute('cost_settled_notified_at') !== null) {
            return;
        }

        try {
            $creation->refresh();
            if ($creation->getAttribute('cost_settled_notified_at') !== null) {
                return;
            }

            $user = User::query()->find($creation->getAttribute('user_id'));
            $mode = (string) ($creation->getAttribute('mode') ?? '');
            $pnl = app(CreationProfitCalculator::class)->compute($creation, $creationType);

            $title = $pnl['negative_nominal'] || $pnl['variance_alert']
                ? '<b>⚠️ Creation cost settled</b>'
                : '<b>Creation cost settled</b>';

            $lines = [
                $title,
                'Surface: '.$this->e($pnl['surface']),
                'Type: '.$this->e($creationType),
                'ID: '.(int) $creation->getKey(),
                'Status: '.$this->e((string) ($creation->getAttribute('status') ?? '')),
                'User: '.$this->e((string) ($user?->email ?? '—')).' (#'.(int) ($creation->getAttribute('user_id') ?? 0).')',
                'Balance: '.number_format((int) ($user?->tokens ?? 0)).' tokens',
                'Tokens charged: '.$this->fmtNum($pnl['tokens_charged']).($pnl['refunded'] ? ' (refunded)' : ''),
                'Net tokens: '.$this->fmtNum($pnl['net_tokens']),
                'Est. fal USD: '.$this->fmtUsd($pnl['estimated_fal_usd']),
                'Actual fal USD: '.$this->fmtUsd($pnl['actual_fal_usd']),
            ];

            if ($pnl['estimate_variance_percent'] !== null) {
                $lines[] = 'Estimate Δ: '.$this->fmtUsd($pnl['estimate_delta_usd'])
                    .' ('.$pnl['estimate_variance_percent'].'%)';
            }

            $lines[] = 'Nominal revenue: '.$this->fmtUsd($pnl['nominal_revenue_usd']);
            $lines[] = 'Nominal profit: '.$this->fmtUsd($pnl['nominal_profit_usd'])
                .($pnl['nominal_margin_percent'] !== null ? ' ('.$pnl['nominal_margin_percent'].'%)' : '');

            if ($pnl['cash_available']) {
                $lines[] = 'Cash revenue: '.$this->fmtUsd($pnl['cash_revenue_usd'])
                    .' / '.number_format((float) ($pnl['cash_revenue_dzd'] ?? 0), 2).' DZD';
                $lines[] = 'Cash profit: '.$this->fmtUsd($pnl['cash_profit_usd'])
                    .($pnl['cash_margin_percent'] !== null ? ' ('.$pnl['cash_margin_percent'].'%)' : '');
            } else {
                $lines[] = 'Cash profit: unavailable ('.$this->e((string) ($pnl['cash_note'] ?? 'legacy/free tokens')).')';
            }

            $lines[] = 'Wallet before: '.$this->fmtUsd($creation->getAttribute('fal_wallet_balance_before'));
            $lines[] = 'Wallet after: '.$this->fmtUsd($creation->getAttribute('fal_wallet_balance_after'));
            $lines[] = 'Deducted: '.$this->fmtUsd($creation->getAttribute('deducted_amount_from_main_wallet'));
            $lines[] = 'Mode: '.$this->e($mode !== '' ? $mode : '—');
            $lines[] = 'Model: '.$this->e((string) ($creation->getAttribute('model_name') ?? '—'));

            $this->telegram->send(implode("\n", $lines));

            if ($creation->isFillable('cost_settled_notified_at') || array_key_exists('cost_settled_notified_at', $creation->getAttributes())) {
                $creation->forceFill(['cost_settled_notified_at' => now()])->save();
            }
        } catch (Throwable $e) {
            report($e);
            Log::warning('CreationTelegramNotifier notifyCostSettled failed', [
                'type' => $creationType,
                'creation_id' => $creation->getKey(),
                'error' => $e->getMessage(),
            ]);
        }
    }

    public function notifyRefunded(User $user, string $creationType, Model $creation, string $reason): void
    {
        if (! $this->telegram->isConfigured()) {
            return;
        }

        try {
            $credits = $creation->getAttribute('credits_charged');
            $this->telegram->send(implode("\n", [
                '<b>↩️ Tokens refunded</b>',
                'Type: '.$this->e($creationType),
                'ID: '.(int) $creation->getKey(),
                'User: '.$this->e((string) ($user->email ?? '—')),
                'Tokens: '.$this->fmtNum($credits),
                'Reason: '.$this->e($reason),
                'Balance: '.number_format((int) $user->tokens),
            ]));
        } catch (Throwable $e) {
            report($e);
        }
    }

    public function notifyFailedCharged(string $creationType, Model $creation): void
    {
        if (! $this->telegram->isConfigured()) {
            return;
        }

        try {
            $user = User::query()->find($creation->getAttribute('user_id'));
            $this->telegram->send(implode("\n", [
                '<b>⚠️ Failed creation — fal billed, tokens kept</b>',
                'Type: '.$this->e($creationType),
                'ID: '.(int) $creation->getKey(),
                'User: '.$this->e((string) ($user?->email ?? '—')),
                'Tokens charged: '.$this->fmtNum($creation->getAttribute('credits_charged')),
                'cost_usd: '.$this->fmtUsd($creation->getAttribute('cost_usd')),
                'Endpoint: '.$this->e((string) ($creation->getAttribute('endpoint_id') ?? '—')),
            ]));
        } catch (Throwable $e) {
            report($e);
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
