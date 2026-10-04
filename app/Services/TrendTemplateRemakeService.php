<?php

namespace App\Services;

use App\Exceptions\InsufficientTokensException;
use App\Exceptions\TrendsRemakeException;
use App\Models\TrendTemplate;
use App\Models\User;
use App\Models\UserVideoCreation;
use App\Services\Credits\VideoGenerationCostEstimator;
use App\Services\Tokens\TokenService;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Curated Trend Template remake: face photos → character sheets → Seedance R2V.
 */
class TrendTemplateRemakeService
{
    public function __construct(
        private FalService $fal,
        private FalVideoInputBuilder $videoInput,
        private FalImageInputBuilder $imageInput,
        private FalPricingService $pricing,
        private VideoGenerationCostEstimator $videoCost,
        private TokenService $tokens,
        private FalWalletCostTracker $walletCost,
        private FalWebhookProcessor $processor,
        private TrendsFeedService $trends,
        private MediaMuxService $mux,
    ) {}

    /**
     * @param  array<string, string>  $slotPhotos  slot key => image URL
     * @return array<string, mixed>
     */
    public function remake(User $user, TrendTemplate|string|int $template, array $slotPhotos): array
    {
        if (! $this->fal->configured()) {
            throw new TrendsRemakeException('Generation service is not configured.', 503);
        }

        $template = $this->resolveTemplate($template);
        if (! $template->is_published) {
            throw new TrendsRemakeException('Template not found or not published.', 404);
        }

        $active = UserVideoCreation::query()
            ->where('user_id', $user->id)
            ->where('settings->from_trend_template_id', $template->id)
            ->whereIn('status', [
                UserVideoCreation::STATUS_PENDING,
                UserVideoCreation::STATUS_QUEUED,
                UserVideoCreation::STATUS_IN_PROGRESS,
            ])
            ->orderByDesc('id')
            ->first();
        if ($active) {
            $remakes = $this->trends->userRemakesForTrendTemplate((int) $template->id, (int) $user->id);

            return [
                'type' => 'video',
                'creation' => $this->presentVideo($active),
                'user_remake_count' => $remakes['count'],
                'user_latest' => $remakes['latest'],
            ];
        }

        $sketchUrl = $template->motionSketchUrl();
        if (! is_string($sketchUrl) || $sketchUrl === '') {
            throw new TrendsRemakeException('Template is missing a motion sketch.', 422);
        }

        $clientSlots = $template->clientSlots();
        if ($clientSlots === []) {
            throw new TrendsRemakeException('Template has no face upload slots.', 422);
        }

        $orderedPhotos = [];
        foreach ($clientSlots as $slot) {
            $key = $slot['key'];
            $url = $slotPhotos[$key] ?? null;
            if ((! is_string($url) || $url === '') && $slot['required']) {
                throw new TrendsRemakeException(
                    'Upload a photo for “'.($slot['label'] ?: $key).'”.',
                    422,
                );
            }
            if (is_string($url) && $url !== '') {
                $orderedPhotos[] = [
                    'key' => $key,
                    'label' => $slot['label'],
                    'role' => $slot['role'],
                    'photo_url' => $url,
                ];
            }
        }

        if ($orderedPhotos === []) {
            throw new TrendsRemakeException('Upload at least one face photo.', 422);
        }

        $prompt = trim((string) $template->prompt);
        if ($prompt === '' || ! str_contains($prompt, '@Video1')) {
            throw new TrendsRemakeException('Template prompt is invalid.', 422);
        }

        $endpointId = trim((string) $template->endpoint_id) ?: TrendTemplate::DEFAULT_ENDPOINT;
        $model = $this->resolveVideoModel($endpointId);
        if (! $model) {
            // Fall back to Kling O3 Pro if MiniMax H3 catalog row is missing.
            $endpointId = TrendTemplate::FALLBACK_ENDPOINT;
            $model = $this->resolveVideoModel($endpointId);
        }
        if (! $model) {
            throw new TrendsRemakeException(__('messages.model_unavailable'), 422);
        }
        $submitEndpoint = (string) $model->endpoint_id;

        $credits = (int) $template->trend_cost;
        if ($credits <= 0) {
            throw new TrendsRemakeException(
                'This template has no Trends token price (trend_cost). Set it in Filament.',
                422,
            );
        }

        $aspect = (string) ($template->aspect_ratio ?: '16:9');
        $resolution = (string) ($template->resolution ?: '720p');
        $duration = $template->duration ?: '15';
        $audio = (bool) $template->generate_audio;

        $allowedDurations = null;
        if (! empty($model->enums)) {
            $decoded = is_string($model->enums) ? json_decode($model->enums, true) : $model->enums;
            $allowedDurations = is_array($decoded) ? $decoded : null;
        }

        $inputAssets = [];
        foreach ($orderedPhotos as $photo) {
            $inputAssets[] = [
                'url' => $photo['photo_url'],
                'fal_url' => $photo['photo_url'],
                'type' => 'image',
                'role' => 'face_'.$photo['key'],
                'slot_key' => $photo['key'],
            ];
        }
        $inputAssets[] = [
            'url' => $sketchUrl,
            'fal_url' => $sketchUrl,
            'type' => 'video',
            'role' => 'motion_sketch',
        ];

        // Kapwing-style: keep locked song for post-mux only — never send it to the model.
        $muxAudioUrl = $template->optionalAudioUrl();
        if (is_string($muxAudioUrl) && $muxAudioUrl !== '') {
            $inputAssets[] = [
                'url' => $muxAudioUrl,
                'fal_url' => $muxAudioUrl,
                'type' => 'audio',
                'role' => 'mux_audio',
            ];
        }

        try {
            /** @var UserVideoCreation $creation */
            $creation = $this->tokens->reserve(
                $user,
                $credits,
                'video',
                fn () => UserVideoCreation::create([
                    'user_id' => $user->id,
                    'mode' => 'trend_template',
                    'endpoint_id' => $submitEndpoint,
                    'model_name' => $template->model_name ?: ($model->name ?? 'MiniMax H3'),
                    'prompt' => $prompt,
                    'input_assets' => $inputAssets,
                    'settings' => [
                        'aspect' => $aspect,
                        'resolution' => $resolution,
                        'duration' => $duration,
                        'audio' => false,
                        'mux_audio_after' => is_string($muxAudioUrl) && $muxAudioUrl !== '',
                        'mux_audio_url' => (is_string($muxAudioUrl) && $muxAudioUrl !== '') ? $muxAudioUrl : null,
                        'catalog_endpoint' => $submitEndpoint,
                        'fal_endpoint' => $submitEndpoint,
                        'credits' => $credits,
                        'credits_source' => 'trend_cost',
                        'from_trend_template_id' => $template->id,
                        'trend_template_slug' => $template->slug,
                        'sheet_endpoint_id' => $template->sheet_endpoint_id,
                        'face_slots' => $orderedPhotos,
                    ],
                    'duration_value' => is_numeric($duration) ? (string) $duration : (string) $duration,
                    'duration_seconds' => is_numeric($duration) ? (int) $duration : null,
                    'aspect_ratio' => $aspect,
                    'resolution' => $resolution,
                    'with_audio' => is_string($muxAudioUrl) && $muxAudioUrl !== '',
                    'credits_charged' => $credits,
                    'status' => UserVideoCreation::STATUS_PENDING,
                    'progress_message' => 'Generating character sheets…',
                ]),
            );
        } catch (InsufficientTokensException $e) {
            throw $e;
        }

        $creation->markInProgress(null, 'Generating character sheets…');
        $this->processor->broadcastSnapshot('video', $creation);

        $template->increment('uses_count');
        TrendTemplate::bustFeedCache();

        $creationId = (int) $creation->id;
        $templateId = (int) $template->id;
        $userId = (int) $user->id;

        // Return immediately so the client can subscribe to websocket progress while
        // character sheets + Seedance submit run after the HTTP response.
        dispatch(function () use (
            $creationId,
            $templateId,
            $userId,
            $orderedPhotos,
            $inputAssets,
            $sketchUrl,
            $submitEndpoint,
            $prompt,
            $aspect,
            $resolution,
            $duration,
            $audio,
            $allowedDurations,
        ): void {
            app(self::class)->runSheetsAndSubmit(
                creationId: $creationId,
                templateId: $templateId,
                userId: $userId,
                orderedPhotos: $orderedPhotos,
                inputAssets: $inputAssets,
                sketchUrl: $sketchUrl,
                submitEndpoint: $submitEndpoint,
                prompt: $prompt,
                aspect: $aspect,
                resolution: $resolution,
                duration: $duration,
                audio: $audio,
                allowedDurations: $allowedDurations,
            );
        })->afterResponse();

        $remakes = $this->trends->userRemakesForTrendTemplate($templateId, $userId);

        return [
            'type' => 'video',
            'creation' => $this->presentVideo($creation->fresh()),
            'user_remake_count' => $remakes['count'],
            'user_latest' => $remakes['latest'],
        ];
    }

