<?php

namespace App\Console\Commands;

use App\Services\FalPricingSyncService;
use App\Services\TelegramNotifier;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Refresh unit/unit_price for EVERY endpoint in EVERY model table from fal.
 *
 * Designed to run from cron. Never called on the user request path — generation
 * reads pricing from the DB only (see FalPricingService).
 */
class FalSyncPricing extends Command
{
    protected $signature = 'fal:sync-pricing
        {--table= : Limit to a single model table}
        {--sleep=2 : Seconds to wait between fal batch requests (50 models per batch)}
        {--skip-status : Only sync pricing, do not touch status}
        {--dry-run : Show what would change without writing}';

    protected $description = 'Sync unit, unit_price and active/inactive status for all models across all tables from fal';

    public function handle(FalPricingSyncService $sync): int
    {
        $key = config('services.fal.key');

        if (! $key) {
            $this->error('FAL_KEY is not set in .env');
            Log::error('fal:sync-pricing aborted — FAL_KEY is not set');

            return self::FAILURE;
        }

        $only = $this->option('table');
        $tables = $only ? [(string) $only] : FalPricingSyncService::ALL_TABLES;

        $this->info('fal:sync-pricing started');

        try {
            $summary = $sync->run(
                $tables,
                (bool) $this->option('dry-run'),
                (bool) $this->option('skip-status'),
                max(0, (int) $this->option('sleep')),
            );
        } catch (Throwable $e) {
            $this->error($e->getMessage());
            Log::error('fal:sync-pricing failed', ['error' => $e->getMessage()]);

            return self::FAILURE;
        }

        $this->newLine();
        $this->info(sprintf(
            'Done. priced=%d price_failed=%d deactivated=%d reactivated=%d quarantined=%d coverage=%.2f active=%d%s',
            $summary['priced'],
            $summary['price_failed'],
            $summary['deactivated'],
            $summary['reactivated'],
            $summary['quarantined'],
            $summary['coverage'],
            $summary['total_active'],
            $summary['dry_run'] ? ' (dry-run: nothing written)' : '',
        ));

        if ($summary['failed']) {
            $this->error((string) ($summary['error'] ?? 'Pricing sync failed.'));
        }

        $this->reportToTelegram($summary);

        return $summary['failed'] ? self::FAILURE : self::SUCCESS;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function reportToTelegram(array $data): void
    {
        $notifier = TelegramNotifier::forPricing();

        if (! $notifier->isConfigured()) {
            $this->warn('Telegram pricing bot not configured (TELEGRAM_BOT_TOKEN / TELEGRAM_CHAT_ID) — skipping report.');

            return;
        }

        $events = $data['events'];
        $deactivations = array_values(array_filter(
            $events,
            fn ($e) => $e['field'] === 'status' && $e['new'] !== 'active'
        ));
        $reactivations = array_values(array_filter(
            $events,
            fn ($e) => $e['field'] === 'status' && $e['new'] === 'active'
        ));
        $priceChanges = array_values(array_filter(
            $events,
            fn ($e) => $e['field'] === 'unit' || $e['field'] === 'unit_price'
        ));

        $lines = [];
        $hasChanges = $deactivations !== [] || $reactivations !== [] || $priceChanges !== [];

        if ($data['failed']) {
            $lines[] = '💥 <b>fal Pricing Sync — yeeted</b>';
            $lines[] = '<i>something exploded · prices may be stale</i>';
        } elseif ($hasChanges) {
            $lines[] = '✨ <b>fal Pricing Sync — fresh drops</b>';
            $lines[] = '<i>catalogue got a little glow-up</i>';
        } else {
            $lines[] = '😴 <b>fal Pricing Sync — all quiet</b>';
            $lines[] = '<i>no drama · models still napping in place</i>';
        }

        if ($data['dry_run']) {
            $lines[] = '🧪 <i>dry-run: nothing written</i>';
        }
        if (! empty($data['error'])) {
            $lines[] = '🗯️ <i>'.$this->esc((string) $data['error']).'</i>';
        }
        $lines[] = '<i>────────────</i>';
        $lines[] = sprintf('🟢 Active · <b>%d</b>', $data['total_active']);
        $lines[] = sprintf('⚫️ Inactive · <b>%d</b>', $data['total_inactive']);
        $lines[] = sprintf('💲 Priced OK · <b>%d</b>    ⚠️ Failed · <b>%d</b>', $data['priced'], $data['price_failed']);
        $lines[] = sprintf('📦 Coverage · <b>%.0f%%</b>    🚫 Quarantined · <b>%d</b>', $data['coverage'] * 100, $data['quarantined']);
        $lines[] = sprintf('♻️ Reactivated · <b>%d</b>    🔻 Deactivated · <b>%d</b>', $data['reactivated'], $data['deactivated']);
        $lines[] = sprintf('⏱️ Duration · <b>%ss</b>', $data['duration']);

        if ($deactivations !== []) {
            $lines[] = '';
            $lines[] = '🔻 <b>Just sent to the bench</b>';
            foreach ($deactivations as $e) {
                $lines[] = '· '.$this->esc($e['name']).' <i>('.$this->shortTable($e['table']).')</i>';
            }
        }

        if ($reactivations !== []) {
            $lines[] = '';
            $lines[] = '♻️ <b>Back in the game</b>';
            foreach ($reactivations as $e) {
                $lines[] = '· '.$this->esc($e['name']).' <i>('.$this->shortTable($e['table']).')</i>';
            }
        }

        if ($priceChanges !== []) {
            $lines[] = '';
            $lines[] = '💸 <b>Price tags moved</b>';
            foreach ($priceChanges as $e) {
                $old = $e['old'] === null || $e['old'] === '' ? '—' : $e['old'];
                $badge = $e['field'] === 'unit_price' ? '💰' : '📏';
                $lines[] = $badge.' '.$this->esc($e['name']).' <i>('.$this->shortTable($e['table']).')</i>: <code>'.$this->esc((string) $old).'</code> → <code>'.$this->esc((string) $e['new']).'</code>';
            }
        }

        if ($events === [] && empty($data['error'])) {
            $lines[] = '';
            $lines[] = '🧸 No changes this run. Go touch grass.';
        }

        $notifier->send(implode("\n", $lines));
        $this->info('Telegram report sent.');
    }

    private function shortTable(string $table): string
    {
        return str_replace(['text_to_', 'image_to_', '_models'], ['', 'img→', ''], $table);
    }

    private function esc(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
    }
}
