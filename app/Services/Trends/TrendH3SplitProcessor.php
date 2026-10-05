<?php

namespace App\Services\Trends;

use App\Models\TrendTemplate;
use App\Models\User;
use App\Models\UserVideoCreation;
use App\Services\FalService;
use App\Services\FalVideoInputBuilder;
use App\Services\FalWebhookProcessor;
use App\Services\LabCreationPresenter;
use App\Services\MediaMuxService;
use App\Services\Tokens\TokenService;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Orchestrates one H3-split trend remake step so each queue tick stays short.
 */
class TrendH3SplitProcessor
{
    public const PHASE_PREPARE = 'prepare';

    public const PHASE_GENERATE = 'generate';

    public const PHASE_UPSCALE = 'upscale';

    public const PHASE_FINALIZE = 'finalize';

    public function __construct(
        private FalService $fal,
        private FalVideoInputBuilder $videoInput,
        private MediaMuxService $mux,
        private MotionSectionSplitter $splitter,
        private H3SplitCostEstimator $costs,
        private FalWebhookProcessor $processor,
        private TokenService $tokens,
    ) {}

    public function tick(UserVideoCreation $creation): void
    {
        if (method_exists($creation, 'isTerminal') && $creation->isTerminal()) {
            return;
        }

        $settings = is_array($creation->settings) ? $creation->settings : [];
        $state = is_array($settings['h3_split'] ?? null) ? $settings['h3_split'] : [];
        $phase = (string) ($state['phase'] ?? self::PHASE_PREPARE);

        match ($phase) {
            self::PHASE_PREPARE => $this->prepare($creation, $settings, $state),
            self::PHASE_GENERATE => $this->generate($creation, $settings, $state),
            self::PHASE_UPSCALE => $this->upscale($creation, $settings, $state),
            self::PHASE_FINALIZE => $this->finalize($creation, $settings, $state),
            default => $this->fail($creation, 'Unknown H3 split phase.', 'h3_phase_error'),
        };
    }

    /**
     * @param  array<string, mixed>  $settings
     * @param  array<string, mixed>  $state
     */
    private function prepare(UserVideoCreation $creation, array $settings, array $state): void
    {
        $sketchUrl = (string) ($state['sketch_url'] ?? '');
        $audioUrl = (string) ($state['audio_url'] ?? '');
        $imageUrls = is_array($state['image_urls'] ?? null) ? array_values($state['image_urls']) : [];
        if ($sketchUrl === '' || $imageUrls === []) {
            $this->fail($creation, 'H3 split is missing motion sketch or reference images.', 'h3_prepare_error');

            return;
        }

        $this->progress($creation, 'Planning H3 sections…');

        try {
            $sketchUrl = $this->fal->ensureInferenceVideoUrl($sketchUrl, 'motion-sketch.mp4');
            $imageUrls = array_map(
                fn ($url): string => $this->fal->ensureCdnUrl((string) $url),
                $imageUrls,
            );
            if ($audioUrl !== '') {
                $audioUrl = $this->fal->ensureCdnUrl($audioUrl);
            }
        } catch (Throwable $e) {
            report($e);
            $this->fail(
                $creation,
                $e->getMessage() !== '' ? $e->getMessage() : 'Failed to prepare H3 media.',
                'h3_rehost_error',
            );

            return;
        }

        if ($audioUrl === '') {
            $extracted = $this->mux->extractAudioToPublicStorage(
                $sketchUrl,
                'trend-remakes/'.$creation->user_id.'/h3/'.$creation->id.'/audio',
                'full-audio.mp3',
            );
            $audioUrl = $extracted['url'];
            try {
                $audioUrl = $this->fal->ensureCdnUrl($audioUrl);
            } catch (Throwable $e) {
                // Public storage URL may still be reachable; continue.
                report($e);
            }
        }

        $sections = $this->splitter->split($sketchUrl);
        $dir = 'trend-remakes/'.$creation->user_id.'/h3/'.$creation->id.'/slices';
        $prepared = [];
        foreach ($sections as $i => $section) {
            $start = (float) $section['start'];
            $dur = (float) $section['duration'];
            $video = $this->mux->sliceVideoRangeToPublicStorage(
                $sketchUrl,
                $start,
                $dur,
                $dir,
                'section-'.$i.'-video.mp4',
            );
            $audio = $this->mux->sliceAudioRangeToPublicStorage(
                $audioUrl,
                $start,
                $dur,
                $dir,
                'section-'.$i.'-audio.mp3',
            );
            $prepared[] = [
                'index' => $i,
                'start' => $start,
                'end' => (float) $section['end'],
                'duration' => $dur,
                'video_url' => $video['url'],
                'audio_url' => $audio['url'],
                'fal_request_id' => null,
                'fal_status_url' => null,
                'fal_response_url' => null,
                'raw_video_url' => null,
                'upscaled_video_url' => null,
                'flash_request_id' => null,
                'flash_status_url' => null,
                'flash_response_url' => null,
            ];
        }

        $estimate = $this->costs->estimate($prepared, count($imageUrls));
        $state = array_merge($state, [
            'phase' => self::PHASE_GENERATE,
            'index' => 0,
            'audio_url' => $audioUrl,
            'sections' => $prepared,
            'estimate' => $estimate,
        ]);
        $this->saveState($creation, $settings, $state, 'Generating H3 section 1/'.count($prepared).'…', [
            'fal_cost_usd' => $estimate['fal_cost_usd'],
            'cost_breakdown' => $estimate['breakdown'],
        ]);
    }

