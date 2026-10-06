<?php

namespace App\Http\Controllers;

use App\Exceptions\InsufficientTokensException;
use App\Jobs\PollHiggsfieldCreationJob;
use App\Models\UserVideoCreation;
use App\Services\AssetPromptReferences;
use App\Services\Credits\VideoGenerationCostEstimator;
use App\Services\FalPricingService;
use App\Services\FalService;
use App\Services\FalVideoInputBuilder;
use App\Services\FalWalletCostTracker;
use App\Services\FalWebhookProcessor;
use App\Services\HiggsfieldService;
use App\Services\LabCreationPresenter;
use App\Services\MediaProbeService;
use App\Services\MediaReferenceStorage;
use App\Services\Tokens\TokenService;
use App\Services\VideoModelCapabilities;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class VideoGenerationController extends Controller
{
    public function store(
        Request $request,
        FalService $fal,
        FalVideoInputBuilder $inputBuilder,
        VideoGenerationCostEstimator $costEstimator,
        MediaReferenceStorage $mediaStorage,
        VideoModelCapabilities $capabilities,
        TokenService $tokens,
        FalPricingService $pricing,
        AssetPromptReferences $promptReferences,
        FalWalletCostTracker $walletCost,
        FalWebhookProcessor $processor,
        MediaProbeService $mediaProbe,
        HiggsfieldService $higgsfield,
    ): JsonResponse {
        $contentLength = (int) $request->server('CONTENT_LENGTH', 0);
        if ($contentLength > 0 && $request->all() === [] && $request->allFiles() === []) {
            return response()->json([
                'message' => 'Upload blocked by server size limit. With many images + a video, upload each file under ~35MB, or the app will upload references one-by-one before generate.',
            ], 422);
        }

        foreach (['images', 'videos', 'audios'] as $field) {
            $files = $request->file($field);
            if (! is_array($files)) {
                continue;
            }
            foreach ($files as $index => $file) {
                if ($file instanceof UploadedFile && ! $file->isValid()) {
                    $code = $file->getError();
                    Log::warning('Video generate PHP upload error', [
                        'field' => $field,
                        'index' => $index,
                        'upload_error' => $code,
                        'upload_message' => $file->getErrorMessage(),
                        'name' => $file->getClientOriginalName(),
                        'size_client' => $file->getSize(),
                        'content_length' => $contentLength,
                    ]);

                    return response()->json([
                        'message' => $this->uploadErrorMessage($code, $file->getClientOriginalName(), $field),
                        'upload_error' => $code,
                        'field' => "{$field}.{$index}",
                    ], 422);
                }
            }
        }

        try {
            $data = $request->validate([
                'prompt' => ['nullable', 'string', 'max:100000'],
                'endpoint_id' => ['nullable', 'string', 'max:191'],
                'aspect' => ['nullable', 'string', 'max:32'],
                'resolution' => ['nullable', 'string', 'max:32'],
                'duration' => ['nullable'],
                'audio' => ['nullable', 'boolean'],
                'speed' => ['nullable', 'string', Rule::in(['fast', 'pro'])],
                'images' => ['nullable', 'array', 'max:9'],
                'images.*' => ['file', 'max:30720'],
                'videos' => ['nullable', 'array', 'max:3'],
                'videos.*' => ['file', 'max:51200'],
                'audios' => ['nullable', 'array', 'max:3'],
                'audios.*' => ['file', 'max:15360'],
                // Preferred path: pre-uploaded fal CDN URLs (avoids huge multipart).
                'image_urls' => ['nullable', 'array', 'max:9'],
                'image_urls.*' => ['string', 'max:2048'],
                'video_urls' => ['nullable', 'array', 'max:3'],
                'video_urls.*' => ['string', 'max:2048'],
                'audio_urls' => ['nullable', 'array', 'max:3'],
                'audio_urls.*' => ['string', 'max:2048'],
                'frame_mode' => ['nullable', 'string', Rule::in(['first_last'])],
                'negative_prompt' => ['nullable', 'string', 'max:500'],
                // Client-probed hint used when server cannot ffprobe pre-uploaded video URLs.
                'reference_video_seconds' => ['nullable', 'numeric', 'min:0', 'max:45'],
                // Genjutsu Restyle style UUID from GET /lab/video/genjutsu/restyle-presets.
                'preset_id' => ['nullable', 'uuid'],
            ]);
        } catch (ValidationException $e) {
            $first = collect($e->errors())->flatten()->first();
            Log::warning('Video generate validation failed', [
                'errors' => $e->errors(),
                'content_length' => $contentLength,
                'file_keys' => array_keys($request->allFiles()),
            ]);

            return response()->json([
                'message' => is_string($first) && $first !== '' ? $first : 'Invalid video request.',
                'errors' => $e->errors(),
            ], 422);
        }

        $model = null;
        if (! empty($data['endpoint_id'])) {
            $model = DB::table('text_to_video_models')
                ->where('endpoint_id', $data['endpoint_id'])
                ->where('status', 'active')
                ->first();
        }

        if (! $model) {
            $model = DB::table('text_to_video_models')
                ->where('status', 'active')
                ->orderBy('sort')
                ->first();
        }

        if (! $model) {
            return response()->json(['message' => __('messages.model_unavailable')], 422);
        }

        $wantsHiggsfield = HiggsfieldService::isHiggsfieldEndpoint((string) $model->endpoint_id);
        if ($wantsHiggsfield && ! $higgsfield->configured()) {
            return response()->json(['message' => 'Higgsfield is not configured.'], 503);
        }
        if (! $wantsHiggsfield && ! $fal->configured()) {
            return response()->json(['message' => 'Video service is not configured.'], 503);
        }

        $promptText = trim((string) ($data['prompt'] ?? ''));
        if (! $wantsHiggsfield && mb_strlen($promptText) < 2) {
            return response()->json(['message' => 'Add a prompt to generate.'], 422);
        }

        $imageFiles = $this->normalizeFiles($request->file('images'));
        $videoFiles = $this->normalizeFiles($request->file('videos'));
        $audioFiles = $this->normalizeFiles($request->file('audios'));

        $preImageUrls = $this->normalizeUrlList($data['image_urls'] ?? []);
        $preVideoUrls = $this->normalizeUrlList($data['video_urls'] ?? []);
        $preAudioUrls = $this->normalizeUrlList($data['audio_urls'] ?? []);

        $counts = [
            'images' => count($imageFiles) + count($preImageUrls),
            'videos' => count($videoFiles) + count($preVideoUrls),
            'audios' => count($audioFiles) + count($preAudioUrls),
        ];
        $frameMode = ($data['frame_mode'] ?? null) === 'first_last' ? 'first_last' : null;

        if ($counts['audios'] > 0 && ($counts['images'] + $counts['videos']) === 0) {
            return response()->json([
                'message' => 'Audio references require at least one image or video alongside them.',
            ], 422);
        }

        if (! $capabilities->supportsMediaMix($model->endpoint_id, $counts, $frameMode)) {
            return response()->json([
                'message' => 'The selected model does not support this media mix. Choose a compatible model or remove unsupported references.',
            ], 422);
        }

        $route = $capabilities->resolveRoute($model->endpoint_id, $counts, $frameMode);
        if ($route === null) {
            $needsImage = str_contains(strtolower((string) $model->endpoint_id), 'image-to-video')
                && (int) ($counts['images'] ?? 0) < 1;

            return response()->json([
                'message' => $needsImage
                    ? 'This model needs a source image. Upload an image to animate.'
                    : 'Could not resolve a fal endpoint for this model and media mix.',
            ], 422);
        }

        $inputAssets = [];
        try {
            if ($imageFiles !== []) {
                $inputAssets = array_merge($inputAssets, $mediaStorage->storeMany($request->user()->id, $imageFiles, 'image'));
            }
            if ($videoFiles !== []) {
                $inputAssets = array_merge($inputAssets, $mediaStorage->storeMany($request->user()->id, $videoFiles, 'video'));
            }
            if ($audioFiles !== []) {
                $inputAssets = array_merge($inputAssets, $mediaStorage->storeMany($request->user()->id, $audioFiles, 'audio'));
            }
        } catch (\Throwable $e) {
            report($e);

            return response()->json(['message' => __('messages.upload_failed')], 502);
        }

        foreach ($preImageUrls as $url) {
            $inputAssets[] = ['url' => $url, 'fal_url' => $url, 'type' => 'image', 'role' => 'reference'];
        }
        foreach ($preVideoUrls as $url) {
            $inputAssets[] = ['url' => $url, 'fal_url' => $url, 'type' => 'video', 'role' => 'reference'];
        }
        foreach ($preAudioUrls as $url) {
            $inputAssets[] = ['url' => $url, 'fal_url' => $url, 'type' => 'audio', 'role' => 'reference'];
        }

        $imageUrls = [];
        $videoUrls = [];
        $audioUrls = [];
        foreach ($inputAssets as $asset) {
            $url = $asset['fal_url'] ?? $asset['url'] ?? null;
            if (! is_string($url) || $url === '') {
                continue;
            }
            match ($asset['type'] ?? '') {
                'video' => $videoUrls[] = $url,
                'audio' => $audioUrls[] = $url,
                default => $imageUrls[] = $url,
            };
        }

        // Normalize videos for partner downloaders (moov-at-end phone/Windows MP4s).
        // Higgsfield also needs a publicly downloadable MP4 URL.
        if ($fal->configured() && $videoUrls !== []) {
            try {
                $videoUrls = array_values(array_map(
                    fn (string $url): string => $fal->ensureInferenceVideoUrl($url, 'lab-video.mp4'),
                    $videoUrls,
                ));
                $videoIndex = 0;
                foreach ($inputAssets as $i => $asset) {
                    if (($asset['type'] ?? null) !== 'video') {
                        continue;
                    }
                    if (! isset($videoUrls[$videoIndex])) {
                        break;
                    }
                    $inputAssets[$i]['fal_url'] = $videoUrls[$videoIndex];
                    $inputAssets[$i]['url'] = $videoUrls[$videoIndex];
                    $inputAssets[$i]['normalized'] = true;
                    $videoIndex++;
                }
            } catch (\Throwable $e) {
                report($e);

                return response()->json([
                    'message' => 'Could not prepare the video for AI processing. Try re-uploading as MP4.',
                ], 422);
            }
        }

        $submitEndpoint = $route['endpoint_id'];
        $mode = $route['mode'];

        $referenceVideoSeconds = 0.0;
        foreach ($videoFiles as $videoFile) {
            if (! $videoFile instanceof UploadedFile) {
                continue;
            }
            $probed = $mediaProbe->probeUploaded($videoFile);
            if ($probed !== null && isset($probed['duration']) && is_numeric($probed['duration'])) {
                $referenceVideoSeconds += (float) $probed['duration'];
            }
        }
        foreach ($preVideoUrls as $url) {
            $probed = $mediaProbe->probeUrl($url);
            if ($probed !== null && isset($probed['duration']) && is_numeric($probed['duration'])) {
                $referenceVideoSeconds += (float) $probed['duration'];
            }
        }
        $clientHint = max(0.0, (float) ($data['reference_video_seconds'] ?? 0));
        if ($referenceVideoSeconds <= 0 && $clientHint > 0) {
            $referenceVideoSeconds = $clientHint;
        }

        if (HiggsfieldService::isGenjutsuEndpoint($submitEndpoint)) {
            return $this->storeGenjutsu(
                $request,
                $higgsfield,
                $costEstimator,
                $tokens,
                $processor,
                $model,
                $submitEndpoint,
                $mode,
                $promptText,
                $data,
                $inputAssets,
                $imageUrls,
                $videoUrls,
                $counts,
                $referenceVideoSeconds,
            );
        }

        $allowedDurations = null;
        if (! empty($model->enums)) {
            $decoded = is_string($model->enums) ? json_decode($model->enums, true) : $model->enums;
            $allowedDurations = is_array($decoded) ? $decoded : null;
        }

        $providerPrompt = $promptReferences->resolve($promptText !== '' ? $promptText : (string) ($data['prompt'] ?? ''), [
            'image' => count($imageUrls),
            'video' => count($videoUrls),
            'audio' => count($audioUrls),
        ]);

        $negativePrompt = trim((string) ($data['negative_prompt'] ?? ''));

        $built = $inputBuilder->build($submitEndpoint, [
            'prompt' => $providerPrompt,
            'negative_prompt' => $negativePrompt,
            'aspect' => $this->pickSupportedOption(
                (string) ($data['aspect'] ?? '16:9'),
                $model->aspect_ratios ?? null,
                '16:9',
            ),
            'resolution' => $this->pickSupportedOption(
                (string) ($data['resolution'] ?? '720p'),
                $model->resolutions ?? null,
                '720p',
            ),
            'duration' => $data['duration'] ?? null,
            'audio' => array_key_exists('audio', $data) ? (bool) $data['audio'] : true,
            'allowed_durations' => $allowedDurations,
            'mode' => $mode,
            'image_urls' => $imageUrls,
            'video_urls' => $videoUrls,
            'audio_urls' => $audioUrls,
            'first_frame_param' => $route['first_frame_param'],
            'last_frame_param' => $route['last_frame_param'] ?? null,
        ]);

        $falInput = $built['input'];
        $durationSeconds = $built['duration_seconds'];
        $withAudio = $built['with_audio'];

        // Prefer pricing for the exact submit route (I2V/R2V). No live fal lookup; cron owns prices.
        $billing = $pricing->resolve($submitEndpoint);
        if ($billing === null) {
            return response()->json([
                'message' => __('messages.model_unavailable'),
                'endpoint_id' => $submitEndpoint,
            ], 503);
        }

        // Fail closed on H3 R2V: unknown ref duration still bills a motion clip (fal bills both).
        // Prefer probed/client duration; otherwise assume output length (pricing policy also
        // fail-closes if this stays 0).
        if (
            $referenceVideoSeconds <= 0
            && $videoUrls !== []
            && str_contains(strtolower($submitEndpoint), 'minimax/h3')
            && str_contains(strtolower($submitEndpoint), 'reference-to-video')
        ) {
            $referenceVideoSeconds = (float) min(15, max(1, $durationSeconds));
        }

        $cost = $costEstimator->estimate([
            'endpoint_id' => $submitEndpoint,
            'unit' => $billing['unit'],
            'unit_price' => $billing['unit_price'],
            'duration_seconds' => $durationSeconds,
            'audio' => $withAudio,
            'voice_control' => (bool) ($data['voice_control'] ?? $data['voice'] ?? false),
            'resolution' => $built['resolution'] ?? ($data['resolution'] ?? '720p'),
            'aspect' => $built['aspect_ratio'] ?? ($data['aspect'] ?? '16:9'),
            'reference_video_seconds' => $referenceVideoSeconds,
            'reference_image_count' => count($imageUrls),
        ]);

        if ((int) $cost['credits'] <= 0) {
            return response()->json([
                'message' => __('messages.model_unavailable'),
                'endpoint_id' => $submitEndpoint,
            ], 503);
        }

        try {
            /** @var UserVideoCreation $creation */
            $creation = $tokens->reserve(
                $request->user(),
                (int) $cost['credits'],
                'video',
                fn () => UserVideoCreation::create([
                    'user_id' => $request->user()->id,
                    'mode' => $mode,
                    'endpoint_id' => $submitEndpoint,
                    'model_name' => $model->name,
                    'prompt' => $data['prompt'],
                    'negative_prompt' => $negativePrompt !== '' ? $negativePrompt : null,
                    'input_assets' => $inputAssets ?: null,
                    'settings' => [
                        'aspect' => $built['aspect_ratio'],
                        'resolution' => $built['resolution'],
                        'duration' => $data['duration'] ?? $built['duration_value'],
                        'speed' => $data['speed'] ?? 'pro',
                        'audio' => $withAudio,
                        'negative_prompt' => $negativePrompt !== '' ? $negativePrompt : null,
                        'catalog_endpoint' => $model->endpoint_id,
                        'fal_input' => $falInput,
                        'fal_endpoint' => $submitEndpoint,
                        'billing_endpoint' => $billing['endpoint_id'],
                        'billing_source' => $billing['source'],
                        'billing_unit' => $billing['unit'],
                        'billing_unit_price' => $billing['unit_price'],
                        'fal_cost_usd' => $cost['fal_cost_usd'],
                        'credits' => $cost['credits'],
                        'cost_breakdown' => $cost['breakdown'],
                        'media_counts' => $counts,
                    ],
                    'duration_value' => $built['duration_value'],
                    'duration_seconds' => $durationSeconds,
                    'aspect_ratio' => $built['aspect_ratio'],
                    'resolution' => $built['resolution'],
                    'with_audio' => $withAudio,
                    'credits_charged' => $cost['credits'],
                    'status' => UserVideoCreation::STATUS_PENDING,
                ]),
            );
        } catch (InsufficientTokensException $e) {
            return response()->json([
                'message' => __('messages.not_enough_tokens'),
                'required_tokens' => $e->required,
                'available_tokens' => $e->available,
            ], 402);
        }

        try {
            $walletCost->recordBalanceBefore($creation);
            $submit = $fal->submit($submitEndpoint, $falInput);
        } catch (\Throwable $e) {
            report($e);
            $creation->markFailed(__('messages.could_not_start'), 'submit_error');
            $tokens->refund($request->user(), $creation, 'video', 'fal_submit_failed');

            return response()->json($this->present($creation), 502);
        }

        $creation->markQueued(
            $submit['request_id'] ?? null,
            $submit['status_url'] ?? null,
            $submit['response_url'] ?? null,
        );

        if (isset($submit['queue_position'])) {
            $creation->forceFill(['queue_position' => (int) $submit['queue_position']])->save();
        }

        $creation->refresh();
        $processor->broadcastSnapshot('video', $creation);

        return response()->json($this->present($creation), 201);
    }

    public function status(Request $request, UserVideoCreation $creation, FalWebhookProcessor $processor, FalWalletCostTracker $walletCost): JsonResponse
    {
        abort_unless($creation->isOwnedBy($request->user()), 403);

        if (! $creation->isTerminal() && $creation->fal_request_id) {
            $processor->syncFromFal('video', $creation);
            $creation->refresh();
        }

        // Fill cost_usd + fal_wallet_balance_after (billing often lags a few seconds).
        if (
            $creation->fal_request_id
            && in_array($creation->status, [
                UserVideoCreation::STATUS_COMPLETED,
                UserVideoCreation::STATUS_FAILED,
            ], true)
            && ! $walletCost->isFullyReconciled($creation)
        ) {
            try {
                if ($creation->status === UserVideoCreation::STATUS_COMPLETED) {
                    $walletCost->recordAfterCompletion($creation);
                } else {
                    $walletCost->recordAfterFailure($creation);
                }
            } catch (\Throwable $e) {
                report($e);
            }
            $creation->refresh();
        }

        return response()->json($this->present($creation));
    }

    public function restylePresets(HiggsfieldService $higgsfield): JsonResponse
    {
        if (! $higgsfield->configured()) {
            return response()->json(['message' => 'Higgsfield is not configured.'], 503);
        }

        try {
            $items = $higgsfield->listRestylePresets();
        } catch (\Throwable $e) {
            report($e);

            return response()->json(['message' => 'Could not load Restyle styles.'], 502);
        }

        return response()->json([
            'model' => HiggsfieldService::GENJUTSU_RESTYLE,
            'items' => $items,
        ]);
    }

    /**
     * @param  object{endpoint_id: string, name: string, resolutions?: mixed, aspect_ratios?: mixed}  $model
     * @param  array<string, mixed>  $data
     * @param  list<array<string, mixed>>  $inputAssets
     * @param  list<string>  $imageUrls
     * @param  list<string>  $videoUrls
     * @param  array{images: int, videos: int, audios: int}  $counts
     */
    private function storeGenjutsu(
        Request $request,
        HiggsfieldService $higgsfield,
        VideoGenerationCostEstimator $costEstimator,
        TokenService $tokens,
        FalWebhookProcessor $processor,
        object $model,
        string $submitEndpoint,
        string $mode,
        string $promptText,
        array $data,
        array $inputAssets,
        array $imageUrls,
        array $videoUrls,
        array $counts,
        float $referenceVideoSeconds,
    ): JsonResponse {
        if ($videoUrls === []) {
            return response()->json(['message' => 'Add a motion video (4–30s).'], 422);
        }

        $isRestyle = str_contains(strtolower($submitEndpoint), 'restyle');
        if (! $isRestyle && $imageUrls === []) {
            return response()->json(['message' => 'Add at least one character or product image.'], 422);
        }

        $presetId = isset($data['preset_id']) && is_string($data['preset_id'])
            ? trim($data['preset_id'])
            : '';
        if ($isRestyle && $presetId === '') {
            return response()->json(['message' => 'Pick a Restyle style before creating.'], 422);
        }

        if ($referenceVideoSeconds <= 0) {
            return response()->json([
                'message' => 'Could not read the motion video length. Re-upload the clip and try again.',
            ], 422);
        }
        if ($referenceVideoSeconds < 4) {
            return response()->json([
                'message' => 'Genjutsu needs a motion video of at least 4 seconds.',
            ], 422);
        }

        $resolution = $this->pickSupportedOption(
            (string) ($data['resolution'] ?? '720p'),
            $model->resolutions ?? null,
            '720p',
        );
        if (! in_array(strtolower($resolution), ['480p', '720p', '1080p'], true)) {
            $resolution = '720p';
        } else {
            $resolution = strtolower($resolution);
        }

        $billableSeconds = (int) min(30, max(1, (int) ceil($referenceVideoSeconds - 1e-9)));
        $cost = $costEstimator->estimate([
            'endpoint_id' => $submitEndpoint,
            'unit' => 'seconds',
            'unit_price' => HiggsfieldService::genjutsuUnitPriceUsd($resolution),
            'duration_seconds' => $billableSeconds,
            'audio' => false,
            'resolution' => $resolution,
            'aspect' => 'auto',
            'reference_video_seconds' => $referenceVideoSeconds,
            'reference_image_count' => count($imageUrls),
        ]);

        if ((int) $cost['credits'] <= 0) {
            return response()->json([
                'message' => __('messages.model_unavailable'),
                'endpoint_id' => $submitEndpoint,
            ], 503);
        }

        $hfInput = [
            'video_url' => $videoUrls[0],
            'resolution' => $resolution,
        ];
        if ($isRestyle) {
            $hfInput['preset_id'] = $presetId;
            $hfInput['image_urls'] = array_values(array_slice($imageUrls, 0, 5));
        } else {
            $hfInput['image_urls'] = array_values(array_slice($imageUrls, 0, 8));
        }
        if ($promptText !== '') {
            $hfInput['prompt'] = mb_substr($promptText, 0, 10000);
        }

        try {
            /** @var UserVideoCreation $creation */
            $creation = $tokens->reserve(
                $request->user(),
                (int) $cost['credits'],
                'video',
                fn () => UserVideoCreation::create([
                    'user_id' => $request->user()->id,
                    'mode' => $mode,
                    'provider' => 'higgsfield',
                    'endpoint_id' => $submitEndpoint,
                    'model_name' => $model->name,
                    'prompt' => $promptText !== '' ? $promptText : null,
                    'negative_prompt' => null,
                    'input_assets' => $inputAssets ?: null,
                    'settings' => [
                        'aspect' => 'auto',
                        'resolution' => $resolution,
                        'duration' => $billableSeconds,
                        'audio' => false,
                        'provider' => 'higgsfield',
                        'catalog_endpoint' => $model->endpoint_id,
                        'higgsfield_model' => $submitEndpoint,
                        'higgsfield_input' => $hfInput,
                        'preset_id' => $isRestyle ? $presetId : null,
                        'billing_endpoint' => $submitEndpoint,
                        'billing_source' => 'higgsfield_list',
                        'billing_unit' => $cost['unit'],
                        'billing_unit_price' => $cost['unit_price'],
                        'fal_cost_usd' => $cost['fal_cost_usd'],
                        'credits' => $cost['credits'],
                        'cost_breakdown' => $cost['breakdown'],
                        'media_counts' => $counts,
                        'reference_video_seconds' => $referenceVideoSeconds,
                    ],
                    'duration_value' => (string) $billableSeconds,
                    'duration_seconds' => $billableSeconds,
                    'aspect_ratio' => 'auto',
                    'resolution' => $resolution,
                    'with_audio' => false,
                    'credits_charged' => $cost['credits'],
                    'status' => UserVideoCreation::STATUS_PENDING,
                    'progress_message' => 'Starting video generation…',
                ]),
            );
        } catch (InsufficientTokensException $e) {
            return response()->json([
                'message' => __('messages.not_enough_tokens'),
                'required_tokens' => $e->required,
                'available_tokens' => $e->available,
            ], 402);
        }

        try {
            $submit = $higgsfield->submit($submitEndpoint, $hfInput);
        } catch (\Throwable $e) {
            report($e);
            $creation->markFailed(__('messages.could_not_start'), 'submit_error');
            $tokens->refund($request->user(), $creation, 'video', 'higgsfield_submit_failed');
            $processor->broadcastSnapshot('video', $creation->fresh());

            return response()->json($this->present($creation->fresh()), 502);
        }

        $creation->markQueued(
            $submit['request_id'] ?? null,
            $submit['status_url'] ?? null,
            $submit['response_url'] ?? null,
        );
        $processor->broadcastSnapshot('video', $creation->fresh());

        PollHiggsfieldCreationJob::dispatch((int) $creation->id)
            ->onConnection('database')
            ->delay(now()->addSeconds(8));

        return response()->json($this->present($creation->fresh()), 201);
    }

    /**
     * @param  array<int, UploadedFile>|UploadedFile|null  $raw
     * @return array<int, UploadedFile>
     */
    private function normalizeFiles(mixed $raw): array
    {
        if ($raw === null) {
            return [];
        }

        if ($raw instanceof UploadedFile) {
            return [$raw];
        }

        return is_array($raw) ? array_values(array_filter($raw, fn ($f) => $f instanceof UploadedFile)) : [];
    }

    private function refresh(UserVideoCreation $creation, FalService $fal, FalWalletCostTracker $walletCost): void
    {
        try {
            $status = $creation->fal_status_url
                ? $fal->statusByUrl($creation->fal_status_url)
                : null;
        } catch (\Throwable $e) {
            report($e);

            return;
        }

        if (! is_array($status)) {
            return;
        }

        $state = $status['status'] ?? null;

        if ($state === 'IN_QUEUE') {
            $creation->forceFill([
                'status' => UserVideoCreation::STATUS_QUEUED,
                'queue_position' => $status['queue_position'] ?? null,
                'progress_message' => 'In queue',
            ])->save();

            return;
        }

        if ($state === 'IN_PROGRESS') {
            $creation->markInProgress(null, __('messages.generating'));

            return;
        }

        if ($state !== 'COMPLETED') {
            return;
        }

        if (! empty($status['error'])) {
            $creation->markFailed((string) $status['error'], $status['error_type'] ?? 'error');

            return;
        }

        try {
            $result = $creation->fal_response_url
                ? $fal->resultByUrl($creation->fal_response_url)
                : [];
        } catch (\Throwable $e) {
            report($e);

            return;
        }

        $video = $this->extractVideo($result);

        if ($video === null) {
            $creation->markFailed(__('messages.no_video'), 'empty_result');

            return;
        }

        $creation->forceFill([
            'status' => UserVideoCreation::STATUS_COMPLETED,
            'result_assets' => [$video],
            'result_video_url' => $video['url'],
            'result_preview_url' => $video['url'],
            'thumbnail_url' => $video['thumbnail'] ?? null,
            'progress_message' => 'Completed',
            'queue_position' => null,
            'completed_at' => now(),
            'error_message' => null,
            'error_type' => null,
        ])->save();

        $walletCost->recordAfterCompletion($creation);
    }

    /**
     * @param  array<string, mixed>  $result
     * @return array<string, mixed>|null
     */
    private function extractVideo(array $result): ?array
    {
        $video = $result['video'] ?? null;

        if (is_array($video) && ! empty($video['url']) && is_string($video['url'])) {
            return [
                'url' => $video['url'],
                'content_type' => $video['content_type'] ?? 'video/mp4',
                'file_name' => $video['file_name'] ?? null,
                'file_size' => $video['file_size'] ?? null,
                'thumbnail' => (is_array($result['thumbnail'] ?? null) && is_string($result['thumbnail']['url'] ?? null))
                    ? $result['thumbnail']['url']
                    : null,
            ];
        }

        if (is_string($video) && $video !== '') {
            return ['url' => $video, 'content_type' => 'video/mp4'];
        }

        $videos = $result['videos'] ?? null;
        if (is_array($videos) && isset($videos[0]) && is_array($videos[0]) && ! empty($videos[0]['url'])) {
            return [
                'url' => $videos[0]['url'],
                'content_type' => $videos[0]['content_type'] ?? 'video/mp4',
            ];
        }

        return null;
    }

    /**
     * @param  array<int, mixed>|null  $urls
     * @return list<string>
     */
    private function normalizeUrlList(mixed $urls): array
    {
        if (! is_array($urls)) {
            return [];
        }

        $out = [];
        foreach ($urls as $url) {
            if (! is_string($url)) {
                continue;
            }
            $url = trim($url);
            if ($url === '' || ! $this->isAllowedFalMediaUrl($url)) {
                continue;
            }
            $out[] = $url;
        }

        return array_values(array_unique($out));
    }

    private function isAllowedFalMediaUrl(string $url): bool
    {
        $parts = parse_url($url);
        if (! is_array($parts) || ($parts['scheme'] ?? '') !== 'https') {
            return false;
        }
        $host = strtolower((string) ($parts['host'] ?? ''));
        if ($host === '') {
            return false;
        }

        return $host === 'fal.media'
            || str_ends_with($host, '.fal.media')
            || $host === 'v3.fal.media'
            || $host === 'v3b.fal.media';
    }

    private function uploadErrorMessage(int $code, ?string $name, string $field): string
    {
        $label = $name ? "“{$name}”" : 'A media file';
        $kind = match ($field) {
            'videos' => 'video',
            'audios' => 'audio',
            default => 'image',
        };

        return match ($code) {
            UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE => "{$label} is too large for the server (PHP upload limit). Compress the {$kind} or keep each file under ~35MB. With many references, the app uploads them one-by-one.",
            UPLOAD_ERR_PARTIAL => "{$label} was only partially uploaded. Try again (stable connection / smaller file).",
            UPLOAD_ERR_NO_FILE => "No {$kind} file was received.",
            UPLOAD_ERR_NO_TMP_DIR => 'Server temp folder is missing (upload_tmp_dir).',
            UPLOAD_ERR_CANT_WRITE => 'Server could not save the upload to disk.',
            UPLOAD_ERR_EXTENSION => "A PHP extension blocked this {$kind} upload.",
            default => "{$label} failed to upload (PHP error code {$code}).",
        };
    }

    /**
     * @return array<string, mixed>
     */
    private function present(UserVideoCreation $creation): array
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
            'error' => $creation->error_message,
            'credits' => $settings['credits'] ?? $creation->credits_charged,
            'token_balance' => (int) (auth()->user()?->fresh()->tokens ?? 0),
            'fal_cost_usd' => $settings['fal_cost_usd'] ?? null,
            'mode' => $creation->mode,
            'created_at' => optional($creation->created_at)->toIso8601String(),
        ];
    }

    /**
     * Prefer the requested option when the model lists it; otherwise first listed / fallback.
     */
    private function pickSupportedOption(string $requested, mixed $rawList, string $fallback): string
    {
        $list = [];
        if (is_string($rawList) && $rawList !== '') {
            $decoded = json_decode($rawList, true);
            $rawList = is_array($decoded) ? $decoded : [];
        }
        if (is_array($rawList)) {
            foreach ($rawList as $item) {
                if (is_scalar($item) && (string) $item !== '') {
                    $list[] = (string) $item;
                }
            }
        }

        if ($list === []) {
            return $requested !== '' ? $requested : $fallback;
        }

        foreach ($list as $item) {
            if (strcasecmp($item, $requested) === 0) {
                return $item;
            }
        }

        return $list[0];
    }
}
