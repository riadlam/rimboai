<?php

namespace App\Services;

use App\Exceptions\InsufficientTokensException;
use App\Exceptions\TrendsRemakeException;
use App\Models\User;
use App\Models\UserImageCreation;
use App\Models\UserMusicCreation;
use App\Models\UserVideoCreation;
use App\Services\Credits\ImageGenerationCostEstimator;
use App\Services\Credits\MusicGenerationCostEstimator;
use App\Services\Credits\VideoGenerationCostEstimator;
use App\Services\Tokens\TokenService;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Remake a public Trends template: clone recipe/settings, swap only uploaded references.
 */
class TrendsRemakeService
{
    public function __construct(
        private TrendsFeedService $trends,
        private FalService $fal,
        private FalVideoInputBuilder $videoInput,
        private FalImageInputBuilder $imageInput,
        private FalMusicInputBuilder $musicInput,
        private VideoModelCapabilities $videoCaps,
        private VideoGenerationCostEstimator $videoCost,
        private ImageGenerationCostEstimator $imageCost,
        private MusicGenerationCostEstimator $musicCost,
        private FalPricingService $pricing,
        private AssetPromptReferences $promptReferences,
        private TokenService $tokens,
        private FalWalletCostTracker $walletCost,
        private FalWebhookProcessor $processor,
    ) {}

    /**
     * @param  array{
     *   image_urls?: list<string>,
     *   video_urls?: list<string>,
     *   audio_urls?: list<string>,
     * }  $media
     * @return array<string, mixed>
     */
    public function remake(User $user, string $type, int $id, array $media): array
    {
        if (! $this->fal->configured()) {
            throw new TrendsRemakeException('Generation service is not configured.', 503);
        }

        $template = $this->trends->findPublicCompleted($type, $id);
        if (! $template) {
            throw new TrendsRemakeException('Template not found or not public.', 404);
        }

        $mode = (string) ($template->mode ?? '');
        if (str_starts_with($mode, 'tool:')) {
            throw new TrendsRemakeException('Tool templates cannot be remade from Trends.', 422);
        }

        $expected = $this->expectedMediaCounts($template);
        $imageUrls = $this->cleanUrls($media['image_urls'] ?? []);
        $videoUrls = $this->cleanUrls($media['video_urls'] ?? []);
        $audioUrls = $this->cleanUrls($media['audio_urls'] ?? []);

        $this->assertMediaCounts($expected, [
            'image' => count($imageUrls),
            'video' => count($videoUrls),
            'audio' => count($audioUrls),
        ]);

        $result = match ($type) {
            'video' => $this->remakeVideo($user, $template, $imageUrls, $videoUrls, $audioUrls),
            'image' => $this->remakeImage($user, $template, $imageUrls),
            'music' => $this->remakeMusic($user, $template, $audioUrls[0] ?? null),
            default => throw new TrendsRemakeException('Unsupported template type.', 422),
        };

        $template->increment('uses_count');

        return $result;
    }