    /**
     * @param  array<string, mixed>  $settings
     * @param  array<string, mixed>  $state
     */
    private function generate(UserVideoCreation $creation, array $settings, array $state): void
    {
        $sections = is_array($state['sections'] ?? null) ? $state['sections'] : [];
        $index = (int) ($state['index'] ?? 0);
        if ($sections === [] || $index >= count($sections)) {
            $state['phase'] = self::PHASE_UPSCALE;
            $state['index'] = 0;
            $this->saveState($creation, $settings, $state, 'Upscaling H3 sections…');

            return;
        }

        $section = $sections[$index];
        $n = $index + 1;
        $total = count($sections);

        if (empty($section['fal_request_id'])) {
            $this->progress($creation, "Submitting H3 section {$n}/{$total}…");
            $prompt = (string) ($state['prompt'] ?? $creation->prompt ?? '');
            $imageUrls = is_array($state['image_urls'] ?? null) ? $state['image_urls'] : [];
            $aspect = (string) ($state['aspect'] ?? $creation->aspect_ratio ?? '16:9');
            $durationOut = (int) max(5, min(15, (int) ceil((float) $section['duration'])));

            $built = $this->videoInput->build(H3SplitCostEstimator::H3_ENDPOINT, [
                'prompt' => $prompt,
                'aspect' => $aspect,
                'resolution' => '720p', // maps to 768P
                'duration' => $durationOut,
                'audio' => true,
                'mode' => 'reference-to-video',
                'image_urls' => $imageUrls,
                'video_urls' => [(string) $section['video_url']],
                'audio_urls' => [(string) $section['audio_url']],
                'prompt_expansion_mode' => 'disabled',
                'enable_safety_checker' => true,
            ]);

            try {
                $submit = $this->fal->submit(H3SplitCostEstimator::H3_ENDPOINT, $built['input'], '');
            } catch (Throwable $e) {
                report($e);
                $this->fail($creation, $e->getMessage() !== '' ? $e->getMessage() : 'H3 submit failed.', 'h3_submit_error');

                return;
            }

            $sections[$index]['fal_request_id'] = $submit['request_id'] ?? null;
            $sections[$index]['fal_status_url'] = $submit['status_url'] ?? null;
            $sections[$index]['fal_response_url'] = $submit['response_url'] ?? null;
            $state['sections'] = $sections;
            $this->saveState($creation, $settings, $state, "Generating H3 section {$n}/{$total}…");

            return;
        }

        $statusUrl = (string) ($section['fal_status_url'] ?? '');
        $responseUrl = (string) ($section['fal_response_url'] ?? '');
        if ($statusUrl === '' || $responseUrl === '') {
            $this->fail($creation, 'H3 section missing status URL.', 'h3_status_missing');

            return;
        }

        try {
            $status = $this->fal->statusByUrl($statusUrl);
        } catch (Throwable $e) {
            report($e);
            Log::warning('trends.h3.section_status_failed', [
                'creation_id' => $creation->id,
                'index' => $index,
                'error' => $e->getMessage(),
            ]);

            return;
        }

        $stateName = strtoupper((string) ($status['status'] ?? ''));
        if (in_array($stateName, ['IN_QUEUE', 'IN_PROGRESS', ''], true)) {
            $msg = $stateName === 'IN_QUEUE'
                ? LabCreationPresenter::queueProgressMessage(isset($status['queue_position']) ? (int) $status['queue_position'] : null)
                : "Generating H3 section {$n}/{$total}…";
            $this->progress($creation, $msg);

            return;
        }

        if (in_array($stateName, ['FAILED', 'ERROR', 'CANCELLED'], true) || ! empty($status['error'])) {
            $message = is_string($status['error'] ?? null) ? $status['error'] : 'H3 section generation failed.';
            $this->fail($creation, $message, 'h3_section_failed');

            return;
        }

        if (! in_array($stateName, ['COMPLETED', 'OK'], true)) {
            return;
        }

        try {
            $result = $this->fal->resultByUrl($responseUrl);
        } catch (Throwable $e) {
            report($e);
            $this->fail($creation, 'Could not download H3 section result.', 'h3_result_error');

            return;
        }

        $videoUrl = $this->extractVideoUrl($result);
        if ($videoUrl === null) {
            $this->fail($creation, 'H3 section returned no video.', 'h3_empty_result');

            return;
        }

        $sections[$index]['raw_video_url'] = $videoUrl;
        $state['sections'] = $sections;
        $state['index'] = $index + 1;
        if ($state['index'] >= count($sections)) {
            $state['phase'] = self::PHASE_UPSCALE;
            $state['index'] = 0;
            $this->saveState($creation, $settings, $state, 'Upscaling H3 sections…');
        } else {
            $this->saveState($creation, $settings, $state, 'Generating H3 section '.($state['index'] + 1).'/'.count($sections).'…');
        }
    }

