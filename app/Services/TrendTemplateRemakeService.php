<?php

namespace App\Services;

use App\Exceptions\InsufficientTokensException;
use App\Exceptions\TrendsRemakeException;
use App\Jobs\PollHiggsfieldCreationJob;
use App\Jobs\ProcessTrendH3SplitJob;
use App\Models\TrendTemplate;
use App\Models\User;
use App\Models\UserVideoCreation;
use App\Services\Credits\VideoGenerationCostEstimator;
use App\Services\Tokens\TokenService;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Curated Trend Template remake:
 * - Higgsfield Genjutsu: slot photos as image refs (no sheets)
 * - MiniMax H3 direct: product/packshot image + locked motion video (Lab-style R2V)
 * - MiniMax H3 split: scene-cut sections + FlashVSR + song mux (no sheets)
 * - Other fal R2V: face photos → character sheets → video
 */
class TrendTemplateRemakeService
{
    public function __construct(
        private FalService $fal,
        private HiggsfieldService $higgsfield,
        private FalVideoInputBuilder $videoInput,
        private FalImageInputBuilder $imageInput,
        private FalPricingService $pricing,
        private VideoGenerationCostEstimator $videoCost,
        private TokenService $tokens,
        private FalWalletCostTracker $walletCost,
        private FalWebhookProcessor $processor,
        private TrendsFeedService $trends,
    ) {}

