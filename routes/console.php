<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Keep model pricing and active/inactive status in sync with fal.
// Runs in background without overlapping so a slow run never stacks.
// Sends a run report to Telegram on completion (see FalSyncPricing).
Schedule::command('fal:sync-pricing')
    ->everyTenMinutes()
    ->withoutOverlapping(10)
    ->runInBackground();

// Drain delayed jobs (wallet cost reconcile, etc.) on shared hosting without a queue daemon.
Schedule::command('queue:work database --stop-when-empty --max-time=50 --tries=1 --sleep=1')
    ->everyMinute()
    ->withoutOverlapping(1)
    ->runInBackground();

// Safety net if queue drain missed a job: fill cost_usd / wallet-after on recent creations.
Schedule::command('fal:reconcile-wallet-costs --hours=48 --limit=40')
    ->everyFiveMinutes()
    ->withoutOverlapping(5)
    ->runInBackground();

// Safety net: credit tokens for SofizPay payments where the user paid but never
// returned to the return URL. Idempotent, so it can never double-credit.
Schedule::command('payments:reconcile-sofizpay')
    ->everyFiveMinutes()
    ->withoutOverlapping(10)
    ->runInBackground();
