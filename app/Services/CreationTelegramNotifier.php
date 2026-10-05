<?php

namespace App\Services;

use App\Models\User;
use App\Models\UserImageCreation;
use App\Models\UserMusicCreation;
use App\Models\UserVideoCreation;
use App\Models\UserVoiceCreation;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Throwable;

/**
 * Telegram alerts for Lab / Tools / Trends creations.
 * One Telegram message per creation (edit via telegram_message_id).
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

            $viaEmoji = match (strtolower($via)) {
                'google' => '🔵',
                'email' => '✉️',
                default => '🚪',
            };

            $lines = [
                '🎉 <b>New RIMBOAI human just landed</b>',
                '<i>welcome to the lab · starter tokens unlocked</i>',
                $this->hr(),
                $viaEmoji.' Via · <b>'.$this->e($viaLabel).'</b>',
                '🪪 ID · <code>#'.(int) $user->getKey().'</code>',
                '👤 Name · '.$this->e((string) ($user->name ?? '—')),
                '📧 Email · '.$this->e((string) ($user->email ?? '—')),
                '🪙 Tokens · <b>'.number_format((int) ($user->tokens ?? 0)).'</b>',
                '🔗 Google ID · '.$this->e((string) ($user->google_id ?: '—')),
            ];

            $avatar = is_string($user->avatar) ? trim($user->avatar) : '';
            if ($avatar !== '') {
                $lines[] = '🖼️ Avatar · '.$this->e($this->truncate($avatar, 200));
            }

            $created = $user->created_at?->timezone(config('app.timezone'))->format('Y-m-d H:i:s');
            if ($created) {
                $lines[] = '📅 Created · '.$this->e($created);
            }

            $ip = request()?->ip();
            if (is_string($ip) && $ip !== '') {
                $lines[] = '🌐 IP · <code>'.$this->e($ip).'</code>';
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
            $markup = (float) config('credits.markup', 1.25);
            $usdPer = (float) config('credits.usd_per_credit', 0.01);
            $chargedRevenue = is_numeric($credits) ? round((float) $credits * $usdPer, 6) : null;

            $typeEmoji = $this->typeEmoji($creationType);
            $status = (string) ($creation->getAttribute('status') ?? '');

            $lines = [
                $typeEmoji.' <b>New '.$this->e($creationType).' is cooking</b>',
                '<i>'.$this->e($this->surfaceBlurb($mode, $toolSlug)).' · pending fal cost</i>',
                $this->hr(),
                '📦 Type · <b>'.$this->e($creationType).'</b>',
                '🪪 ID · <code>#'.(int) $creation->getKey().'</code>',
                $this->statusLine($status),
                '👤 User · '.$this->e((string) ($user->email ?? '')).' <code>#'.(int) $user->getKey().'</code>',
                '🪙 Balance · <b>'.number_format((int) $user->tokens).'</b> tokens',
                '📐 Markup · ×'.$this->fmtMarkup($markup),
                '⚡️ Charged · <b>'.$this->fmtNum($credits).'</b> → '.$this->fmtUsd($chargedRevenue),
                '🔮 Est. fal · '.$this->fmtUsd($estimatedUsd),
                '💸 Actual fal · <i>waiting…</i>',
                '🎛️ Mode · '.$this->e($mode !== '' ? $mode : '—'),
            ];

            if ($toolSlug) {
                $lines[] = '🛠️ Tool · <b>'.$this->e($toolSlug).'</b>';
            }

            $lines[] = '🧠 Model · '.$this->e((string) ($creation->getAttribute('model_name') ?? '—'));
            $lines[] = '🔌 Endpoint · <code>'.$this->e((string) ($creation->getAttribute('endpoint_id') ?? '—')).'</code>';

            $aspect = $creation->getAttribute('aspect_ratio') ?? ($settings['aspect'] ?? null);
            $resolution = $creation->getAttribute('resolution') ?? ($settings['resolution'] ?? null);
            $duration = $creation->getAttribute('duration_seconds')
                ?? $creation->getAttribute('duration_value')
                ?? ($settings['duration'] ?? null);

            if ($aspect !== null && $aspect !== '') {
                $lines[] = '📐 Aspect · '.$this->e((string) $aspect);
            }
            if ($resolution !== null && $resolution !== '') {
                $lines[] = '🖥️ Resolution · '.$this->e((string) $resolution);
            }
            if ($duration !== null && $duration !== '') {
                $lines[] = '⏱️ Duration · '.$this->e((string) $duration);
            }

            $prompt = (string) ($creation->getAttribute('prompt') ?? '');
            if ($prompt !== '') {
                $lines[] = $this->hr();
                $lines[] = '📝 <b>Prompt</b>';
                $lines[] = '<i>'.$this->e($this->truncate($prompt, 500)).'</i>';
            }

            $this->upsertMessage($creation, implode("\n", $lines));
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
            $markup = (float) config('credits.markup', 1.25);

            $title = $pnl['negative_nominal'] || $pnl['variance_alert']
                ? '😬 <b>Cost settled — peek at this one</b>'
                : '💰 <b>Cost settled — looking tasty</b>';

            $profitEmoji = ($pnl['nominal_profit_usd'] !== null && $pnl['nominal_profit_usd'] < 0) ? '📉' : '📈';
            $cashEmoji = ($pnl['cash_profit_usd'] !== null && $pnl['cash_profit_usd'] < 0) ? '🔻' : '💚';

            $lines = [
                $title,
                '<i>'.$this->e($pnl['surface']).' · '.$this->e($creationType).' · #'.(int) $creation->getKey().'</i>',
                $this->hr(),
                '🪪 ID · <code>#'.(int) $creation->getKey().'</code>',
                $this->statusLine((string) ($creation->getAttribute('status') ?? '')),
                '👤 User · '.$this->e((string) ($user?->email ?? '—')).' <code>#'.(int) ($creation->getAttribute('user_id') ?? 0).'</code>',
                '🪙 Balance · <b>'.number_format((int) ($user?->tokens ?? 0)).'</b> tokens',
                '📐 Markup · ×'.$this->fmtMarkup($markup),
                '⚡️ Charged · '.$this->fmtNum($pnl['tokens_charged']).($pnl['refunded'] ? ' <i>(refunded)</i>' : '')
                    .' → '.$this->fmtUsd($pnl['nominal_revenue_usd']),
                '🧮 Net tokens · '.$this->fmtNum($pnl['net_tokens']),
                $this->hr(),
                '🔮 Est. fal · '.$this->fmtUsd($pnl['estimated_fal_usd']),
                '💸 Actual fal · '.$this->fmtUsd($pnl['actual_fal_usd']),
            ];

            if ($pnl['estimate_variance_percent'] !== null) {
                $lines[] = '📊 Estimate Δ · '.$this->fmtUsd($pnl['estimate_delta_usd'])
                    .' <i>('.$pnl['estimate_variance_percent'].'%)</i>';
            }

            $lines[] = $profitEmoji.' Nominal · '.$this->fmtUsd($pnl['nominal_revenue_usd'])
                .' in → '.$this->fmtUsd($pnl['nominal_profit_usd'])
                .' profit'
                .($pnl['nominal_margin_percent'] !== null ? ' <b>('.$pnl['nominal_margin_percent'].'%)</b>' : '');

            if ($pnl['cash_available']) {
                $lines[] = $cashEmoji.' Cash · '.$this->fmtUsd($pnl['cash_revenue_usd'])
                    .' / '.number_format((float) ($pnl['cash_revenue_dzd'] ?? 0), 2).' DZD';
                $lines[] = $cashEmoji.' Cash profit · '.$this->fmtUsd($pnl['cash_profit_usd'])
                    .($pnl['cash_margin_percent'] !== null ? ' <b>('.$pnl['cash_margin_percent'].'%)</b>' : '');
            } else {
                $lines[] = '💤 Cash profit · unavailable <i>('.$this->e((string) ($pnl['cash_note'] ?? 'legacy/free tokens')).')</i>';
            }

            $lines[] = $this->hr();
            $lines[] = '🏦 Wallet before · '.$this->fmtUsd($creation->getAttribute('fal_wallet_balance_before'));
            $lines[] = '🏦 Wallet after · '.$this->fmtUsd($creation->getAttribute('fal_wallet_balance_after'));
            $lines[] = '✂️ Deducted · '.$this->fmtUsd($creation->getAttribute('deducted_amount_from_main_wallet'));
            $lines[] = '🎛️ Mode · '.$this->e($mode !== '' ? $mode : '—');
            $lines[] = '🧠 Model · '.$this->e((string) ($creation->getAttribute('model_name') ?? '—'));
            $lines[] = '🔌 Endpoint · <code>'.$this->e((string) ($creation->getAttribute('endpoint_id') ?? '—')).'</code>';

            $this->upsertMessage($creation, implode("\n", $lines));

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
            $creation->refresh();
            $credits = $creation->getAttribute('credits_charged');
            $this->upsertMessage($creation, implode("\n", [
                '↩️ <b>Tokens bounced back</b>',
                '<i>refunded · nothing to worry about… probably</i>',
                $this->hr(),
                '📦 Type · <b>'.$this->e($creationType).'</b>',
                '🪪 ID · <code>#'.(int) $creation->getKey().'</code>',
                $this->statusLine((string) ($creation->getAttribute('status') ?? '')),
                '👤 User · '.$this->e((string) ($user->email ?? '—')),
                '🪙 Tokens · <b>'.$this->fmtNum($credits).'</b>',
                '💬 Reason · '.$this->e($reason),
                '💼 Balance · <b>'.number_format((int) $user->tokens).'</b>',
                '💸 cost_usd · '.$this->fmtUsd($creation->getAttribute('cost_usd')),
            ]));
        } catch (Throwable $e) {
            report($e);
        }
    }

    /**
     * Alert when a customer creation fails (Lab / Tools / Trends).
     * Idempotent per creation so webhook + sync retries do not spam.
     */
    public function notifyFailed(string $creationType, Model $creation): void
    {
        if (! $this->telegram->isConfigured()) {
            return;
        }

        $cacheKey = 'telegram:creation_failed:'.$creationType.':'.(int) $creation->getKey();
        if (! Cache::add($cacheKey, 1, now()->addDays(7))) {
            return;
        }

        try {
            $creation->refresh();
            $user = User::query()->find($creation->getAttribute('user_id'));
            $settings = is_array($creation->getAttribute('settings')) ? $creation->getAttribute('settings') : [];
            $mode = (string) ($creation->getAttribute('mode') ?? '');
            $toolSlug = str_starts_with($mode, 'tool:') ? substr($mode, 5) : null;
            $errorMessage = trim((string) ($creation->getAttribute('error_message') ?? ''));
            $errorType = trim((string) ($creation->getAttribute('error_type') ?? ''));
            $progressMessage = trim((string) ($creation->getAttribute('progress_message') ?? ''));
            $requestId = trim((string) ($creation->getAttribute('fal_request_id') ?? ''));
            $markup = (float) config('credits.markup', 1.25);

            $lines = [
                '🚨 <b>Customer creation failed</b>',
                '<i>'.$this->e($this->surfaceBlurb($mode, $toolSlug)).' · needs a look</i>',
                $this->hr(),
                $this->typeEmoji($creationType).' Type · <b>'.$this->e($creationType).'</b>',
                '🪪 Creation · <code>#'.(int) $creation->getKey().'</code>',
                $this->statusLine((string) ($creation->getAttribute('status') ?? 'failed')),
                '👤 User · '.$this->e((string) ($user?->email ?? '—')).' <code>#'.(int) ($creation->getAttribute('user_id') ?? 0).'</code>',
                '🪙 Balance · <b>'.number_format((int) ($user?->tokens ?? 0)).'</b> tokens',
                '📐 Markup · ×'.$this->fmtMarkup($markup),
                '⚡️ Charged · <b>'.$this->fmtNum($creation->getAttribute('credits_charged')).'</b>',
                '🔮 Est. fal · '.$this->fmtUsd($settings['fal_cost_usd'] ?? null),
                '💸 Actual fal · '.$this->fmtUsd($creation->getAttribute('cost_usd')),
            ];

            if ($toolSlug) {
                $lines[] = '🛠️ Tool · <b>'.$this->e($toolSlug).'</b>';
            }

            $lines[] = '🎛️ Mode · '.$this->e($mode !== '' ? $mode : '—');
            $lines[] = '🧠 Model · '.$this->e((string) ($creation->getAttribute('model_name') ?? '—'));
            $lines[] = '🔌 Endpoint · <code>'.$this->e((string) ($creation->getAttribute('endpoint_id') ?? '—')).'</code>';

            if ($requestId !== '') {
                $lines[] = '🆔 fal request · <code>'.$this->e($requestId).'</code>';
            }

            $aspect = $creation->getAttribute('aspect_ratio') ?? ($settings['aspect'] ?? null);
            $resolution = $creation->getAttribute('resolution') ?? ($settings['resolution'] ?? null);
            $duration = $creation->getAttribute('duration_seconds')
                ?? $creation->getAttribute('duration_value')
                ?? ($settings['duration'] ?? null);

            if ($aspect !== null && $aspect !== '') {
                $lines[] = '📐 Aspect · '.$this->e((string) $aspect);
            }
            if ($resolution !== null && $resolution !== '') {
                $lines[] = '🖥️ Resolution · '.$this->e((string) $resolution);
            }
            if ($duration !== null && $duration !== '') {
                $lines[] = '⏱️ Duration · '.$this->e((string) $duration);
            }

            $lines[] = $this->hr();
            $lines[] = '🧨 Error type · <code>'.$this->e($errorType !== '' ? $errorType : '—').'</code>';
            $lines[] = '💬 <b>Error message (full)</b>';
            $lines[] = $errorMessage !== ''
                ? '<pre>'.$this->e($this->truncate($errorMessage, 3500)).'</pre>'
                : '<i>(empty)</i>';

            if ($progressMessage !== '' && strcasecmp($progressMessage, 'Failed') !== 0) {
                $lines[] = '📟 Progress · '.$this->e($this->truncate($progressMessage, 500));
            }

            $prompt = (string) ($creation->getAttribute('prompt') ?? '');
            if ($prompt !== '') {
                $lines[] = $this->hr();
                $lines[] = '📝 <b>Prompt</b>';
                $lines[] = '<i>'.$this->e($this->truncate($prompt, 800)).'</i>';
            }

            $failedAt = $creation->getAttribute('completed_at');
            if ($failedAt) {
                $lines[] = '📅 Failed at · '.$this->e(
                    $failedAt instanceof \Carbon\CarbonInterface
                        ? $failedAt->timezone(config('app.timezone'))->format('Y-m-d H:i:s')
                        : (string) $failedAt
                );
            }

            $this->upsertMessage($creation, implode("\n", $lines));
        } catch (Throwable $e) {
            report($e);
            Cache::forget($cacheKey);
            Log::warning('CreationTelegramNotifier notifyFailed failed', [
                'type' => $creationType,
                'creation_id' => $creation->getKey(),
                'error' => $e->getMessage(),
            ]);
        }
    }

    public function notifyFailedCharged(string $creationType, Model $creation): void
    {
        if (! $this->telegram->isConfigured()) {
            return;
        }

        try {
            $creation->refresh();
            $user = User::query()->find($creation->getAttribute('user_id'));
            $errorMessage = trim((string) ($creation->getAttribute('error_message') ?? ''));
            $markup = (float) config('credits.markup', 1.25);
            $this->upsertMessage($creation, implode("\n", [
                '🚨 <b>Failed job — fal still took a bite</b>',
                '<i>tokens kept · we paid fal anyway</i>',
                $this->hr(),
                '📦 Type · <b>'.$this->e($creationType).'</b>',
                '🪪 ID · <code>#'.(int) $creation->getKey().'</code>',
                $this->statusLine((string) ($creation->getAttribute('status') ?? 'failed')),
                '👤 User · '.$this->e((string) ($user?->email ?? '—')),
                '📐 Markup · ×'.$this->fmtMarkup($markup),
                '⚡️ Charged · '.$this->fmtNum($creation->getAttribute('credits_charged')),
                '💸 Actual fal · '.$this->fmtUsd($creation->getAttribute('cost_usd')),
                '🔌 Endpoint · <code>'.$this->e((string) ($creation->getAttribute('endpoint_id') ?? '—')).'</code>',
                '💬 Error · '.$this->e($this->truncate($errorMessage !== '' ? $errorMessage : '—', 800)),
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

    /**
     * Edit the existing Telegram message for this creation, or send + store message_id.
     */
    private function upsertMessage(Model $creation, string $text): void
    {
        $existingId = (int) ($creation->getAttribute('telegram_message_id') ?? 0);

        if ($existingId > 0 && $this->telegram->edit($existingId, $text)) {
            return;
        }

        $newId = $this->telegram->sendReturningId($text);
        if ($newId === null) {
            return;
        }

        if (! Schema::hasColumn($creation->getTable(), 'telegram_message_id')) {
            return;
        }

        $creation->forceFill(['telegram_message_id' => $newId])->save();
    }

    private function typeEmoji(string $type): string
    {
        return match ($type) {
            'image' => '🖼️',
            'video' => '🎬',
            'music' => '🎵',
            'voice' => '🎙️',
            default => '✨',
        };
    }

    private function surfaceBlurb(?string $mode, ?string $toolSlug): string
    {
        if (is_string($toolSlug) && $toolSlug !== '') {
            return 'tools · '.$toolSlug;
        }
        if (is_string($mode) && str_contains($mode, 'trend')) {
            return 'trends remix';
        }

        return 'lab generation';
    }

    private function statusLine(string $status): string
    {
        $emoji = match (strtolower($status)) {
            'completed', 'succeeded', 'success', 'paid' => '🟢',
            'failed', 'error' => '🔴',
            'canceled', 'cancelled' => '🟠',
            'processing', 'running', 'pending', 'in_progress' => '🟡',
            default => '⚪️',
        };

        return $emoji.' Status · <b>'.$this->e($status !== '' ? $status : '—').'</b>';
    }

    private function hr(): string
    {
        return '<i>────────────</i>';
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

        return '<code>$'.number_format((float) $value, 6, '.', '').'</code>';
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

    private function fmtMarkup(float $markup): string
    {
        $formatted = rtrim(rtrim(number_format($markup, 4, '.', ''), '0'), '.');

        return $formatted !== '' ? $formatted : '1';
    }
}
