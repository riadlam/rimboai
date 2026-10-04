<?php

namespace App\Console\Commands;

use App\Models\UserVideoCreation;
use App\Services\HiggsfieldWebhookProcessor;
use Illuminate\Console\Command;

class SyncHiggsfieldCreationsCommand extends Command
{
    protected $signature = 'higgsfield:sync-pending {--limit=20}';

    protected $description = 'Poll active Higgsfield video creations (webhook safety net)';

    public function handle(HiggsfieldWebhookProcessor $processor): int
    {
        $limit = max(1, min(50, (int) $this->option('limit')));

        $rows = UserVideoCreation::query()
            ->where(function ($q): void {
                $q->where('provider', 'higgsfield')
                    ->orWhere('endpoint_id', 'like', 'higgsfield/%')
                    ->orWhere('settings->provider', 'higgsfield');
            })
            ->whereIn('status', [
                UserVideoCreation::STATUS_PENDING,
                UserVideoCreation::STATUS_QUEUED,
                UserVideoCreation::STATUS_IN_PROGRESS,
            ])
            ->where(function ($q): void {
                $q->whereNotNull('fal_request_id')
                    ->orWhereNotNull('fal_status_url');
            })
            ->orderBy('id')
            ->limit($limit)
            ->get();

        $n = 0;
        foreach ($rows as $creation) {
            $processor->sync($creation);
            $n++;
        }

        $this->info("Synced {$n} Higgsfield creation(s).");

        return self::SUCCESS;
    }
}
