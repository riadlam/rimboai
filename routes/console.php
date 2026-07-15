<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Keep model pricing and active/inactive status in sync with fal.
// Runs in background without overlapping so a slow run never stacks.
Schedule::command('fal:sync-pricing')
    ->everyMinute()
    ->withoutOverlapping(10)
    ->runInBackground();