    /**
     * @param  list<array{key: string, label: string, role: string, photo_url: string}>  $orderedPhotos
     * @param  list<array<string, mixed>>  $inputAssets
     * @param  list<mixed>|null  $allowedDurations
     */
    public function runSheetsAndSubmit(
        int $creationId,
        int $templateId,
        int $userId,
        array $orderedPhotos,
        array $inputAssets,
        string $sketchUrl,
        string $submitEndpoint,
        string $prompt,
        string $aspect,
        string $resolution,
        mixed $duration,
        bool $audio,
        ?array $allowedDurations,
    ): void {
        $creation = UserVideoCreation::query()->whereKey($creationId)->first();
        $template = TrendTemplate::query()->whereKey($templateId)->first();
        $user = User::query()->whereKey($userId)->first();
        if (! $creation || ! $template || ! $user) {
            return;
        }

        $settingsEarly = is_array($creation->settings) ? $creation->settings : [];
        $muxAudioUrl = $settingsEarly['mux_audio_url'] ?? $template->optionalAudioUrl();
        $muxAudioUrl = is_string($muxAudioUrl) && $muxAudioUrl !== '' ? $muxAudioUrl : null;

        try {
            @set_time_limit(max(120, 90 * count($orderedPhotos) + 60));
            $sheetUrls = [];
            foreach ($orderedPhotos as $index => $photo) {
                $n = $index + 1;
                $creation->forceFill([
                    'progress_message' => "Character sheet {$n}/".count($orderedPhotos).'…',
                ])->save();
                $this->processor->broadcastSnapshot('video', $creation->fresh());

                $sheetUrls[] = $this->generateCharacterSheet($template, $photo['photo_url']);
            }
        } catch (Throwable $e) {
            report($e);
            $creation->markFailed(
                $e->getMessage() !== '' ? $e->getMessage() : 'Character sheet generation failed.',
                'sheet_error',
            );
            $this->tokens->refund($user, $creation, 'video', 'sheet_failed');
            $this->processor->broadcastSnapshot('video', $creation->fresh());

            return;
        }

        // Partner models often reject rimboai /storage URLs and moov-at-end MP4s.
        // Always rehost sheets + a normalized faststart motion sketch to fal CDN.
        // H3 hard-caps reference video at 15s — trim sketch (+ mux audio) from the start if longer.
        try {
            $creation->forceFill([
                'progress_message' => 'Preparing motion reference…',
            ])->save();
            $this->processor->broadcastSnapshot('video', $creation->fresh());

            // Stay under fal's hard 15.0s ceiling; CDN re-encode can add ~0.1s.
            $maxRefSeconds = 14.5;
            $trimDir = 'trend-remakes/'.((int) $user->id).'/trim';
            if (str_contains(strtolower($submitEndpoint), 'minimax/h3')) {
                $sketchTrim = $this->mux->ensureMaxDurationPublicUrl(
                    $sketchUrl,
                    $maxRefSeconds,
                    $trimDir,
                    'creation-'.$creation->id.'-sketch-15s.mp4',
                    'video',
                );
                $sketchUrl = $sketchTrim['url'];
                if ($sketchTrim['trimmed']) {
                    $settingsEarly['motion_sketch_trimmed'] = true;
                    $settingsEarly['motion_sketch_original_seconds'] = $sketchTrim['original_seconds'];
                    $creation->forceFill([
                        'progress_message' => 'Trimmed motion sketch to 15s…',
                        'settings' => $settingsEarly,
                    ])->save();
                    $this->processor->broadcastSnapshot('video', $creation->fresh());
                }

                if ($muxAudioUrl !== null) {
                    $audioTrim = $this->mux->ensureMaxDurationPublicUrl(
                        $muxAudioUrl,
                        $maxRefSeconds,
                        $trimDir,
                        'creation-'.$creation->id.'-audio-15s.mp3',
                        'audio',
                    );
                    $muxAudioUrl = $audioTrim['url'];
                    $settingsEarly['mux_audio_url'] = $muxAudioUrl;
                    $settingsEarly['mux_audio_after'] = true;
                    if ($audioTrim['trimmed']) {
                        $settingsEarly['mux_audio_trimmed'] = true;
                        $settingsEarly['mux_audio_original_seconds'] = $audioTrim['original_seconds'];
                    }
                    $creation->forceFill(['settings' => $settingsEarly])->save();
                }

                // Output duration must match the (possibly trimmed) reference window.
                if (is_numeric($duration) && (float) $duration > 15) {
                    $duration = '15';
                }
            }

            $creation->forceFill([
                'progress_message' => 'Uploading motion reference…',
            ])->save();
            $this->processor->broadcastSnapshot('video', $creation->fresh());

            $sketchUrl = $this->fal->ensureInferenceVideoUrl($sketchUrl, 'motion-sketch.mp4');

            // Partner-safe re-encode can nudge duration over 15.0 — trim the CDN file if needed.
            if (str_contains(strtolower($submitEndpoint), 'minimax/h3')) {
                $cdnSeconds = $this->mux->probeUrlDurationSeconds($sketchUrl);
                if ($cdnSeconds !== null && $cdnSeconds > 15.0) {
                    $cdnTrim = $this->mux->ensureMaxDurationPublicUrl(
                        $sketchUrl,
                        $maxRefSeconds,
                        $trimDir,
                        'creation-'.$creation->id.'-sketch-cdn-15s.mp4',
                        'video',
                    );
                    $sketchUrl = $this->fal->ensureInferenceVideoUrl($cdnTrim['url'], 'motion-sketch.mp4');
                    $settingsEarly['motion_sketch_cdn_retried'] = true;
                    $settingsEarly['motion_sketch_cdn_seconds_before'] = $cdnSeconds;
                    $creation->forceFill(['settings' => $settingsEarly])->save();
                }
            }

            $sheetUrls = array_map(
                fn (string $url): string => $this->fal->ensureCdnUrl($url),
                $sheetUrls,
            );
        } catch (Throwable $e) {
            report($e);
            $creation->markFailed(
                $e->getMessage() !== '' ? $e->getMessage() : 'Failed to prepare media for generation.',
                'media_rehost_error',
            );
            $this->tokens->refund($user, $creation, 'video', 'media_rehost_failed');
            $this->processor->broadcastSnapshot('video', $creation->fresh());

            return;
        }

        // Kapwing-style: video-only to the model; song is muxed after webhook success.
        $prompt = $this->withoutAudioReferencePrompt($prompt);

        $built = $this->videoInput->build($submitEndpoint, [
            'prompt' => $prompt,
            'aspect' => $aspect,
            'resolution' => $resolution,
            'duration' => $duration,
            'audio' => false,
            'allowed_durations' => $allowedDurations,
            'mode' => 'reference-to-video',
            'image_urls' => $sheetUrls,
            'video_urls' => [$sketchUrl],
            'audio_urls' => [],
            'enable_prompt_expansion' => false,
        ]);

        $falInput = $built['input'];
        $billing = $this->pricing->resolve($submitEndpoint);
        $cost = $billing
            ? $this->videoCost->estimate([
                'endpoint_id' => $submitEndpoint,
                'unit' => $billing['unit'],
                'unit_price' => $billing['unit_price'],
                'duration_seconds' => $built['duration_seconds'],
                'audio' => $built['with_audio'],
                'resolution' => $built['resolution'] ?? $resolution,
                'aspect' => $built['aspect_ratio'] ?? $aspect,
                'reference_video_seconds' => $built['duration_seconds'],
                'reference_image_count' => count($sheetUrls),
            ])
            : [
                'fal_cost_usd' => (float) ($template->fal_estimate_usd ?? 0),
                'breakdown' => ['mode' => 'template_estimate_fallback'],
            ];

        $sheetMeta = [];
        foreach ($orderedPhotos as $i => $photo) {
            $sheetMeta[] = [
                'slot_key' => $photo['key'],
                'role' => $photo['role'],
                'photo_url' => $photo['photo_url'],
                'sheet_url' => $sheetUrls[$i] ?? null,
                'image_tag' => '@Image'.($i + 1),
            ];
        }

        $settings = is_array($creation->settings) ? $creation->settings : [];
        $settings = array_merge($settings, [
            'aspect' => $built['aspect_ratio'],
            'resolution' => $built['resolution'],
            'duration' => $duration ?? $built['duration_value'],
            'audio' => false,
            'mux_audio_after' => $muxAudioUrl !== null,
            'mux_audio_url' => $muxAudioUrl,
            'fal_input' => $falInput,
            'fal_endpoint' => $submitEndpoint,
            'billing_endpoint' => $billing['endpoint_id'] ?? $submitEndpoint,
            'billing_source' => $billing['source'] ?? null,
            'billing_unit' => $billing['unit'] ?? null,
            'billing_unit_price' => $billing['unit_price'] ?? null,
            'fal_cost_usd' => $cost['fal_cost_usd'],
            'cost_breakdown' => $cost['breakdown'],
            'character_sheets' => $sheetMeta,
            'media_counts' => [
                'images' => count($sheetUrls),
                'videos' => 1,
                'audios' => 0,
            ],
        ]);

        $mergedAssets = array_merge($inputAssets, array_map(
            fn (string $url, int $i) => [
                'url' => $url,
                'fal_url' => $url,
                'type' => 'image',
                'role' => 'character_sheet',
                'slot_key' => $orderedPhotos[$i]['key'] ?? null,
            ],
            $sheetUrls,
            array_keys($sheetUrls),
        ));

        $creation->forceFill([
            'input_assets' => $mergedAssets,
            'settings' => $settings,
            'duration_value' => $built['duration_value'],
            'duration_seconds' => $built['duration_seconds'],
            'aspect_ratio' => $built['aspect_ratio'],
            'resolution' => $built['resolution'],
            'with_audio' => $muxAudioUrl !== null,
            'progress_message' => 'Starting video generation…',
        ])->save();
        $this->processor->broadcastSnapshot('video', $creation->fresh());

        try {
            $this->walletCost->recordBalanceBefore($creation);
            $submit = $this->fal->submit($submitEndpoint, $falInput);
        } catch (Throwable $e) {
            report($e);
            $creation->markFailed(__('messages.could_not_start'), 'submit_error');
            $this->tokens->refund($user, $creation, 'video', 'fal_submit_failed');
            $this->processor->broadcastSnapshot('video', $creation->fresh());

            return;
        }

        $creation->markQueued(
            $submit['request_id'] ?? null,
            $submit['status_url'] ?? null,
            $submit['response_url'] ?? null,
        );
        if (isset($submit['queue_position'])) {
            $creation->forceFill(['queue_position' => (int) $submit['queue_position']])->save();
        }
        $this->processor->broadcastSnapshot('video', $creation->fresh());
    }

