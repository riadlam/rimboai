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
        ] as $table) {
            if (! Schema::hasTable($table)) {
                continue;
            }

            $row = DB::table($table)
                ->where('endpoint_id', $endpointId)
                ->where('status', 'active')
                ->first(['endpoint_id', 'unit', 'unit_price']);

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
}