    /**
     * @param  array<string, string>  $slotPhotos  slot key => image URL
     * @return array<string, mixed>
     */
    public function remake(
        User $user,
        TrendTemplate|string|int $template,
        array $slotPhotos,
        ?string $clientPrompt = null,
    ): array {
        $template = $this->resolveTemplate($template);
        if (! $template->is_published) {
            throw new TrendsRemakeException('Template not found or not published.', 404);
        }

        $endpointIdPreview = trim((string) $template->endpoint_id) ?: TrendTemplate::DEFAULT_ENDPOINT;
        if (TrendTemplate::isCharacterSheetEndpoint($endpointIdPreview)) {
            return $this->remakeCharacterSheet($user, $template, $slotPhotos);
        }

        $needsFal = ! HiggsfieldService::isHiggsfieldEndpoint($endpointIdPreview);
        if ($needsFal && ! $this->fal->configured()) {
            throw new TrendsRemakeException('Generation service is not configured.', 503);
        }
        if (! $needsFal && ! $this->higgsfield->configured()) {
            throw new TrendsRemakeException('Higgsfield is not configured.', 503);
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
            throw new TrendsRemakeException('Template has no photo upload slots.', 422);
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
            throw new TrendsRemakeException('Upload at least one photo.', 422);
        }

        $prompt = $this->resolveClientPrompt($template, $clientPrompt);
        $endpointId = trim((string) $template->endpoint_id) ?: TrendTemplate::DEFAULT_ENDPOINT;
        $isHiggsfield = HiggsfieldService::isHiggsfieldEndpoint($endpointId);
        $isH3Direct = $template->isH3DirectWorkflow()
            && str_contains(strtolower($endpointId), 'minimax/h3');
        $isH3Split = self::isH3SplitEndpoint($endpointId) && ! $isH3Direct;

        if ($isHiggsfield) {
            if (! $this->higgsfield->configured()) {
                throw new TrendsRemakeException('Higgsfield is not configured.', 503);
            }
            if ($prompt === '') {
                throw new TrendsRemakeException('Template prompt is invalid.', 422);
            }
        } elseif ($isH3Direct || $isH3Split) {
            if (! $this->fal->configured()) {
                throw new TrendsRemakeException('Generation service is not configured.', 503);
            }
            if ($prompt === '') {
                throw new TrendsRemakeException('Add a prompt before creating.', 422);
            }
            if ($isH3Direct && ! str_contains($prompt, '@Image') && ! str_contains($prompt, 'Image 1')) {
                throw new TrendsRemakeException('Prompt must mention @Image1 (your product photo).', 422);
            }
        } elseif ($prompt === '' || ! str_contains($prompt, '@Video1')) {
            throw new TrendsRemakeException('Template prompt is invalid.', 422);
        }

        $model = $this->resolveVideoModel($endpointId);
        if (! $model && ! $isHiggsfield && ! $isH3Split && ! $isH3Direct) {
            $endpointId = TrendTemplate::FALLBACK_ENDPOINT;
            $model = $this->resolveVideoModel($endpointId);
        }
        if (! $model && ! $isHiggsfield && ! $isH3Split && ! $isH3Direct) {
            $endpointId = TrendTemplate::SECONDARY_FALLBACK_ENDPOINT;
            $model = $this->resolveVideoModel($endpointId);
            $isH3Direct = $template->isH3DirectWorkflow();
            $isH3Split = self::isH3SplitEndpoint($endpointId) && ! $isH3Direct;
        }
        if (! $model) {
            throw new TrendsRemakeException(__('messages.model_unavailable'), 422);
        }
        $submitEndpoint = (string) $model->endpoint_id;
        $isHiggsfield = HiggsfieldService::isHiggsfieldEndpoint($submitEndpoint);
        $isH3Direct = $isH3Direct || (
            $template->isH3DirectWorkflow()
            && str_contains(strtolower($submitEndpoint), 'minimax/h3')
        );
        $isH3Split = self::isH3SplitEndpoint($submitEndpoint) && ! $isH3Direct;

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

        $optionalAudio = $template->optionalAudioUrl();
        if (is_string($optionalAudio) && $optionalAudio !== '') {
            $inputAssets[] = [
                'url' => $optionalAudio,
                'fal_url' => $optionalAudio,
                'type' => 'audio',
                'role' => 'locked_audio',
            ];
        }

        $progressStart = $isHiggsfield
            ? 'Preparing references…'
            : ($isH3Direct
                ? 'Preparing product remake…'
                : ($isH3Split ? 'Planning H3 sections…' : 'Generating character sheets…'));

        $workflow = $isH3Direct
            ? TrendTemplate::WORKFLOW_H3_DIRECT
            : ($isH3Split ? 'h3_split' : ($isHiggsfield ? 'higgsfield_genjutsu' : 'fal_r2v'));

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
                    'provider' => $isHiggsfield ? 'higgsfield' : 'fal',
                    'model_name' => $template->model_name ?: ($model->name ?? 'Trend Video'),
                    'prompt' => $prompt,
                    'input_assets' => $inputAssets,
                    'settings' => [
                        'aspect' => $aspect,
                        'resolution' => $resolution,
                        'duration' => $duration,
                        'audio' => $isH3Split || $isH3Direct,
                        'provider' => $isHiggsfield ? 'higgsfield' : 'fal',
                        'workflow' => $workflow,
                        'catalog_endpoint' => $submitEndpoint,
                        'fal_endpoint' => $isHiggsfield ? null : $submitEndpoint,
                        'higgsfield_model' => $isHiggsfield ? $submitEndpoint : null,
                        'credits' => $credits,
                        'credits_source' => 'trend_cost',
                        'from_trend_template_id' => $template->id,
                        'trend_template_slug' => $template->slug,
                        'sheet_endpoint_id' => ($isHiggsfield || $isH3Split || $isH3Direct) ? null : $template->sheet_endpoint_id,
                        'skip_character_sheets' => $isHiggsfield || $isH3Split || $isH3Direct,
                        'face_slots' => $orderedPhotos,
                        'prompt_editable' => $template->isPromptEditable(),
                        'h3_split' => $isH3Split ? [
                            'phase' => 'prepare',
                            'sketch_url' => $sketchUrl,
                            'audio_url' => is_string($optionalAudio) ? $optionalAudio : '',
                            'image_urls' => array_map(fn (array $p) => $p['photo_url'], $orderedPhotos),
                            'prompt' => $prompt,
                            'aspect' => $aspect,
                            'index' => 0,
                            'sections' => [],
                        ] : null,
                    ],
                    'duration_value' => is_numeric($duration) ? (string) $duration : (string) $duration,
                    'duration_seconds' => is_numeric($duration) ? (int) $duration : null,
                    'aspect_ratio' => $aspect,
                    'resolution' => ($isH3Split || $isH3Direct) ? '768P' : $resolution,
                    'with_audio' => $isH3Split || $isH3Direct,
                    'credits_charged' => $credits,
                    'status' => UserVideoCreation::STATUS_PENDING,
                    'progress_message' => $progressStart,
                ]),
            );
        } catch (InsufficientTokensException $e) {
            throw $e;
        }

        $creation->markInProgress(null, $progressStart);
        $this->processor->broadcastSnapshot('video', $creation);

        $template->increment('uses_count');
        TrendTemplate::bustFeedCache();

        $creationId = (int) $creation->id;
        $templateId = (int) $template->id;
        $userId = (int) $user->id;

        if ($isH3Split) {
            ProcessTrendH3SplitJob::dispatch($creationId)
                ->onConnection('database')
                ->delay(now()->addSeconds(2));
        } elseif ($isH3Direct) {
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
                $allowedDurations,
            ): void {
                app(self::class)->runH3DirectSubmit(
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
                    allowedDurations: $allowedDurations,
                );
            })->afterResponse();
        } else {
            // Return immediately so the client can subscribe to websocket progress while
            // media prep + provider submit run after the HTTP response.
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
        }

        $remakes = $this->trends->userRemakesForTrendTemplate($templateId, $userId);

        return [
            'type' => 'video',
            'creation' => $this->presentVideo($creation->fresh()),
            'user_remake_count' => $remakes['count'],
            'user_latest' => $remakes['latest'],
        ];
    }

    public static function isH3SplitEndpoint(?string $endpointId): bool
    {
        $id = strtolower(trim((string) $endpointId));

        return $id !== ''
            && str_contains($id, 'minimax/h3')
            && str_contains($id, 'reference-to-video');
    }

    private function resolveClientPrompt(TrendTemplate $template, ?string $clientPrompt): string
    {
        $fallback = trim((string) $template->prompt);
        if (! $template->isPromptEditable()) {
            return $fallback;
        }

        $override = trim((string) ($clientPrompt ?? ''));
        if ($override === '') {
            return $fallback;
        }

        // Soft guard — Lab allows long commercial briefs.
        if (mb_strlen($override) > 100000) {
            throw new TrendsRemakeException('Prompt is too long.', 422);
        }

        return $override;
    }

    /**
     * Product / packshot MiniMax H3 R2V — same path as Video Lab (no section split).
     *
     * @param  list<array{key: string, label: string, role: string, photo_url: string}>  $orderedPhotos
     * @param  list<array<string, mixed>>  $inputAssets
     * @param  list<int|string>|null  $allowedDurations
     */
    public function runH3DirectSubmit(
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
        ?array $allowedDurations,
    ): void {
        $creation = UserVideoCreation::query()->whereKey($creationId)->first();
        $template = TrendTemplate::query()->whereKey($templateId)->first();
        $user = User::query()->whereKey($userId)->first();
        if (! $creation || ! $template || ! $user) {
            return;
        }

        $imageUrls = array_values(array_map(
            static fn (array $p): string => $p['photo_url'],
            $orderedPhotos,
        ));

        try {
            $creation->forceFill(['progress_message' => 'Uploading references…'])->save();
            $this->processor->broadcastSnapshot('video', $creation->fresh());

            $sketchUrl = $this->fal->ensureInferenceVideoUrl($sketchUrl, 'motion-ref.mp4');
            $imageUrls = array_map(
                fn (string $url): string => $this->fal->ensureCdnUrl($url),
                $imageUrls,
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

        $built = $this->videoInput->build($submitEndpoint, [
            'prompt' => $prompt,
            'aspect' => $aspect,
            'resolution' => $resolution !== '' ? $resolution : '768p',
            'duration' => $duration,
            'audio' => true,
            'allowed_durations' => $allowedDurations,
            'mode' => 'reference-to-video',
            'image_urls' => $imageUrls,
            'video_urls' => [$sketchUrl],
            'audio_urls' => [],
            'prompt_expansion_mode' => 'disabled',
            'enable_safety_checker' => true,
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
                'reference_image_count' => count($imageUrls),
            ])
            : [
                'fal_cost_usd' => (float) ($template->fal_estimate_usd ?? 0),
                'breakdown' => ['mode' => 'template_estimate_fallback'],
            ];

        $settings = is_array($creation->settings) ? $creation->settings : [];
        $settings = array_merge($settings, [
            'aspect' => $built['aspect_ratio'],
            'resolution' => $built['resolution'],
            'duration' => $duration ?? $built['duration_value'],
            'audio' => true,
            'provider' => 'fal',
            'workflow' => TrendTemplate::WORKFLOW_H3_DIRECT,
            'fal_input' => $falInput,
            'fal_endpoint' => $submitEndpoint,
            'billing_endpoint' => $billing['endpoint_id'] ?? $submitEndpoint,
            'billing_source' => $billing['source'] ?? null,
            'billing_unit' => $billing['unit'] ?? null,
            'billing_unit_price' => $billing['unit_price'] ?? null,
            'fal_cost_usd' => $cost['fal_cost_usd'],
            'cost_breakdown' => $cost['breakdown'],
            'media_counts' => [
                'images' => count($imageUrls),
                'videos' => 1,
                'audios' => 0,
            ],
        ]);

        $creation->forceFill([
            'provider' => 'fal',
            'input_assets' => $inputAssets,
            'settings' => $settings,
            'duration_value' => $built['duration_value'],
            'duration_seconds' => $built['duration_seconds'],
            'aspect_ratio' => $built['aspect_ratio'],
            'resolution' => $built['resolution'],
            'with_audio' => true,
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
        $creation->forceFill(['progress_message' => 'Queued…'])->save();
        $this->processor->broadcastSnapshot('video', $creation->fresh());
    }

    /**
     * Character-sheet-only Trends remake (Nano Banana Pro).
     *
     * @param  array<string, string>  $slotPhotos
     * @return array<string, mixed>
     */
    private function remakeCharacterSheet(User $user, TrendTemplate $template, array $slotPhotos): array
    {
        if (! $this->fal->configured()) {
            throw new TrendsRemakeException('Generation service is not configured.', 503);
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

        $clientSlots = $template->clientSlots();
        if ($clientSlots === []) {
            throw new TrendsRemakeException('Template has no photo upload slots.', 422);
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
            throw new TrendsRemakeException('Upload at least one photo.', 422);
        }

        $credits = (int) $template->trend_cost;
        if ($credits <= 0) {
            throw new TrendsRemakeException(
                'This template has no Trends token price (trend_cost). Set it in Filament.',
                422,
            );
        }

        $sheetEndpoint = trim((string) ($template->sheet_endpoint_id ?: TrendTemplate::DEFAULT_SHEET_ENDPOINT))
            ?: TrendTemplate::DEFAULT_SHEET_ENDPOINT;
        if (! TrendTemplate::isCharacterSheetEndpoint($sheetEndpoint)) {
            $sheetEndpoint = TrendTemplate::DEFAULT_SHEET_ENDPOINT;
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

        try {
            /** @var UserVideoCreation $creation */
            $creation = $this->tokens->reserve(
                $user,
                $credits,
                'video',
                fn () => UserVideoCreation::create([
                    'user_id' => $user->id,
                    'mode' => 'trend_character_sheet',
                    'endpoint_id' => $sheetEndpoint,
                    'provider' => 'fal',
                    'model_name' => $template->model_name ?: 'Nano Banana Pro Character Sheet',
                    'prompt' => (string) ($template->sheet_prompt ?: TrendTemplate::defaultSheetPrompt()),
                    'input_assets' => $inputAssets,
                    'settings' => [
                        'aspect' => '16:9',
                        'resolution' => '1K',
                        'provider' => 'fal',
                        'workflow' => 'character_sheet',
                        'output_kind' => 'character_sheet',
                        'catalog_endpoint' => $sheetEndpoint,
                        'fal_endpoint' => $sheetEndpoint,
                        'sheet_endpoint_id' => $sheetEndpoint,
                        'credits' => $credits,
                        'credits_source' => 'trend_cost',
                        'from_trend_template_id' => $template->id,
                        'trend_template_slug' => $template->slug,
                        'face_slots' => $orderedPhotos,
                    ],
                    'aspect_ratio' => '16:9',
                    'resolution' => '1K',
                    'with_audio' => false,
                    'credits_charged' => $credits,
                    'status' => UserVideoCreation::STATUS_PENDING,
                    'progress_message' => 'Generating character sheet…',
                ]),
            );
        } catch (InsufficientTokensException $e) {
            throw $e;
        }

        $creation->markInProgress(null, 'Generating character sheet…');
        $this->processor->broadcastSnapshot('video', $creation);

        $template->increment('uses_count');
        TrendTemplate::bustFeedCache();

        $creationId = (int) $creation->id;
        $templateId = (int) $template->id;
        $userId = (int) $user->id;
        $photos = $orderedPhotos;
        $endpoint = $sheetEndpoint;
        $sheetPrompt = (string) ($template->sheet_prompt ?: TrendTemplate::defaultSheetPrompt());

        dispatch(function () use ($creationId, $templateId, $userId, $photos, $endpoint, $sheetPrompt) {
            $creation = UserVideoCreation::query()->find($creationId);
            $user = User::query()->find($userId);
            if (! $creation || ! $user) {
                return;
            }
            if (method_exists($creation, 'isTerminal') && $creation->isTerminal()) {
                return;
            }

            try {
                $sheetUrls = [];
                $sheetMeta = [];
                foreach ($photos as $i => $photo) {
                    $creation->forceFill([
                        'progress_message' => 'Generating character sheet '
                            .(count($photos) > 1 ? ($i + 1).'/'.count($photos).'…' : '…'),
                    ])->save();
                    $this->processor->broadcastSnapshot('video', $creation->fresh());

                    $built = $this->buildCharacterSheet(
                        $photo['photo_url'],
                        $endpoint,
                        $sheetPrompt,
                    );
                    $sheetUrls[] = $built['sheet_url'];
                    $sheetMeta[] = [
                        'slot_key' => $photo['key'],
                        'role' => $photo['role'],
                        'photo_url' => $photo['photo_url'],
                        'sheet_url' => $built['sheet_url'],
                        'image_tag' => '@Image'.($i + 1),
                    ];
                }

                $assets = array_map(
                    fn (string $url) => ['url' => $url, 'content_type' => 'image/png'],
                    $sheetUrls,
                );
                $settings = is_array($creation->settings) ? $creation->settings : [];
                $settings['character_sheets'] = $sheetMeta;
                $settings['workflow'] = 'character_sheet';
                $settings['output_kind'] = 'character_sheet';
                $settings['fal_cost_usd'] = $settings['fal_cost_usd'] ?? null;

                $creation->forceFill([
                    'status' => UserVideoCreation::STATUS_COMPLETED,
                    'result_assets' => $assets,
                    'result_preview_url' => $sheetUrls[0] ?? null,
                    'result_video_url' => null,
                    'thumbnail_url' => $sheetUrls[0] ?? null,
                    'progress_message' => 'Completed',
                    'queue_position' => null,
                    'completed_at' => now(),
                    'error_message' => null,
                    'error_type' => null,
                    'settings' => $settings,
                    'settled_at' => now(),
                    'cost_usd_is_final' => true,
                    'cost_usd_source' => 'character_sheet_estimate',
                ])->save();

                $this->processor->broadcastSnapshot('video', $creation->fresh());
            } catch (Throwable $e) {
                report($e);
                $creation->markFailed(
                    $e->getMessage() !== '' ? $e->getMessage() : 'Character sheet failed.',
                    'character_sheet_error',
                );
                $this->tokens->refund($user, $creation, 'video', 'character_sheet_error');
                $this->processor->broadcastSnapshot('video', $creation->fresh());
            }
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

        $isHiggsfield = HiggsfieldService::isHiggsfieldEndpoint($submitEndpoint);

        // Higgsfield Genjutsu: use uploaded slot photos directly (no fal character sheets).
        if ($isHiggsfield) {
            $this->runHiggsfieldDirectSubmit(
                creation: $creation,
                user: $user,
                template: $template,
                orderedPhotos: $orderedPhotos,
                inputAssets: $inputAssets,
                sketchUrl: $sketchUrl,
                submitEndpoint: $submitEndpoint,
                prompt: $prompt,
                aspect: $aspect,
                resolution: $resolution,
                duration: $duration,
            );

            return;
        }

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

        // Light path: rehost sheets + motion sketch only (no audio download / mux / trim).
        try {
            $creation->forceFill([
                'progress_message' => 'Uploading motion reference…',
            ])->save();
            $this->processor->broadcastSnapshot('video', $creation->fresh());

            $sketchUrl = $this->fal->ensureInferenceVideoUrl($sketchUrl, 'motion-sketch.mp4');
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

        $prompt = $this->withoutAudioReferencePrompt($prompt);
        $sheetUrls = array_values(array_slice($sheetUrls, 0, 8));

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

        $settings = is_array($creation->settings) ? $creation->settings : [];
        $settings = array_merge($settings, [
            'aspect' => $built['aspect_ratio'],
            'resolution' => $built['resolution'],
            'duration' => $duration ?? $built['duration_value'],
            'audio' => false,
            'provider' => 'fal',
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

        $creation->forceFill([
            'provider' => 'fal',
            'input_assets' => $mergedAssets,
            'settings' => $settings,
            'duration_value' => $built['duration_value'],
            'duration_seconds' => $built['duration_seconds'],
            'aspect_ratio' => $built['aspect_ratio'],
            'resolution' => $built['resolution'],
            'with_audio' => false,
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
     * @param  list<array{key: string, label: string, role: string, photo_url: string}>  $orderedPhotos
     * @param  list<array<string, mixed>>  $inputAssets
     */
    private function runHiggsfieldDirectSubmit(
        UserVideoCreation $creation,
        User $user,
        TrendTemplate $template,
        array $orderedPhotos,
        array $inputAssets,
        string $sketchUrl,
        string $submitEndpoint,
        string $prompt,
        string $aspect,
        string $resolution,
        mixed $duration,
    ): void {
        try {
            @set_time_limit(180);
            $creation->forceFill([
                'progress_message' => 'Preparing references…',
            ])->save();
            $this->processor->broadcastSnapshot('video', $creation->fresh());

            // Prefer fal CDN rehost when available; otherwise use public HTTPS URLs as-is.
            if ($this->fal->configured()) {
                $sketchUrl = $this->fal->ensureInferenceVideoUrl($sketchUrl, 'motion-sketch.mp4');
                $imageUrls = array_map(
                    fn (array $photo): string => $this->fal->ensureCdnUrl($photo['photo_url']),
                    $orderedPhotos,
                );
            } else {
                $imageUrls = array_map(
                    fn (array $photo): string => $photo['photo_url'],
                    $orderedPhotos,
                );
            }
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

        $prompt = $this->withoutAudioReferencePrompt($prompt);
        $imageUrls = array_values(array_slice($imageUrls, 0, 8));

        $refMeta = [];
        foreach ($orderedPhotos as $i => $photo) {
            $refMeta[] = [
                'slot_key' => $photo['key'],
                'role' => $photo['role'],
                'photo_url' => $photo['photo_url'],
                'image_url' => $imageUrls[$i] ?? null,
                'image_tag' => '@Image'.($i + 1),
            ];
        }

        $mergedAssets = array_merge($inputAssets, array_map(
            fn (string $url, int $i) => [
                'url' => $url,
                'fal_url' => $url,
                'type' => 'image',
                'role' => 'reference_image',
                'slot_key' => $orderedPhotos[$i]['key'] ?? null,
            ],
            $imageUrls,
            array_keys($imageUrls),
        ));

        $this->submitHiggsfieldGenjutsu(
            creation: $creation,
            user: $user,
            template: $template,
            submitEndpoint: $submitEndpoint,
            prompt: $prompt,
            aspect: $aspect,
            resolution: $resolution,
            duration: $duration,
            sketchUrl: $sketchUrl,
            sheetUrls: $imageUrls,
            sheetMeta: $refMeta,
            mergedAssets: $mergedAssets,
        );
    }

    /**
     * @param  list<string>  $sheetUrls
     * @param  list<array<string, mixed>>  $sheetMeta
     * @param  list<array<string, mixed>>  $mergedAssets
     */
    private function submitHiggsfieldGenjutsu(
        UserVideoCreation $creation,
        User $user,
        TrendTemplate $template,
        string $submitEndpoint,
        string $prompt,
        string $aspect,
        string $resolution,
        mixed $duration,
        string $sketchUrl,
        array $sheetUrls,
        array $sheetMeta,
        array $mergedAssets,
    ): void {
        $durationSeconds = is_numeric($duration) ? max(1, (int) round((float) $duration)) : 15;
        $cost = HiggsfieldService::estimateGenjutsuUsd($durationSeconds, $resolution);

        $hfInput = [
            'video_url' => $sketchUrl,
            'image_urls' => $sheetUrls,
            'resolution' => in_array(strtolower($resolution), ['480p', '720p', '1080p'], true)
                ? strtolower($resolution)
                : '720p',
        ];
        $cleanPrompt = trim($prompt);
        if ($cleanPrompt !== '') {
            $hfInput['prompt'] = $cleanPrompt;
        }

        $settings = is_array($creation->settings) ? $creation->settings : [];
        $settings = array_merge($settings, [
            'aspect' => $aspect,
            'resolution' => $hfInput['resolution'],
            'duration' => $duration,
            'audio' => false,
            'provider' => 'higgsfield',
            'higgsfield_model' => $submitEndpoint,
            'higgsfield_input' => $hfInput,
            'billing_endpoint' => $submitEndpoint,
            'billing_source' => 'higgsfield_list',
            'billing_unit' => $cost['unit'],
            'billing_unit_price' => $cost['unit_price'],
            'fal_cost_usd' => $cost['fal_cost_usd'],
            'cost_breakdown' => $cost['breakdown'],
            'skip_character_sheets' => true,
            'reference_images' => $sheetMeta,
            'character_sheets' => [],
            'media_counts' => [
                'images' => count($sheetUrls),
                'videos' => 1,
                'audios' => 0,
            ],
        ]);

        $creation->forceFill([
            'provider' => 'higgsfield',
            'endpoint_id' => $submitEndpoint,
            'input_assets' => $mergedAssets,
            'settings' => $settings,
            'duration_value' => is_numeric($duration) ? (string) $duration : (string) $durationSeconds,
            'duration_seconds' => $durationSeconds,
            'aspect_ratio' => $aspect,
            'resolution' => $hfInput['resolution'],
            'with_audio' => false,
            'progress_message' => 'Starting video generation…',
        ])->save();
        $this->processor->broadcastSnapshot('video', $creation->fresh());

        try {
            $submit = $this->higgsfield->submit($submitEndpoint, $hfInput);
        } catch (Throwable $e) {
            report($e);
            $creation->markFailed(__('messages.could_not_start'), 'submit_error');
            $this->tokens->refund($user, $creation, 'video', 'higgsfield_submit_failed');
            $this->processor->broadcastSnapshot('video', $creation->fresh());

            return;
        }

        $creation->markQueued(
            $submit['request_id'] ?? null,
            $submit['status_url'] ?? null,
            $submit['response_url'] ?? null,
        );
        $this->processor->broadcastSnapshot('video', $creation->fresh());

        // HF webhooks are not reliably delivered; poll via database queue + scheduler.
        PollHiggsfieldCreationJob::dispatch((int) $creation->id)
            ->onConnection('database')
            ->delay(now()->addSeconds(8));
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
        if (HiggsfieldService::isHiggsfieldEndpoint($endpointId)) {
            return (object) [
                'endpoint_id' => $endpointId,
                'name' => $endpointId === HiggsfieldService::GENJUTSU_MOTION_TRANSFER
                    ? 'Higgsfield Genjutsu Motion Transfer'
                    : 'Higgsfield',
                'status' => 'active',
                'enums' => null,
            ];
        }

        if (self::isH3SplitEndpoint($endpointId)) {
            foreach (['text_to_video_models', 'image_to_video_models'] as $table) {
                $row = DB::table($table)
                    ->where('status', 'active')
                    ->where('endpoint_id', $endpointId)
                    ->first();
                if ($row) {
                    return $row;
                }
            }

            return (object) [
                'endpoint_id' => $endpointId,
                'name' => 'MiniMax H3 split + audio (Sogni-style)',
                'status' => 'active',
                'enums' => json_encode([5, 6, 7, 8, 9, 10, 11, 12, 13, 14, 15]),
            ];
        }

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
        $images = collect($creation->result_assets ?? [])
            ->pluck('url')
            ->filter(fn ($u) => is_string($u) && $u !== '')
            ->values()
            ->all();
        if (
            $images === []
            && ($settings['output_kind'] ?? null) === 'character_sheet'
            && is_string($creation->result_preview_url)
            && $creation->result_preview_url !== ''
        ) {
            $images = [$creation->result_preview_url];
        }

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
            'images' => $images,
            'aspect' => $creation->aspect_ratio,
            'resolution' => $creation->resolution,
            'duration' => $creation->duration_value,
            'audio' => (bool) $creation->with_audio,
            'error' => $creation->error_message,
            'credits' => $settings['credits'] ?? $creation->credits_charged,
            'token_balance' => (int) (auth()->user()?->fresh()->tokens ?? 0),
            'mode' => $creation->mode,
            'output_kind' => $settings['output_kind'] ?? null,
            'created_at' => optional($creation->created_at)->toIso8601String(),
        ];
    }
}