    /**
     * @param  array<string, mixed>  $settings
     * @param  array<string, mixed>  $state
     */
    private function upscale(UserVideoCreation $creation, array $settings, array $state): void
    {
        $sections = is_array($state['sections'] ?? null) ? $state['sections'] : [];
        $index = (int) ($state['index'] ?? 0);
        if ($sections === [] || $index >= count($sections)) {
            $state['phase'] = self::PHASE_FINALIZE;
            $this->saveState($creation, $settings, $state, 'Stitching final video…');

            return;
        }

        $section = $sections[$index];
        $n = $index + 1;
        $total = count($sections);
        $raw = (string) ($section['raw_video_url'] ?? '');
        if ($raw === '') {
            $this->fail($creation, 'Missing H3 section video for upscale.', 'h3_upscale_missing');

            return;
        }

        if (empty($section['flash_request_id'])) {
            $this->progress($creation, "Upscaling section {$n}/{$total}…");
            try {
                $submit = $this->fal->submit(H3SplitCostEstimator::FLASHVSR_ENDPOINT, [
                    'video_url' => $raw,
                    'upscale_factor' => 2,
                    'acceleration' => 'regular',
                    'color_fix' => true,
                    'quality' => 70,
                    'output_format' => 'X264 (.mp4)',
                    'output_quality' => 'high',
                    'output_write_mode' => 'balanced',
                ], '');
            } catch (Throwable $e) {
                report($e);
                $this->fail($creation, $e->getMessage() !== '' ? $e->getMessage() : 'FlashVSR submit failed.', 'flashvsr_submit_error');

                return;
            }

            $sections[$index]['flash_request_id'] = $submit['request_id'] ?? null;
            $sections[$index]['flash_status_url'] = $submit['status_url'] ?? null;
            $sections[$index]['flash_response_url'] = $submit['response_url'] ?? null;
            $state['sections'] = $sections;
            $this->saveState($creation, $settings, $state, "Upscaling section {$n}/{$total}…");

            return;
        }

        $statusUrl = (string) ($section['flash_status_url'] ?? '');
        $responseUrl = (string) ($section['flash_response_url'] ?? '');
        if ($statusUrl === '' || $responseUrl === '') {
            $this->fail($creation, 'FlashVSR missing status URL.', 'flashvsr_status_missing');

            return;
        }

        try {
            $status = $this->fal->statusByUrl($statusUrl);
        } catch (Throwable $e) {
            report($e);

            return;
        }

        $stateName = strtoupper((string) ($status['status'] ?? ''));
        if (in_array($stateName, ['IN_QUEUE', 'IN_PROGRESS', ''], true)) {
            $this->progress($creation, "Upscaling section {$n}/{$total}…");

            return;
        }
        if (in_array($stateName, ['FAILED', 'ERROR', 'CANCELLED'], true) || ! empty($status['error'])) {
            $message = is_string($status['error'] ?? null) ? $status['error'] : 'FlashVSR failed.';
            $this->fail($creation, $message, 'flashvsr_failed');

            return;
        }
        if (! in_array($stateName, ['COMPLETED', 'OK'], true)) {
            return;
        }

        try {
            $result = $this->fal->resultByUrl($responseUrl);
        } catch (Throwable $e) {
            report($e);
            $this->fail($creation, 'Could not download FlashVSR result.', 'flashvsr_result_error');

            return;
        }

        $videoUrl = $this->extractVideoUrl($result);
        if ($videoUrl === null) {
            $this->fail($creation, 'FlashVSR returned no video.', 'flashvsr_empty');

            return;
        }

        $sections[$index]['upscaled_video_url'] = $videoUrl;
        $state['sections'] = $sections;
        $state['index'] = $index + 1;
        if ($state['index'] >= count($sections)) {
            $state['phase'] = self::PHASE_FINALIZE;
            $this->saveState($creation, $settings, $state, 'Stitching final video…');
        } else {
            $this->saveState($creation, $settings, $state, 'Upscaling section '.($state['index'] + 1).'/'.count($sections).'…');
        }
    }

