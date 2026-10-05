<?php

namespace App\Services;

use App\Models\TrendTemplate;
use App\Services\Credits\CreditCalculator;
use App\Services\Credits\ImageGenerationCostEstimator;
use App\Services\Credits\VideoGenerationCostEstimator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Admin-only fal USD + token suggestion for curated Trend Templates.
 */
class TrendTemplateCostEstimator
{
    public function __construct(
        private FalPricingService $catalogPricing,
        private FalModelInspector $inspector,
        private VideoGenerationCostEstimator $videoCost,
        private ImageGenerationCostEstimator $imageCost,
        private CreditCalculator $credits,
    ) {}

    /**
     * @param  array{
     *   endpoint_id?: string|null,
     *   sheet_endpoint_id?: string|null,
     *   duration?: string|int|null,
     *   resolution?: string|null,
     *   aspect_ratio?: string|null,
     *   generate_audio?: bool|null,
     *   slots?: list<mixed>|null,
     *   reference_video_seconds?: float|int|null,
     * }  $data
     * @return array{
     *   fal_estimate_usd: float,
     *   suggested_trend_cost: int,
     *   video_usd: float,
     *   sheets_usd: float,
     *   breakdown: array<string, mixed>,
     * }
     */
    public function estimate(array $data): array
    {
        $endpointId = trim((string) ($data['endpoint_id'] ?? TrendTemplate::DEFAULT_ENDPOINT));
        if ($endpointId === '') {
            $endpointId = TrendTemplate::FALLBACK_ENDPOINT;
        }

        $sheetEndpoint = trim((string) ($data['sheet_endpoint_id'] ?? TrendTemplate::DEFAULT_SHEET_ENDPOINT));
        $aspect = (string) ($data['aspect_ratio'] ?? '16:9');
        $resolution = (string) ($data['resolution'] ?? '720p');
        $audio = (bool) ($data['generate_audio'] ?? false);
        $durationSeconds = $this->durationSeconds($data['duration'] ?? null, $data['reference_video_seconds'] ?? null);
        $slotCount = $this->imageSlotCount($data['slots'] ?? null);

        if (TrendTemplate::isCharacterSheetEndpoint($endpointId)) {
            $sheetEndpoint = trim((string) ($data['sheet_endpoint_id'] ?? TrendTemplate::DEFAULT_SHEET_ENDPOINT))
                ?: TrendTemplate::DEFAULT_SHEET_ENDPOINT;
            $sheetBase = preg_replace('#/edit$#', '', $sheetEndpoint) ?: $sheetEndpoint;
            $sheetSubmit = str_ends_with($sheetEndpoint, '/edit')
                ? $sheetEndpoint
                : (app(FalImageInputBuilder::class)->resolveEndpoint($sheetBase, ['https://example.com/x.jpg']));
            $sheetBilling = $this->resolveBilling($sheetSubmit) ?? $this->resolveBilling($sheetBase);
            $sheetOne = $this->imageCost->estimate([
                'endpoint_id' => $sheetSubmit,
                'unit' => $sheetBilling['unit'] ?? 'image',
                'unit_price' => $sheetBilling['unit_price'] ?? 0,
                'aspect' => '16:9',
                'resolution' => '1K',
                'quantity' => 1,
                'reference_count' => 1,
            ]);
            $sheetsUsd = round(((float) $sheetOne['fal_cost_usd']) * max(1, $slotCount), 6);
            $suggested = $sheetsUsd > 0
                ? max(1, $this->credits->applyFloor($this->credits->fromFalUsd($sheetsUsd), 'image'))
                : 0;

            return [
                'fal_estimate_usd' => $sheetsUsd,
                'suggested_trend_cost' => $suggested,
                'video_usd' => 0.0,
                'sheets_usd' => $sheetsUsd,
                'upscale_usd' => 0.0,
                'breakdown' => [
                    'endpoint_id' => $endpointId,
                    'sheet_endpoint_id' => $sheetSubmit,
                    'sheets_in_pipeline' => true,
                    'workflow' => 'character_sheet',
                    'slot_count' => max(1, $slotCount),
                    'video' => ['mode' => 'skipped_for_character_sheet'],
                    'sheet_one' => $sheetOne['breakdown'],
                    'billing_video' => null,
                    'billing_sheet' => $sheetBilling,
                ],
            ];
        }

        if (HiggsfieldService::isHiggsfieldEndpoint($endpointId)) {
            $quoted = HiggsfieldService::estimateGenjutsuUsd($durationSeconds, $resolution);
            $videoUsd = (float) $quoted['fal_cost_usd'];
            $suggested = $videoUsd > 0
                ? max(1, $this->credits->applyFloor($this->credits->fromFalUsd($videoUsd), 'video'))
                : 0;

            return [
                'fal_estimate_usd' => $videoUsd,
                'suggested_trend_cost' => $suggested,
                'video_usd' => $videoUsd,
                'sheets_usd' => 0.0,
                'upscale_usd' => 0.0,
                'breakdown' => [
                    'endpoint_id' => $endpointId,
                    'sheet_endpoint_id' => null,
                    'sheets_in_pipeline' => false,
                    'duration_seconds' => $durationSeconds,
                    'slot_count' => $slotCount,
                    'video' => $quoted['breakdown'],
                    'sheet_one' => ['mode' => 'skipped_for_higgsfield'],
                    'billing_video' => [
                        'endpoint_id' => $endpointId,
                        'unit' => $quoted['unit'],
                        'unit_price' => $quoted['unit_price'],
                        'source' => 'higgsfield_list',
                    ],
                    'billing_sheet' => null,
                ],
            ];
        }

        if (TrendTemplateRemakeService::isH3SplitEndpoint($endpointId)) {
            $sections = app(\App\Services\Trends\H3SplitCostEstimator::class)->planFromDuration((float) $durationSeconds);
            $quoted = app(\App\Services\Trends\H3SplitCostEstimator::class)->estimate($sections, $slotCount);
            $totalUsd = (float) $quoted['fal_cost_usd'];
            $suggested = $totalUsd > 0
                ? max(1, $this->credits->applyFloor($this->credits->fromFalUsd($totalUsd), 'video'))
                : 0;

            return [
                'fal_estimate_usd' => $totalUsd,
                'suggested_trend_cost' => $suggested,
                'video_usd' => (float) $quoted['video_usd'],
                'sheets_usd' => 0.0,
                'upscale_usd' => (float) $quoted['upscale_usd'],
                'breakdown' => [
                    'endpoint_id' => $endpointId,
                    'sheet_endpoint_id' => null,
                    'sheets_in_pipeline' => false,
                    'workflow' => 'h3_split',
                    'duration_seconds' => $durationSeconds,
                    'slot_count' => $slotCount,
                    'section_count' => count($sections),
                    'video' => $quoted['breakdown'],
                    'sheet_one' => ['mode' => 'skipped_for_h3_split'],
                    'billing_video' => [
                        'endpoint_id' => $endpointId,
                        'unit' => $quoted['unit'],
                        'unit_price' => $quoted['unit_price'],
                        'source' => 'fal_h3_split_list',
                    ],
                    'billing_sheet' => null,
                    'upscale_usd' => $quoted['upscale_usd'],
                ],
            ];
        }

        $videoBilling = $this->resolveBilling($endpointId);
        $video = $this->videoCost->estimate([
            'endpoint_id' => $endpointId,
            'unit' => $videoBilling['unit'] ?? 'seconds',
            'unit_price' => $videoBilling['unit_price'] ?? 0,
            'duration_seconds' => $durationSeconds,
            'audio' => $audio,
            'resolution' => $resolution,
            'aspect' => $aspect,
            'reference_video_seconds' => $durationSeconds,
            'reference_image_count' => $slotCount,
        ]);

        $sheetBase = preg_replace('#/edit$#', '', $sheetEndpoint) ?: $sheetEndpoint;
        $sheetSubmit = str_ends_with($sheetEndpoint, '/edit')
            ? $sheetEndpoint
            : (app(FalImageInputBuilder::class)->resolveEndpoint($sheetBase, ['https://example.com/x.jpg']));
        $sheetBilling = $this->resolveBilling($sheetSubmit) ?? $this->resolveBilling($sheetBase);
        $sheetOne = $this->imageCost->estimate([
            'endpoint_id' => $sheetSubmit,
            'unit' => $sheetBilling['unit'] ?? 'image',
            'unit_price' => $sheetBilling['unit_price'] ?? 0,
            'aspect' => '16:9',
            'resolution' => '1K',
            'quantity' => 1,
            'reference_count' => 1,
        ]);
        $sheetsUsd = round(((float) $sheetOne['fal_cost_usd']) * max(0, $slotCount), 6);
        $videoUsd = (float) $video['fal_cost_usd'];
        $totalUsd = round($videoUsd + $sheetsUsd, 6);

        $suggested = $totalUsd > 0
            ? max(1, $this->credits->applyFloor($this->credits->fromFalUsd($totalUsd), 'video'))
            : 0;

        return [
            'fal_estimate_usd' => $totalUsd,
            'suggested_trend_cost' => $suggested,
            'video_usd' => $videoUsd,
            'sheets_usd' => $sheetsUsd,
            'upscale_usd' => 0.0,
            'breakdown' => [
                'endpoint_id' => $endpointId,
                'sheet_endpoint_id' => $sheetSubmit,
                'duration_seconds' => $durationSeconds,
                'slot_count' => $slotCount,
                'video' => $video['breakdown'],
                'sheet_one' => $sheetOne['breakdown'],
                'billing_video' => $videoBilling,
                'billing_sheet' => $sheetBilling,
            ],
        ];
    }