    /**
     * Strip Audio refs — song is muxed after generation (Kapwing-style), not sent to fal.
     */
    private function withoutAudioReferencePrompt(string $prompt): string
    {
        $prompt = trim($prompt);
        $prompt = preg_replace('/\n*If @Audio1 is provided:.*$/is', '', $prompt) ?? $prompt;
        $prompt = preg_replace('/[^\n]*@Audio\d+[^\n]*\n?/i', '', $prompt) ?? $prompt;

        return trim($prompt);
    }

    private function resolveTemplate(TrendTemplate|string|int $template): TrendTemplate
    {
        if ($template instanceof TrendTemplate) {
            return $template;
        }

        if (is_numeric($template)) {
            $row = TrendTemplate::query()->whereKey((int) $template)->first();
        } else {
            $row = TrendTemplate::query()->where('slug', (string) $template)->first();
        }

        if (! $row) {
            throw new TrendsRemakeException('Template not found or not published.', 404);
        }

        return $row;
    }

    private function generateCharacterSheet(TrendTemplate $template, string $photoUrl): string
    {
        $built = $this->buildCharacterSheet(
            $photoUrl,
            (string) ($template->sheet_endpoint_id ?: TrendTemplate::DEFAULT_SHEET_ENDPOINT),
            (string) ($template->sheet_prompt ?: TrendTemplate::defaultSheetPrompt()),
        );

        return $built['sheet_url'];
    }