    /**
     * @param  array<string, mixed>  $settings
     * @param  array<string, mixed>  $state
     */
    private function finalize(UserVideoCreation $creation, array $settings, array $state): void
    {
        $sections = is_array($state['sections'] ?? null) ? $state['sections'] : [];
        $urls = [];
        foreach ($sections as $section) {
            $url = (string) ($section['upscaled_video_url'] ?? $section['raw_video_url'] ?? '');
            if ($url === '') {
                $this->fail($creation, 'Missing section video while stitching.', 'h3_stitch_missing');

                return;
            }
            $urls[] = $url;
        }

        $this->progress($creation, 'Stitching final video…');
        $dir = 'trend-remakes/'.$creation->user_id.'/h3/'.$creation->id.'/final';

        try {
            $concat = $this->mux->concatVideosToPublicStorage($urls, $dir, 'stitched.mp4');
            $audioUrl = (string) ($state['audio_url'] ?? '');
            if ($audioUrl === '') {
                throw new \RuntimeException('Missing song audio for final mux.');
            }
            $muxed = $this->mux->muxToPublicStorage($concat['url'], $audioUrl, $dir, 'final.mp4');
        } catch (Throwable $e) {
            report($e);
            $this->fail($creation, $e->getMessage() !== '' ? $e->getMessage() : 'Final stitch/mux failed.', 'h3_finalize_error');

            return;
        }

        $estimate = is_array($state['estimate'] ?? null) ? $state['estimate'] : [];
        $settings['h3_split'] = array_merge($state, ['phase' => 'done']);
        $settings['workflow'] = 'h3_split';
        $settings['fal_cost_usd'] = $estimate['fal_cost_usd'] ?? ($settings['fal_cost_usd'] ?? null);
        $settings['cost_breakdown'] = $estimate['breakdown'] ?? ($settings['cost_breakdown'] ?? null);

        $creation->forceFill([
            'status' => UserVideoCreation::STATUS_COMPLETED,
            'provider' => 'fal',
            'result_video_url' => $muxed['url'],
            'result_preview_url' => $muxed['url'],
            'result_assets' => [['url' => $muxed['url'], 'content_type' => 'video/mp4']],
            'progress_message' => 'Completed',
            'queue_position' => null,
            'completed_at' => now(),
            'error_message' => null,
            'error_type' => null,
            'settings' => $settings,
            'cost_usd' => $estimate['fal_cost_usd'] ?? $creation->cost_usd,
            'cost_usd_source' => 'h3_split_estimate',
            'cost_usd_is_final' => true,
            'deducted_amount_from_main_wallet' => $creation->deducted_amount_from_main_wallet ?? 0,
            'settled_at' => now(),
        ])->save();

        $this->processor->broadcastSnapshot('video', $creation->fresh());
        Log::info('trends.h3.split_completed', ['creation_id' => $creation->id]);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function extractVideoUrl(array $payload): ?string
    {
        $video = $payload['video'] ?? null;
        if (is_array($video) && is_string($video['url'] ?? null) && $video['url'] !== '') {
            return $video['url'];
        }
        if (is_string($payload['video_url'] ?? null) && $payload['video_url'] !== '') {
            return $payload['video_url'];
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $settings
     * @param  array<string, mixed>  $state
     * @param  array<string, mixed>  $extra
     */
    private function saveState(
        UserVideoCreation $creation,
        array $settings,
        array $state,
        string $progress,
        array $extra = [],
    ): void {
        $settings['h3_split'] = $state;
        $settings['workflow'] = 'h3_split';
        $fill = array_merge([
            'settings' => $settings,
            'progress_message' => $progress,
            'status' => UserVideoCreation::STATUS_IN_PROGRESS,
        ], $extra);
        $creation->forceFill($fill)->save();
        $this->processor->broadcastSnapshot('video', $creation->fresh());
    }

    private function progress(UserVideoCreation $creation, string $message): void
    {
        $creation->forceFill([
            'status' => UserVideoCreation::STATUS_IN_PROGRESS,
            'progress_message' => $message,
        ])->save();
        $this->processor->broadcastSnapshot('video', $creation->fresh());
    }

    private function fail(UserVideoCreation $creation, string $message, string $type): void
    {
        $creation->markFailed($message, $type);
        $user = User::query()->find($creation->user_id);
        if ($user) {
            $this->tokens->refund($user, $creation, 'video', $type);
        }
        $this->processor->broadcastSnapshot('video', $creation->fresh());
        Log::warning('trends.h3.split_failed', [
            'creation_id' => $creation->id,
            'type' => $type,
            'error' => $message,
        ]);
    }

    public function forceTimeout(UserVideoCreation $creation): void
    {
        if (method_exists($creation, 'isTerminal') && $creation->isTerminal()) {
            return;
        }
        $this->fail($creation, 'H3 split timed out waiting for fal.', 'h3_timeout');
    }
}