    /**
     * @return array{endpoint_id: string, unit: string|null, unit_price: float, source: string}|null
     */
    private function resolveBilling(string $endpointId): ?array
    {
        $fromCatalog = $this->catalogPricing->resolve($endpointId);
        if ($fromCatalog !== null) {
            return $fromCatalog;
        }

        $live = $this->inspector->fetchPricing($endpointId);
        if ($live !== null && ($live['unit_price'] ?? null) !== null && (float) $live['unit_price'] > 0) {
            return [
                'endpoint_id' => $endpointId,
                'unit' => $live['unit'],
                'unit_price' => (float) $live['unit_price'],
                'source' => 'fal_live',
            ];
        }

        foreach (['text_to_video_models', 'image_to_video_models', 'text_to_image_models'] as $table) {
            if (! Schema::hasTable($table)) {
                continue;
            }
            $row = DB::table($table)->where('endpoint_id', $endpointId)->first(['endpoint_id', 'unit', 'unit_price']);
            if ($row && $row->unit_price !== null && (float) $row->unit_price > 0) {
                return [
                    'endpoint_id' => $endpointId,
                    'unit' => isset($row->unit) ? (string) $row->unit : null,
                    'unit_price' => (float) $row->unit_price,
                    'source' => 'catalog_any:'.$table,
                ];
            }
        }

        return null;
    }

    private function durationSeconds(mixed $duration, mixed $referenceSeconds): int
    {
        if (is_numeric($referenceSeconds) && (float) $referenceSeconds > 0) {
            return max(1, (int) round((float) $referenceSeconds));
        }

        if (is_numeric($duration) && (float) $duration > 0) {
            return max(1, (int) round((float) $duration));
        }

        if (is_string($duration) && ctype_digit($duration)) {
            return max(1, (int) $duration);
        }

        return 15;
    }

    private function imageSlotCount(mixed $slots): int
    {
        if (! is_array($slots)) {
            return 2;
        }

        $n = 0;
        foreach ($slots as $slot) {
            if (! is_array($slot)) {
                continue;
            }
            if ((string) ($slot['kind'] ?? 'image') === 'image') {
                $n++;
            }
        }

        return max(0, $n);
    }
}