    /**
     * Admin / remake shared path: face photo → character sheet (no video, no billing).
     *
     * @return array{sheet_url: string, endpoint_id: string, prompt: string, photo_url: string}
     */
    public function buildCharacterSheet(
        string $photoUrl,
        ?string $sheetEndpoint = null,
        ?string $sheetPrompt = null,
    ): array {
        $sheetEndpoint = trim((string) ($sheetEndpoint ?: TrendTemplate::DEFAULT_SHEET_ENDPOINT))
            ?: TrendTemplate::DEFAULT_SHEET_ENDPOINT;
        $prompt = trim((string) ($sheetPrompt ?: TrendTemplate::defaultSheetPrompt()));
        if ($prompt === '') {
            $prompt = TrendTemplate::defaultSheetPrompt();
        }

        $base = preg_replace('#/edit$#', '', $sheetEndpoint) ?: $sheetEndpoint;
        $submitEndpoint = str_ends_with($sheetEndpoint, '/edit')
            ? $sheetEndpoint
            : $this->imageInput->resolveEndpoint($base, [$photoUrl]);

        $input = $this->imageInput->build($base, [
            'prompt' => $prompt,
            'aspect' => '16:9',
            'resolution' => '1K',
            'quantity' => 1,
            'reference_urls' => [$photoUrl],
        ]);

        // build() may omit image_urls when base lacks REFERENCE_CAPABLE; force for edit routes.
        if (empty($input['image_urls']) && empty($input['image_url'])) {
            $input['image_urls'] = [$photoUrl];
        }

        @set_time_limit(200);
        $result = $this->fal->submitAndWait($submitEndpoint, $input, 180);
        $url = $this->firstImageUrl($result);
        if (! is_string($url) || $url === '') {
            throw new TrendsRemakeException('Character sheet model returned no image.', 502);
        }

        return [
            'sheet_url' => $url,
            'endpoint_id' => $submitEndpoint,
            'prompt' => $prompt,
            'photo_url' => $photoUrl,
        ];
    }

