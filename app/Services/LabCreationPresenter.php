<?php

namespace App\Services;

use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;

/**
 * Shared Lab creation payload helpers (progress % mirrors resources/js/lib/labProgress.ts).
 */
final class LabCreationPresenter
{
    public static function queueProgressMessage(mixed $queuePosition): string
    {
        if (! is_numeric($queuePosition)) {
            return 'In queue';
        }

        $pos = (int) $queuePosition;

        if ($pos > 0) {
            return "Queue #{$pos}";
        }

        if ($pos === 0) {
            return 'Next up';
        }

        return 'In queue';
    }

    public static function progressPercent(Model $creation): int
    {
        $status = (string) $creation->getAttribute('status');

        if ($status === 'completed') {
            return 100;
        }

        if (in_array($status, ['failed', 'cancelled'], true)) {
            return 0;
        }

        if ($status === 'queued') {
            $pos = $creation->getAttribute('queue_position');
            if (is_numeric($pos)) {
                $n = (int) $pos;
                if ($n >= 0) {
                    return max(8, min(42, 40 - min($n, 16) * 2));
                }
            }

            return 20;
        }

        if ($status === 'in_progress') {
            $started = $creation->getAttribute('started_at')
                ?? $creation->getAttribute('queued_at')
                ?? $creation->getAttribute('created_at');
            $elapsed = self::secondsSince($started);
            $t = min($elapsed / 240, 1);
            $eased = 1 - pow(1 - $t, 1.55);

            return (int) round(45 + $eased * 43);
        }

        $started = $creation->getAttribute('created_at');
        $elapsed = self::secondsSince($started);

        return min(12, 4 + (int) round($elapsed * 2));
    }

    private static function secondsSince(mixed $at): int
    {
        if ($at instanceof CarbonInterface) {
            return max(0, (int) $at->diffInSeconds(now(), false));
        }

        return 0;
    }
}