    /**
     * @param  list<string>  $imageUrls
     * @param  list<string>  $videoUrls
     * @param  list<string>  $audioUrls
     * @return array<string, mixed>
     */
    private function remakeVideo(
        User $user,
        Model $template,
        array $imageUrls,
        array $videoUrls,
        array $audioUrls,
    ): array {
        /** @var UserVideoCreation $template */
        $settings = is_array($template->settings) ? $template->settings : [];
        $prompt = trim((string) ($template->prompt ?? ''));
        if ($prompt === '') {
            throw new TrendsRemakeException('Template prompt is missing.', 422);
        }

        $catalogEndpoint = (string) ($settings['catalog_endpoint'] ?? $template->endpoint_id ?? '');
        $model = $this->resolveVideoModel($catalogEndpoint, (string) ($template->endpoint_id ?? ''));
        if (! $model) {
            throw new TrendsRemakeException(__('messages.model_unavailable'), 422);
        }

        $counts = [
            'images' => count($imageUrls),
            'videos' => count($videoUrls),
            'audios' => count($audioUrls),
        ];
        $frameMode = (($settings['frame_mode'] ?? null) === 'first_last') ? 'first_last' : 'default';

        if (! $this->videoCaps->supportsMediaMix($model->endpoint_id, $counts, $frameMode)) {
            throw new TrendsRemakeException(
                'Uploaded media does not match what this template expects.',
                422,
            );
        }

        $route = $this->videoCaps->resolveRoute($model->endpoint_id, $counts, $frameMode);
        if ($route === null) {
            throw new TrendsRemakeException('Could not resolve a generation route for this template.', 422);
        }

        // Resolve from catalog + media mix (same as Lab). Prefer template fal_endpoint
        // only when it matches the resolved mode family (avoids stale T2V endpoint on I2V remakes).
        $submitEndpoint = $route['endpoint_id'];
        $storedFal = (string) ($settings['fal_endpoint'] ?? '');
        if (
            $storedFal !== ''
            && str_contains($storedFal, match ($route['mode']) {
                'image-to-video' => 'image-to-video',
                'reference-to-video' => 'reference-to-video',
                'first-last-frame-to-video' => 'image-to-video',
                default => 'text-to-video',
            })
        ) {
            $submitEndpoint = $storedFal;
        }
        $mode = (string) ($route['mode'] ?: $template->mode ?: 'text-to-video');

        $allowedDurations = null;
        if (! empty($model->enums)) {
            $decoded = is_string($model->enums) ? json_decode($model->enums, true) : $model->enums;
            $allowedDurations = is_array($decoded) ? $decoded : null;
        }

        $aspect = (string) ($template->aspect_ratio ?? $settings['aspect'] ?? '16:9');
        $resolution = (string) ($template->resolution ?? $settings['resolution'] ?? '720p');
        $duration = $template->duration_value ?? ($settings['duration'] ?? $template->duration_seconds);
        $audio = array_key_exists('audio', $settings)
            ? (bool) $settings['audio']
            : (bool) ($template->with_audio ?? true);
        $speed = (string) ($settings['speed'] ?? 'pro');

        $providerPrompt = $this->promptReferences->resolve($prompt, [
            'image' => count($imageUrls),
            'video' => count($videoUrls),
            'audio' => count($audioUrls),
        ]);

        $built = $this->videoInput->build($submitEndpoint, [
            'prompt' => $providerPrompt,
            'aspect' => $aspect,
            'resolution' => $resolution,
            'duration' => $duration,
            'audio' => $audio,
            'allowed_durations' => $allowedDurations,
            'mode' => $mode,
            'image_urls' => $imageUrls,
            'video_urls' => $videoUrls,
            'audio_urls' => $audioUrls,
            'first_frame_param' => $route['first_frame_param'],
            'last_frame_param' => $route['last_frame_param'] ?? null,
            'negative_prompt' => (string) ($template->negative_prompt ?? ''),
            'enable_prompt_expansion' => false,
        ]);

        $falInput = $built['input'];
        $billing = $this->pricing->resolve($submitEndpoint);
        if ($billing === null) {
            throw new TrendsRemakeException(__('messages.model_unavailable'), 503);
        }

        $cost = $this->videoCost->estimate([
            'endpoint_id' => $submitEndpoint,
            'unit' => $billing['unit'],
            'unit_price' => $billing['unit_price'],
            'duration_seconds' => $built['duration_seconds'],
            'audio' => $built['with_audio'],
            'resolution' => $built['resolution'] ?? $resolution,
            'aspect' => $built['aspect_ratio'] ?? $aspect,
        ]);

        $credits = $this->creditsForTemplate($template, (int) $cost['credits']);
        if ($credits <= 0) {
            throw new TrendsRemakeException(__('messages.model_unavailable'), 503);
        }

        $inputAssets = [];
        foreach ($imageUrls as $url) {
            $inputAssets[] = ['url' => $url, 'fal_url' => $url, 'type' => 'image', 'role' => 'reference'];
        }
        foreach ($videoUrls as $url) {
            $inputAssets[] = ['url' => $url, 'fal_url' => $url, 'type' => 'video', 'role' => 'reference'];
        }
        foreach ($audioUrls as $url) {
            $inputAssets[] = ['url' => $url, 'fal_url' => $url, 'type' => 'audio', 'role' => 'reference'];
        }

        try {
            /** @var UserVideoCreation $creation */
            $creation = $this->tokens->reserve(
                $user,
                $credits,
                'video',
                fn () => UserVideoCreation::create([
                    'user_id' => $user->id,
                    'mode' => $mode,
                    'endpoint_id' => $submitEndpoint,
                    'model_name' => $template->model_name ?: $model->name,
                    'prompt' => $prompt,
                    'negative_prompt' => $template->negative_prompt,
                    'input_assets' => $inputAssets ?: null,
                    'settings' => [
                        'aspect' => $built['aspect_ratio'],
                        'resolution' => $built['resolution'],
                        'duration' => $duration ?? $built['duration_value'],
                        'speed' => $speed,
                        'audio' => $built['with_audio'],
                        'frame_mode' => $settings['frame_mode'] ?? null,
                        'catalog_endpoint' => $model->endpoint_id,
                        'fal_input' => $falInput,
                        'fal_endpoint' => $submitEndpoint,
                        'billing_endpoint' => $billing['endpoint_id'],
                        'billing_source' => $billing['source'],
                        'billing_unit' => $billing['unit'],
                        'billing_unit_price' => $billing['unit_price'],
                        'fal_cost_usd' => $cost['fal_cost_usd'],
                        'credits' => $credits,
                        'cost_breakdown' => $cost['breakdown'],
                        'media_counts' => $counts,
                        'from_trend_id' => $template->id,
                    ],
                    'duration_value' => $built['duration_value'],
                    'duration_seconds' => $built['duration_seconds'],
                    'aspect_ratio' => $built['aspect_ratio'],
                    'resolution' => $built['resolution'],
                    'with_audio' => $built['with_audio'],
                    'credits_charged' => $credits,
                    'status' => UserVideoCreation::STATUS_PENDING,
                ]),
            );
        } catch (InsufficientTokensException $e) {
            throw $e;
        }

        $this->submitFal($user, $creation, 'video', $submitEndpoint, $falInput);

        return [
            'type' => 'video',
            'creation' => $this->presentVideo($creation),
        ];
    }