    /**
     * @param  array<string, mixed>  $result
     */
    private function firstImageUrl(array $result): ?string
    {
        $images = $result['images'] ?? null;
        if (is_array($images) && $images !== []) {
            $first = $images[0];
            if (is_array($first) && is_string($first['url'] ?? null)) {
                return $first['url'];
            }
            if (is_string($first)) {
                return $first;
            }
        }

        $image = $result['image'] ?? null;
        if (is_array($image) && is_string($image['url'] ?? null)) {
            return $image['url'];
        }
        if (is_string($image)) {
            return $image;
        }

        return null;
    }

    private function resolveVideoModel(string $endpointId): ?object
    {
        foreach (['text_to_video_models', 'image_to_video_models'] as $table) {
            $row = DB::table($table)
                ->where('status', 'active')
                ->where('endpoint_id', $endpointId)
                ->first();
            if ($row) {
                return $row;
            }
        }

        return null;
    }

    /**
     * @return array<string, mixed>
     */
    private function presentVideo(UserVideoCreation $creation): array
    {
        $settings = is_array($creation->settings) ? $creation->settings : [];

        return [
            'id' => $creation->id,
            'status' => $creation->status,
            'queue_position' => $creation->queue_position,
            'progress_message' => $creation->progress_message,
            'progress_percent' => LabCreationPresenter::progressPercent($creation),
            'prompt' => $creation->prompt,
            'model_name' => $creation->model_name,
            'video_url' => $creation->result_video_url,
            'thumbnail_url' => $creation->thumbnail_url,
            'preview_url' => $creation->result_preview_url ?: $creation->result_video_url,
            'aspect' => $creation->aspect_ratio,
            'resolution' => $creation->resolution,
            'duration' => $creation->duration_value,
            'audio' => (bool) $creation->with_audio,
            'error' => $creation->error_message,
            'credits' => $settings['credits'] ?? $creation->credits_charged,
            'token_balance' => (int) (auth()->user()?->fresh()->tokens ?? 0),
            'mode' => $creation->mode,
            'created_at' => optional($creation->created_at)->toIso8601String(),
        ];
    }
}
