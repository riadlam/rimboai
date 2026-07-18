<?php

namespace App\Http\Controllers;

use App\Exceptions\InsufficientTokensException;
use App\Models\UserVideoCreation;
use App\Services\AssetPromptReferences;
use App\Services\Credits\VideoGenerationCostEstimator;
use App\Services\FalPricingService;
use App\Services\FalService;
use App\Services\FalVideoInputBuilder;
use App\Services\FalWalletCostTracker;
use App\Services\FalWebhookProcessor;
use App\Services\LabCreationPresenter;
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
                'prompt' => ['required', 'string', 'min:2', 'max:2000'],
                'endpoint_id' => ['nullable', 'string', 'max:191'],
                'aspect' => ['nullable', 'string', Rule::in(['16:9', '9:16', '1:1', '4:5', '3:4'])],
                'resolution' => ['nullable', 'string', Rule::in(['720p', '1080p', '4K', '4k'])],
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

        if (! $fal->configured()) {
            return response()->json(['message' => 'Video service is not configured.'], 503);
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
            return response()->json([
                'message' => 'Could not resolve a fal endpoint for this model and media mix.',
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

        $allowedDurations = null;
        if (! empty($model->enums)) {
            $decoded = is_string($model->enums) ? json_decode($model->enums, true) : $model->enums;
            $allowedDurations = is_array($decoded) ? $decoded : null;
        }

        $submitEndpoint = $route['endpoint_id'];
        $mode = $route['mode'];

        $providerPrompt = $promptReferences->resolve($data['prompt'], [
            'image' => count($imageUrls),
            'video' => count($videoUrls),
            'audio' => count($audioUrls),
        ]);

        $negativePrompt = trim((string) ($data['negative_prompt'] ?? ''));

        $built = $inputBuilder->build($submitEndpoint, [
            'prompt' => $providerPrompt,
            'negative_prompt' => $negativePrompt,
            'aspect' => $data['aspect'] ?? '16:9',
            'resolution' => $data['resolution'] ?? '720p',
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

        $cost = $costEstimator->estimate([
            'endpoint_id' => $submitEndpoint,
            'unit' => $billing['unit'],
            'unit_price' => $billing['unit_price'],
            'duration_seconds' => $durationSeconds,
            'audio' => $withAudio,
            'resolution' => $built['resolution'] ?? ($data['resolution'] ?? '720p'),
            'aspect' => $built['aspect_ratio'] ?? ($data['aspect'] ?? '16:9'),
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
}