    /**
     * @param  list<string>  $imageUrls
     * @return array<string, mixed>
     */
    private function remakeImage(User $user, Model $template, array $imageUrls): array
    {
        /** @var UserImageCreation $template */
        $settings = is_array($template->settings) ? $template->settings : [];
        $prompt = trim((string) ($template->prompt ?? ''));
        if ($prompt === '') {
            throw new TrendsRemakeException('Template prompt is missing.', 422);
        }

        $catalogEndpoint = (string) ($settings['catalog_endpoint'] ?? $template->endpoint_id ?? '');
        $model = DB::table('text_to_image_models')
            ->where('status', 'active')
            ->where(function ($q) use ($catalogEndpoint, $template) {
                $q->where('endpoint_id', $catalogEndpoint)
                    ->orWhere('endpoint_id', (string) ($template->endpoint_id ?? ''));
            })
            ->orderByRaw('CASE WHEN endpoint_id = ? THEN 0 ELSE 1 END', [$catalogEndpoint])
            ->first();

        if (! $model) {
            throw new TrendsRemakeException(__('messages.model_unavailable'), 422);
        }

        $aspect = (string) ($settings['aspect'] ?? '1:1');
        $resolution = (string) ($settings['resolution'] ?? '1K');
        $quantity = max(1, min(4, (int) ($settings['quantity'] ?? 1)));
        $uiMode = ($settings['mode'] ?? null) === 'variations' ? 'variations' : 'create';

        if (! $this->imageInput->supportsReferences($model->endpoint_id, $imageUrls)) {
            throw new TrendsRemakeException(
                'The template model does not support these image references.',
                422,
            );
        }

        $providerPrompt = $this->promptReferences->resolve($prompt, [
            'image' => count($imageUrls),
        ]);

        $falInput = $this->imageInput->build($model->endpoint_id, [
            'prompt' => $providerPrompt,
            'aspect' => $aspect,
            'resolution' => $resolution,
            'quantity' => $quantity,
            'reference_urls' => $imageUrls,
        ]);

        $submitEndpoint = $this->imageInput->resolveEndpoint($model->endpoint_id, $imageUrls);
        $billing = $this->pricing->resolve($submitEndpoint);
        if ($billing === null) {
            throw new TrendsRemakeException(__('messages.model_unavailable'), 503);
        }

        $cost = $this->imageCost->estimate([
            'endpoint_id' => $submitEndpoint,
            'unit' => $billing['unit'],
            'unit_price' => $billing['unit_price'],
            'aspect' => $aspect,
            'resolution' => $resolution,
            'quantity' => $quantity,
            'reference_count' => count($imageUrls),
        ]);

        $credits = $this->creditsForTemplate($template, (int) $cost['credits']);
        if ($credits <= 0) {
            throw new TrendsRemakeException(__('messages.model_unavailable'), 503);
        }

        $creationMode = $uiMode === 'variations' || $imageUrls !== []
            ? 'image-to-image'
            : 'text-to-image';

        $inputAssets = array_map(
            static fn (string $url): array => [
                'url' => $url,
                'fal_url' => $url,
                'type' => 'image',
                'role' => 'reference',
            ],
            $imageUrls,
        );

        /** @var UserImageCreation $creation */
        $creation = $this->tokens->reserve(
            $user,
            $credits,
            'image',
            fn () => UserImageCreation::create([
                'user_id' => $user->id,
                'mode' => $creationMode,
                'endpoint_id' => $submitEndpoint,
                'model_name' => $template->model_name ?: $model->name,
                'prompt' => $prompt,
                'input_assets' => $inputAssets ?: null,
                'settings' => [
                    'aspect' => $aspect,
                    'resolution' => $resolution,
                    'quantity' => $quantity,
                    'mode' => $uiMode,
                    'fal_input' => $falInput,
                    'catalog_endpoint' => $model->endpoint_id,
                    'fal_endpoint' => $submitEndpoint,
                    'billing_endpoint' => $billing['endpoint_id'],
                    'billing_source' => $billing['source'],
                    'billing_unit' => $billing['unit'],
                    'billing_unit_price' => $billing['unit_price'],
                    'fal_cost_usd' => $cost['fal_cost_usd'],
                    'credits' => $credits,
                    'cost_breakdown' => $cost['breakdown'],
                    'from_trend_id' => $template->id,
                ],
                'credits_charged' => $credits,
                'status' => UserImageCreation::STATUS_PENDING,
            ]),
        );

        $this->submitFal($user, $creation, 'image', $submitEndpoint, $falInput);

        return [
            'type' => 'image',
            'creation' => $this->presentImage($creation),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function remakeMusic(User $user, Model $template, ?string $audioUrl): array
    {
        /** @var UserMusicCreation $template */
        $settings = is_array($template->settings) ? $template->settings : [];
        $prompt = trim((string) ($template->prompt ?? ''));
        if ($prompt === '') {
            throw new TrendsRemakeException('Template prompt is missing.', 422);
        }

        $endpointId = (string) ($template->endpoint_id ?? '');
        $model = DB::table('text_to_music_models')
            ->where('status', 'active')
            ->where('endpoint_id', $endpointId)
            ->first();

        if (! $model) {
            throw new TrendsRemakeException(__('messages.model_unavailable'), 422);
        }

        $supportsAudio = (bool) ($model->supports_audio ?? false);
        if ($supportsAudio && ($audioUrl === null || $audioUrl === '')) {
            throw new TrendsRemakeException('This template requires an audio upload.', 422);
        }
        if (! $supportsAudio) {
            $audioUrl = null;
        }

        $lyrics = (string) ($template->lyrics ?? '');
        $instrumental = (bool) ($template->instrumental ?? false);
        $autoEnhance = (bool) ($settings['auto_enhance'] ?? false);
        $vocalGender = $settings['vocal_gender'] ?? null;
        $editMode = $settings['edit_mode'] ?? null;
        $durationSeconds = $template->duration_seconds;

        $providerPrompt = $this->promptReferences->resolve($prompt, [
            'audio' => $audioUrl ? 1 : 0,
        ]);

        $falInput = $this->musicInput->build($endpointId, [
            'prompt' => $providerPrompt,
            'lyrics' => $lyrics,
            'instrumental' => $instrumental,
            'vocal_gender' => $vocalGender,
            'auto_enhance' => $autoEnhance,
            'duration_seconds' => $durationSeconds,
            'default_duration_seconds' => $model->default_duration_seconds ?? null,
            'max_duration' => $model->max_duration ?? null,
            'audio_url' => $audioUrl,
            'edit_mode' => $editMode,
        ]);

        $billing = $this->pricing->resolve($endpointId);
        if ($billing === null) {
            throw new TrendsRemakeException(__('messages.model_unavailable'), 503);
        }

        $cost = $this->musicCost->estimate([
            'unit' => $billing['unit'],
            'unit_price' => $billing['unit_price'],
            'default_duration_seconds' => $model->default_duration_seconds ?? null,
            'max_duration' => $model->max_duration ?? null,
        ], $autoEnhance, is_numeric($durationSeconds) ? (int) $durationSeconds : null);

        $credits = $this->creditsForTemplate($template, (int) $cost['credits']);
        if ($credits <= 0) {
            throw new TrendsRemakeException(__('messages.model_unavailable'), 503);
        }

        $title = trim((string) ($template->title ?? ''));
        if ($title === '') {
            $title = mb_strlen($prompt) > 42 ? mb_substr($prompt, 0, 42).'…' : $prompt;
        }

        /** @var UserMusicCreation $creation */
        $creation = $this->tokens->reserve(
            $user,
            $credits,
            'music',
            fn () => UserMusicCreation::create([
                'user_id' => $user->id,
                'mode' => $supportsAudio && $audioUrl ? 'audio-to-audio' : 'text-to-music',
                'endpoint_id' => $endpointId,
                'model_name' => $template->model_name ?: $model->name,
                'title' => $title,
                'prompt' => $prompt,
                'lyrics' => $lyrics !== '' ? $lyrics : null,
                'instrumental' => $instrumental,
                'input_assets' => $audioUrl ? [['url' => $audioUrl, 'kind' => 'audio']] : null,
                'settings' => [
                    'auto_enhance' => $autoEnhance,
                    'vocal_gender' => $vocalGender,
                    'audio_url' => $audioUrl,
                    'edit_mode' => $editMode ?? ($falInput['edit_mode'] ?? null),
                    'fal_input' => $falInput,
                    'fal_endpoint' => $endpointId,
                    'fal_cost_usd' => $cost['fal_cost_usd'],
                    'credits' => $credits,
                    'assumed_seconds' => $cost['assumed_seconds'],
                    'from_trend_id' => $template->id,
                ],
                'duration_seconds' => $cost['assumed_seconds'],
                'credits_charged' => $credits,
                'status' => UserMusicCreation::STATUS_PENDING,
                'progress_message' => 'Starting…',
            ]),
        );

        $this->submitFal($user, $creation, 'music', $endpointId, $falInput);

        return [
            'type' => 'music',
            'creation' => $this->presentMusic($creation),
        ];
    }

    /**
     * @param  array<string, mixed>  $falInput
     */
    private function submitFal(
        User $user,
        Model $creation,
        string $tokenType,
        string $endpoint,
        array $falInput,
    ): void {
        try {
            $this->walletCost->recordBalanceBefore($creation);
            $submit = $this->fal->submit($endpoint, $falInput);
        } catch (Throwable $e) {
            report($e);
            if (method_exists($creation, 'markFailed')) {
                $creation->markFailed(__('messages.could_not_start'), 'submit_error');
            }
            $this->tokens->refund($user, $creation, $tokenType, 'fal_submit_failed');

            throw new TrendsRemakeException(__('messages.could_not_start'), 502);
        }

        if (method_exists($creation, 'markQueued')) {
            $creation->markQueued(
                $submit['request_id'] ?? null,
                $submit['status_url'] ?? null,
                $submit['response_url'] ?? null,
            );
        }

        if (isset($submit['queue_position']) && method_exists($creation, 'forceFill')) {
            $creation->forceFill(['queue_position' => (int) $submit['queue_position']])->save();
        }

        $creation->refresh();
        $this->processor->broadcastSnapshot($tokenType, $creation);
    }

    private function resolveVideoModel(string $catalogEndpoint, string $submitEndpoint): ?object
    {
        $candidates = array_values(array_filter([$catalogEndpoint, $submitEndpoint]));
        foreach (['text_to_video_models', 'image_to_video_models'] as $table) {
            if (! $candidates) {
                break;
            }
            $row = DB::table($table)
                ->where('status', 'active')
                ->whereIn('endpoint_id', $candidates)
                ->orderByRaw('CASE WHEN endpoint_id = ? THEN 0 ELSE 1 END', [$catalogEndpoint])
                ->first();
            if ($row) {
                return $row;
            }
        }

        return null;
    }

    private function creditsForTemplate(Model $template, int $estimated): int
    {
        $trendCost = $template->getAttribute('trend_cost');
        if ($trendCost !== null && is_numeric($trendCost) && (float) $trendCost > 0) {
            return (int) round((float) $trendCost);
        }

        $charged = $template->getAttribute('credits_charged');
        if ($charged !== null && is_numeric($charged) && (float) $charged > 0) {
            return (int) round((float) $charged);
        }

        return $estimated;
    }

    /**
     * @return array{image: int, video: int, audio: int}
     */
    private function expectedMediaCounts(Model $template): array
    {
        $assets = is_array($template->input_assets) ? $template->input_assets : [];
        $counts = ['image' => 0, 'video' => 0, 'audio' => 0];
        foreach ($assets as $asset) {
            if (! is_array($asset)) {
                continue;
            }
            $type = (string) ($asset['type'] ?? $asset['kind'] ?? 'image');
            $kind = in_array($type, ['image', 'video', 'audio'], true) ? $type : 'image';
            $counts[$kind]++;
        }

        $mode = (string) ($template->mode ?? '');
        if ($counts === ['image' => 0, 'video' => 0, 'audio' => 0]) {
            if (
                $template instanceof UserImageCreation
                || str_contains($mode, 'image-to-video')
                || str_contains($mode, 'reference-to-video')
            ) {
                $counts['image'] = 1;
            }
        }

        return $counts;
    }

    /**
     * @param  array{image: int, video: int, audio: int}  $expected
     * @param  array{image: int, video: int, audio: int}  $got
     */
    private function assertMediaCounts(array $expected, array $got): void
    {
        foreach (['image', 'video', 'audio'] as $kind) {
            if ($got[$kind] !== $expected[$kind]) {
                throw new TrendsRemakeException(
                    "Upload {$expected[$kind]} {$kind}(s) to match this template (got {$got[$kind]}).",
                    422,
                );
            }
        }
    }

    /**
     * @param  list<mixed>  $urls
     * @return list<string>
     */
    private function cleanUrls(array $urls): array
    {
        $out = [];
        foreach ($urls as $url) {
            if (! is_string($url)) {
                continue;
            }
            $url = trim($url);
            if ($url !== '') {
                $out[] = $url;
            }
        }

        return array_values($out);
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

    /**
     * @return array<string, mixed>
     */
    private function presentImage(UserImageCreation $creation): array
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
            'images' => collect($creation->result_assets ?? [])->pluck('url')->filter()->values()->all(),
            'preview_url' => $creation->result_preview_url,
            'aspect' => $settings['aspect'] ?? null,
            'resolution' => $settings['resolution'] ?? null,
            'error' => $creation->error_message,
            'credits' => $settings['credits'] ?? null,
            'token_balance' => (int) (auth()->user()?->fresh()->tokens ?? 0),
            'created_at' => optional($creation->created_at)->toIso8601String(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function presentMusic(UserMusicCreation $creation): array
    {
        $duration = $creation->duration_seconds;
        $durationLabel = null;
        if (is_int($duration) && $duration > 0) {
            $durationLabel = sprintf('%d:%02d', intdiv($duration, 60), $duration % 60);
        }

        return [
            'id' => $creation->id,
            'status' => $creation->status,
            'queue_position' => $creation->queue_position,
            'progress_message' => $creation->progress_message,
            'progress_percent' => LabCreationPresenter::progressPercent($creation),
            'prompt' => $creation->prompt,
            'lyrics' => $creation->lyrics,
            'title' => $creation->title,
            'model_name' => $creation->model_name,
            'instrumental' => (bool) $creation->instrumental,
            'audio_url' => $creation->result_audio_url,
            'preview_url' => $creation->result_preview_url,
            'cover_url' => $creation->cover_url,
            'duration_seconds' => $duration,
            'duration' => $durationLabel,
            'error' => $creation->error_message,
            'credits' => $creation->settings['credits'] ?? $creation->credits_charged,
            'token_balance' => (int) (auth()->user()?->fresh()->tokens ?? 0),
            'created_at' => optional($creation->created_at)->toIso8601String(),
        ];
    }
}
