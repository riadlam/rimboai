<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Seeds specialized Fal endpoints for video tools (2 models per tool).
 *
 * Token math (matches config/credits.php):
 *   credits = ceil( (fal_cost_usd * markup) / usd_per_credit )
 *   markup = 1.25, usd_per_credit = 0.01
 *
 * Marketing token_cost uses a 5s reference clip unless noted.
 * Safe to re-run: upserts on (tool_slug, endpoint_id).
 *
 * Models are chosen from Fal's specialized video tool catalog — not from
 * the text_to_video_models lab generation table.
 */
class VideoToolsModelsSeeder extends Seeder
{
    private const MARKUP = 1.25;

    private const USD_PER_CREDIT = 0.01;

    private const REF_SECONDS = 5;

    public function run(): void
    {
        if (! Schema::hasTable('video_tools_models')) {
            $this->command?->error('Table video_tools_models missing — run migrations first.');

            return;
        }

        $now = now();
        $rows = [];

        foreach ($this->catalog() as $row) {
            $refUsd = (float) $row['ref_cost_usd'];
            $tokenCost = self::creditsFromUsd($refUsd);

            $rows[] = [
                'sort' => (int) $row['sort'],
                'tool_slug' => $row['tool_slug'],
                'tool_name' => $row['tool_name'],
                'endpoint_id' => $row['endpoint_id'],
                'name' => $row['name'],
                'description' => $row['description'],
                'image_url' => $row['image_url'] ?? null,
                'image_cover' => null,
                'tags' => json_encode($row['tags'] ?? [], JSON_UNESCAPED_SLASHES),
                'status' => 'active',
                'unit' => $row['unit'],
                'unit_price' => number_format((float) $row['unit_price'], 6, '.', ''),
                'token_cost' => $tokenCost,
                'ref_cost_usd' => number_format($refUsd, 6, '.', ''),
                'ref_duration_seconds' => $row['ref_duration_seconds'] ?? self::REF_SECONDS,
                'max_duration' => $row['max_duration'] ?? null,
                'enums' => isset($row['enums']) ? json_encode($row['enums'], JSON_UNESCAPED_SLASHES) : null,
                'is_primary' => (bool) $row['is_primary'],
                'defaults' => isset($row['defaults']) ? json_encode($row['defaults'], JSON_UNESCAPED_SLASHES) : null,
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }

        foreach ($rows as $row) {
            DB::table('video_tools_models')->updateOrInsert(
                [
                    'tool_slug' => $row['tool_slug'],
                    'endpoint_id' => $row['endpoint_id'],
                ],
                $row
            );
        }

        $this->command?->info('Seeded '.count($rows).' video tool models ('.(count($rows) / 2).' tools × 2).');
        $this->command?->table(
            ['Tool', 'Model', 'Endpoint', 'Fal $ (ref)', 'Tokens'],
            collect($rows)->map(fn ($r) => [
                $r['tool_slug'],
                $r['name'],
                $r['endpoint_id'],
                $r['ref_cost_usd'],
                $r['token_cost'],
            ])->all()
        );
    }

    /** @return list<array<string, mixed>> */
    private function catalog(): array
    {
        $s = self::REF_SECONDS;

        return [
            // ── Video Upscaler ──────────────────────────────────────────────
            [
                'sort' => 10,
                'tool_slug' => 'video-upscaler',
                'tool_name' => 'Video Upscaler',
                'endpoint_id' => 'fal-ai/topaz/upscale/video',
                'name' => 'Topaz Video Upscale',
                'description' => 'Professional Topaz upscaling (Proteus). Best clarity for most footage; supports 1–4× scale.',
                'unit' => 'seconds',
                // Fal: $0.02/s for 720p→1080p output
                'unit_price' => 0.02,
                'ref_cost_usd' => 0.02 * $s,
                'max_duration' => 120,
                'enums' => ['2x', '4x'],
                'is_primary' => true,
                'defaults' => ['model' => 'Proteus', 'upscale_factor' => 2],
                'tags' => ['upscale', 'topaz', 'primary'],
            ],
            [
                'sort' => 20,
                'tool_slug' => 'video-upscaler',
                'tool_name' => 'Video Upscaler',
                'endpoint_id' => 'fal-ai/bytedance-upscaler/upscale/video',
                'name' => 'Bytedance Video Upscaler',
                'description' => 'Scenario-tuned ByteDance upscaler up to 4K (UGC / film / AIGC presets).',
                'unit' => 'seconds',
                // Fal: $0.0072/s @ 1080p 30fps standard
                'unit_price' => 0.0072,
                'ref_cost_usd' => 0.0072 * $s,
                'max_duration' => 120,
                'enums' => ['1080p', '2k', '4k'],
                'is_primary' => false,
                'defaults' => [
                    'target_resolution' => '1080p',
                    'target_fps' => '30fps',
                    'enhancement_tier' => 'standard',
                    'enhancement_preset' => 'general',
                ],
                'tags' => ['upscale', 'bytedance', 'fallback'],
            ],

            // ── Video Enhancer ──────────────────────────────────────────────
            [
                'sort' => 30,
                'tool_slug' => 'video-enhancer',
                'tool_name' => 'Video Enhancer',
                'endpoint_id' => 'fal-ai/seedvr/upscale/video',
                'name' => 'SeedVR2 Restore',
                'description' => 'One-step diffusion video restoration with temporal consistency — great general enhancer.',
                'unit' => 'megapixels',
                // Fal: $0.001 / megapixel (W×H×frames)
                'unit_price' => 0.001,
                // Ref: 1080p × 5s @ 24fps ≈ 1920×1080×120 / 1e6 = 248.832 MP
                'ref_cost_usd' => 248.832 * 0.001,
                'max_duration' => 60,
                'enums' => ['720p', '1080p', '1440p', '2160p'],
                'is_primary' => true,
                'defaults' => [
                    'upscale_mode' => 'target',
                    'target_resolution' => '1080p',
                    'noise_scale' => 0.1,
                ],
                'tags' => ['enhance', 'seedvr2', 'primary'],
            ],
            [
                'sort' => 40,
                'tool_slug' => 'video-enhancer',
                'tool_name' => 'Video Enhancer',
                'endpoint_id' => 'fal-ai/topaz/upscale/video',
                'name' => 'Topaz Artemis Enhance',
                'description' => 'Topaz Artemis HQ — denoise + sharpen enhance pass (same Topaz family, enhance-oriented model).',
                'unit' => 'seconds',
                'unit_price' => 0.02,
                'ref_cost_usd' => 0.02 * $s,
                'max_duration' => 120,
                'enums' => ['2x', '4x'],
                'is_primary' => false,
                'defaults' => ['model' => 'Artemis HQ', 'upscale_factor' => 2],
                'tags' => ['enhance', 'topaz', 'artemis', 'fallback'],
            ],

            // ── Lip Sync AI ─────────────────────────────────────────────────
            [
                'sort' => 50,
                'tool_slug' => 'lip-sync',
                'tool_name' => 'Lip Sync AI',
                'endpoint_id' => 'fal-ai/sync-lipsync/v2',
                'name' => 'Sync Lipsync 2.0',
                'description' => 'Sync Labs lipsync-2 — high-quality audio→mouth sync for talking-head clips.',
                'unit' => 'seconds',
                // Fal: $3 / minute = $0.05 / s
                'unit_price' => 0.05,
                'ref_cost_usd' => 0.05 * $s,
                'max_duration' => 120,
                'enums' => ['cut_off', 'loop', 'bounce', 'silence', 'remap'],
                'is_primary' => true,
                'defaults' => ['model' => 'lipsync-2', 'sync_mode' => 'cut_off'],
                'tags' => ['lipsync', 'sync', 'primary'],
            ],
            [
                'sort' => 60,
                'tool_slug' => 'lip-sync',
                'tool_name' => 'Lip Sync AI',
                'endpoint_id' => 'veed/lipsync/v2',
                'name' => 'VEED Lipsync v2',
                'description' => 'VEED production lipsync — alternate provider if Sync is unavailable.',
                'unit' => 'seconds',
                // Fal: $0.07 / output second
                'unit_price' => 0.07,
                'ref_cost_usd' => 0.07 * $s,
                'max_duration' => 120,
                'enums' => null,
                'is_primary' => false,
                'defaults' => null,
                'tags' => ['lipsync', 'veed', 'fallback'],
            ],

            // ── Face Swap Video ─────────────────────────────────────────────
            [
                'sort' => 70,
                'tool_slug' => 'face-swap-video',
                'tool_name' => 'Face Swap Video',
                'endpoint_id' => 'fal-ai/pixverse/swap',
                'name' => 'PixVerse Swap',
                'description' => 'Dedicated person/object/background swap from a reference image (mode=person for faces).',
                'unit' => 'video',
                // Fal: $0.20 for ≤5s @ 720p (doubles if longer)
                'unit_price' => 0.20,
                'ref_cost_usd' => 0.20,
                'ref_duration_seconds' => 5,
                'max_duration' => 30,
                'enums' => ['360p', '540p', '720p'],
                'is_primary' => true,
                'defaults' => ['mode' => 'person', 'resolution' => '720p'],
                'tags' => ['faceswap', 'pixverse', 'primary'],
            ],
            [
                'sort' => 80,
                'tool_slug' => 'face-swap-video',
                'tool_name' => 'Face Swap Video',
                'endpoint_id' => 'fal-ai/kling-video/o3/standard/video-to-video/edit',
                'name' => 'Kling O3 Edit (Character)',
                'description' => 'Kling O3 video edit with element/reference images — strong character/face replacement fallback.',
                'unit' => 'seconds',
                // Fal: $0.126 / generated second
                'unit_price' => 0.126,
                'ref_cost_usd' => 0.126 * $s,
                'max_duration' => 15,
                'enums' => null,
                'is_primary' => false,
                'defaults' => null,
                'tags' => ['faceswap', 'kling', 'edit', 'fallback'],
            ],

            // ── Video Background Remover ────────────────────────────────────
            [
                'sort' => 90,
                'tool_slug' => 'video-background-remover',
                'tool_name' => 'Video Background Remover',
                'endpoint_id' => 'veed/video-background-removal',
                'name' => 'VEED Background Removal',
                'description' => 'VEED subject cutout with optional edge refine — people & objects, no green screen.',
                'unit' => 'frames_30',
                // Fal: $0.0225 / 30 frames (refine ON). 5s@30fps = 150 frames = 5 units
                'unit_price' => 0.0225,
                'ref_cost_usd' => 0.0225 * (($s * 30) / 30),
                'max_duration' => 60,
                'enums' => ['vp9', 'h264'],
                'is_primary' => true,
                'defaults' => [
                    'refine_foreground_edges' => true,
                    'subject_is_person' => true,
                    'output_codec' => 'vp9',
                ],
                'tags' => ['background', 'veed', 'primary'],
            ],
            [
                'sort' => 100,
                'tool_slug' => 'video-background-remover',
                'tool_name' => 'Video Background Remover',
                'endpoint_id' => 'bria/video/background-removal',
                'name' => 'Bria Background Removal',
                'description' => 'Bria commercial-safe video matting with solid/transparent backgrounds.',
                'unit' => 'seconds',
                // Fal: $0.0042 / s
                'unit_price' => 0.0042,
                'ref_cost_usd' => 0.0042 * $s,
                'max_duration' => 30,
                'enums' => ['Transparent', 'Black', 'White'],
                'is_primary' => false,
                'defaults' => [
                    'background_color' => 'Transparent',
                    'preserve_audio' => true,
                    'output_container_and_codec' => 'webm_vp9',
                ],
                'tags' => ['background', 'bria', 'fallback'],
            ],

            // ── Video Subtitle Remover ──────────────────────────────────────
            [
                'sort' => 110,
                'tool_slug' => 'remove-subtitles-from-video',
                'tool_name' => 'Video Subtitle Remover',
                'endpoint_id' => 'bria/video/erase/prompt',
                'name' => 'Bria Video Erase (Prompt)',
                'description' => 'Prompt-based erase of burned-in captions/text/objects with temporal consistency.',
                'unit' => 'seconds',
                // Fal: $0.14 / s (clips < 5s)
                'unit_price' => 0.14,
                'ref_cost_usd' => 0.14 * $s,
                'max_duration' => 5,
                'enums' => null,
                'is_primary' => true,
                'defaults' => ['prompt' => 'subtitles, captions, on-screen text'],
                'tags' => ['subtitle-remove', 'bria', 'erase', 'primary'],
            ],
            [
                'sort' => 120,
                'tool_slug' => 'remove-subtitles-from-video',
                'tool_name' => 'Video Subtitle Remover',
                'endpoint_id' => 'fal-ai/void-video-inpainting',
                'name' => 'VOID Video Inpainting',
                'description' => 'VOID object/text removal with optional SAM mask — alternate inpaint path for hard burns.',
                'unit' => 'video',
                // Fal: $0.05 / video (+$0.05 SAM). Marketing ref includes auto-mask.
                'unit_price' => 0.05,
                'ref_cost_usd' => 0.10,
                'ref_duration_seconds' => 5,
                'max_duration' => 30,
                'enums' => null,
                'is_primary' => false,
                'defaults' => [
                    'mask_prompt' => 'subtitles and on-screen captions',
                    'prompt' => 'clean background without any text or captions',
                ],
                'tags' => ['subtitle-remove', 'void', 'inpaint', 'fallback'],
            ],

            // ── AI Video Extender ───────────────────────────────────────────
            [
                'sort' => 130,
                'tool_slug' => 'ai-video-extender',
                'tool_name' => 'AI Video Extender',
                'endpoint_id' => 'fal-ai/ltx-2.3/extend-video',
                'name' => 'LTX 2.3 Extend',
                'description' => 'Dedicated extend-video endpoint — continue start or end with coherent motion (2–20s).',
                'unit' => 'seconds',
                // Fal: $0.10 / s of extension
                'unit_price' => 0.10,
                'ref_cost_usd' => 0.10 * $s,
                'max_duration' => 20,
                'enums' => ['2', '5', '8', '10', '15', '20'],
                'is_primary' => true,
                'defaults' => ['duration' => 5, 'mode' => 'end'],
                'tags' => ['extend', 'ltx', 'primary'],
            ],
            [
                'sort' => 140,
                'tool_slug' => 'ai-video-extender',
                'tool_name' => 'AI Video Extender',
                'endpoint_id' => 'fal-ai/pixverse/v6/extend',
                'name' => 'PixVerse V6 Extend',
                'description' => 'PixVerse V6 continuation with optional native audio — backup extender.',
                'unit' => 'seconds',
                // Fal: $0.045 / s @ 720p no audio
                'unit_price' => 0.045,
                'ref_cost_usd' => 0.045 * $s,
                'max_duration' => 15,
                'enums' => ['360p', '540p', '720p', '1080p'],
                'is_primary' => false,
                'defaults' => [
                    'resolution' => '720p',
                    'duration' => 5,
                    'generate_audio_switch' => false,
                ],
                'tags' => ['extend', 'pixverse', 'fallback'],
            ],

            // ── Video To Video ──────────────────────────────────────────────
            [
                'sort' => 150,
                'tool_slug' => 'video-to-video',
                'tool_name' => 'Video To Video',
                'endpoint_id' => 'fal-ai/wan-vace-apps/video-edit',
                'name' => 'Wan VACE Video Edit',
                'description' => 'Natural-language video restyle/edit with optional reference images.',
                'unit' => 'seconds',
                // Fal: $0.10 / s @ 720p
                'unit_price' => 0.10,
                'ref_cost_usd' => 0.10 * $s,
                'max_duration' => 30,
                'enums' => ['480p', '580p', '720p'],
                'is_primary' => true,
                'defaults' => [
                    'resolution' => '720p',
                    'video_type' => 'auto',
                    'acceleration' => 'regular',
                ],
                'tags' => ['v2v', 'wan', 'vace', 'primary'],
            ],
            [
                'sort' => 160,
                'tool_slug' => 'video-to-video',
                'tool_name' => 'Video To Video',
                'endpoint_id' => 'fal-ai/kling-video/o3/standard/video-to-video/edit',
                'name' => 'Kling O3 V2V Edit',
                'description' => 'Kling O3 element-driven video edit — strong multi-shot restyle fallback.',
                'unit' => 'seconds',
                'unit_price' => 0.126,
                'ref_cost_usd' => 0.126 * $s,
                'max_duration' => 15,
                'enums' => null,
                'is_primary' => false,
                'defaults' => null,
                'tags' => ['v2v', 'kling', 'fallback'],
            ],

            // ── Denoise Video ───────────────────────────────────────────────
            [
                'sort' => 170,
                'tool_slug' => 'denoise-video',
                'tool_name' => 'Denoise Video',
                'endpoint_id' => 'fal-ai/topaz/upscale/video',
                'name' => 'Topaz Nyx Denoise',
                'description' => 'Topaz Nyx — dedicated denoise engine inside the Topaz video suite.',
                'unit' => 'seconds',
                'unit_price' => 0.02,
                'ref_cost_usd' => 0.02 * $s,
                'max_duration' => 120,
                'enums' => ['Nyx', 'Nyx Fast', 'Nyx XL', 'Nyx HF'],
                'is_primary' => true,
                'defaults' => [
                    'model' => 'Nyx',
                    'upscale_factor' => 1,
                    'noise' => 0.5,
                ],
                'tags' => ['denoise', 'topaz', 'nyx', 'primary'],
            ],
            [
                'sort' => 180,
                'tool_slug' => 'denoise-video',
                'tool_name' => 'Denoise Video',
                'endpoint_id' => 'fal-ai/seedvr/upscale/video',
                'name' => 'SeedVR2 Denoise Restore',
                'description' => 'SeedVR2 restoration with noise_scale control — diffusion denoise fallback.',
                'unit' => 'megapixels',
                'unit_price' => 0.001,
                'ref_cost_usd' => 248.832 * 0.001,
                'max_duration' => 60,
                'enums' => ['720p', '1080p'],
                'is_primary' => false,
                'defaults' => [
                    'upscale_mode' => 'target',
                    'target_resolution' => '1080p',
                    'noise_scale' => 0.35,
                ],
                'tags' => ['denoise', 'seedvr2', 'fallback'],
            ],
        ];
    }

    private static function creditsFromUsd(float $falCostUsd): int
    {
        if ($falCostUsd <= 0) {
            return 0;
        }

        return (int) ceil(($falCostUsd * self::MARKUP) / self::USD_PER_CREDIT);
    }
}
