<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Marks catalog video models that support first+last frame generation on Fal.
 * Also ensures alternate submit endpoints exist for billing.
 * Safe to re-run on production.
 */
class VideoFirstLastFrameSeeder extends Seeder
{
    /** @var array<string, string> catalog endpoint_id => FLF sibling */
    private const MAP = [
        'fal-ai/veo3.1' => 'fal-ai/veo3.1/first-last-frame-to-video',
        'fal-ai/veo3.1/fast' => 'fal-ai/veo3.1/fast/first-last-frame-to-video',
        'fal-ai/veo3.1/lite' => 'fal-ai/veo3.1/lite/first-last-frame-to-video',
        'fal-ai/kling-video/o1/reference-to-video' => 'fal-ai/kling-video/o1/image-to-video',
    ];

    /** Pricing rows for FLF / start-end submit endpoints (image_to_video_models). */
    private const BILLING = [
        'fal-ai/veo3.1/first-last-frame-to-video' => [
            'name' => 'veo3.1',
            'description' => 'Generate videos from a first/last frame using Google\'s Veo 3.1',
            'unit' => 'seconds',
            'unit_price' => '0.400000',
            'supports_audio' => true,
            'max_duration' => 8,
            'enums' => '["4s","6s","8s"]',
            'sort' => 160,
        ],
        'fal-ai/veo3.1/fast/first-last-frame-to-video' => [
            'name' => 'veo3.1',
            'description' => 'Generate videos from a first/last frame using Google\'s Veo 3.1 Fast',
            'unit' => 'seconds',
            'unit_price' => '0.150000',
            'supports_audio' => true,
            'max_duration' => 8,
            'enums' => '["4s","6s","8s"]',
            'sort' => 170,
        ],
        'fal-ai/veo3.1/lite/first-last-frame-to-video' => [
            'name' => 'veo3.1',
            'description' => 'Generate videos from a first/last frame using Google\'s Veo 3.1 Lite',
            'unit' => 'seconds',
            'unit_price' => '0.050000',
            'supports_audio' => true,
            'max_duration' => 8,
            'enums' => '["4s","6s","8s"]',
            'sort' => 175,
        ],
        'fal-ai/kling-video/o1/image-to-video' => [
            'name' => 'Kling O1 First Last Frame',
            'description' => 'Kling O1 start/end frame image-to-video.',
            'unit' => 'seconds',
            'unit_price' => '0.112000',
            'supports_audio' => false,
            'max_duration' => 10,
            'enums' => '["3","4","5","6","7","8","9","10"]',
            'sort' => 86,
        ],
    ];

    public function run(): void
    {
        if (! Schema::hasTable('text_to_video_models')) {
            $this->command?->warn('text_to_video_models missing — skip.');

            return;
        }

        $hasLast = Schema::hasColumn('text_to_video_models', 'supports_last_frame');
        $hasFirst = Schema::hasColumn('text_to_video_models', 'supports_first_frame');
        $hasEndpoint = Schema::hasColumn('text_to_video_models', 'first_last_frame_endpoint_id');

        if (! $hasLast && ! $hasFirst && ! $hasEndpoint) {
            $this->command?->warn('FLF columns missing — run migrations first.');

            return;
        }

        $updated = 0;
        foreach (self::MAP as $catalogEndpoint => $flfEndpoint) {
            $payload = ['updated_at' => now()];
            if ($hasFirst) {
                $payload['supports_first_frame'] = true;
            }
            if ($hasLast) {
                $payload['supports_last_frame'] = true;
            }
            if ($hasEndpoint) {
                $payload['first_last_frame_endpoint_id'] = $flfEndpoint;
            }

            $n = DB::table('text_to_video_models')
                ->where('endpoint_id', $catalogEndpoint)
                ->update($payload);
            $updated += $n;
        }

        $this->command?->info("Video first/last frame flags updated on {$updated} model row(s).");

        if (! Schema::hasTable('image_to_video_models')) {
            return;
        }

        $billingCols = Schema::getColumnListing('image_to_video_models');
        $billingUpserted = 0;
        foreach (self::BILLING as $endpointId => $meta) {
            $exists = DB::table('image_to_video_models')->where('endpoint_id', $endpointId)->exists();
            $row = [
                'endpoint_id' => $endpointId,
                'name' => $meta['name'],
                'description' => $meta['description'],
                'status' => 'active',
                'unit' => $meta['unit'],
                'unit_price' => $meta['unit_price'],
                'updated_at' => now(),
            ];
            if (in_array('supports_audio', $billingCols, true)) {
                $row['supports_audio'] = $meta['supports_audio'];
            }
            if (in_array('max_duration', $billingCols, true)) {
                $row['max_duration'] = $meta['max_duration'];
            }
            if (in_array('enums', $billingCols, true)) {
                $row['enums'] = $meta['enums'];
            }
            if (in_array('sort', $billingCols, true)) {
                $row['sort'] = $meta['sort'];
            }
            if (in_array('image_url', $billingCols, true) && ! $exists) {
                $row['image_url'] = null;
            }

            if ($exists) {
                DB::table('image_to_video_models')->where('endpoint_id', $endpointId)->update($row);
            } else {
                $row['created_at'] = now();
                DB::table('image_to_video_models')->insert($row);
            }
            $billingUpserted++;
        }

        $this->command?->info("FLF billing endpoints upserted: {$billingUpserted}.");
    }
}
