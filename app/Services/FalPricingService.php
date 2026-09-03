<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

/**
 * Resolve fal unit pricing for the exact endpoint we will submit to.
 *
 * Pricing is DB-only (cron syncs unit/unit_price/status). Never call fal live
 * during a user generation — that risks rate limits and undercharging.
 */
class FalPricingService
{
    /**
     * @return array{endpoint_id: string, unit: string|null, unit_price: float, source: string}|null
     */
    public function resolve(string $endpointId): ?array
    {
        $endpointId = trim($endpointId);
        if ($endpointId === '') {
            return null;
        }

        foreach ([
            'text_to_image_models',
            'image_to_video_models',
            'text_to_video_models',
            'text_to_voice_models',
            'text_to_music_models',
            'video_tools_models',
        ] as $table) {
            if (! Schema::hasTable($table)) {
                continue;
            }

            $select = ['endpoint_id', 'unit', 'unit_price'];
            if (Schema::hasColumn($table, 'pricing_fetched_at')) {
                $select[] = 'pricing_fetched_at';
            }
            if (Schema::hasColumn($table, 'pricing_checked_at')) {
                $select[] = 'pricing_checked_at';
            }
            if (Schema::hasColumn($table, 'updated_at')) {
                $select[] = 'updated_at';
            }

            $row = DB::table($table)
                ->where('endpoint_id', $endpointId)
                ->where('status', 'active')
                ->first($select);

            if (! $row) {
                continue;
            }

            if ($row->unit_price === null || (float) $row->unit_price <= 0) {
                Log::warning('Active submit endpoint has invalid catalog pricing.', [
                    'endpoint_id' => $endpointId,
                    'table' => $table,
                    'unit_price' => $row->unit_price,
                ]);

                return null;
            }

            if ($this->isStale($row)) {
                Log::warning('Active submit endpoint has stale catalog pricing.', [
                    'endpoint_id' => $endpointId,
                    'table' => $table,
                    'pricing_fetched_at' => $row->pricing_fetched_at ?? null,
                ]);

                return null;
            }

            return [
                'endpoint_id' => $endpointId,
                'unit' => isset($row->unit) ? (string) $row->unit : null,
                'unit_price' => (float) $row->unit_price,
                'source' => 'catalog:'.$table,
            ];
        }

        Log::warning('Submit endpoint missing or inactive in catalog.', [
            'endpoint_id' => $endpointId,
        ]);

        return null;
    }

    private function isStale(object $row): bool
    {
        $maxAge = max(0, (int) config('credits.pricing_max_age_minutes', 1440));
        if ($maxAge <= 0) {
            return false;
        }

        $fetched = $row->pricing_fetched_at ?? $row->pricing_checked_at ?? $row->updated_at ?? null;
        if ($fetched === null || $fetched === '') {
            return false;
        }

        try {
            $at = \Illuminate\Support\Carbon::parse($fetched);
        } catch (\Throwable) {
            return false;
        }

        return $at->lt(now()->subMinutes($maxAge));
    }
}
