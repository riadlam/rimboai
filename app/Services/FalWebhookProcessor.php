<?php

namespace App\Services;

use App\Events\CreationUpdated;
use App\Models\User;
use App\Models\UserImageCreation;
use App\Models\UserMusicCreation;
use App\Models\UserVideoCreation;
use App\Models\UserVoiceCreation;
use App\Services\Tokens\TokenService;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Applies fal webhook payloads to Lab creations (idempotent) and notifies the UI.
 */
class FalWebhookProcessor
{
    public function __construct(
        private readonly TokenService $tokens,
        private readonly FalWalletCostTracker $walletCost,
        private readonly FalFailureRefundDecider $failureRefund,
        private readonly FalService $fal,
    ) {}

    /**
     * Pull latest state from fal queue URLs (used when a webhook was missed).
     * Not a browser poll — one server-side check, e.g. on library refresh.
     */
    /**
     * Minimum seconds between real fal status calls for a single creation.
     * Shared across tabs/users via cache so concurrent viewers do not multiply fal traffic.
     */
    private const SYNC_THROTTLE_SECONDS = 8;

    public function syncFromFal(string $type, Model $creation): void
    {
        if (method_exists($creation, 'isTerminal') && $creation->isTerminal()) {
            return;
        }

        $statusUrl = $creation->getAttribute('fal_status_url');
        if (! is_string($statusUrl) || $statusUrl === '') {
            return;
        }

        // Global (cross-tab, cross-user) throttle: skip the fal HTTP call if one ran recently.
        // The DB already holds the latest known state and Pusher already broadcast it.
        $throttleKey = "fal_sync_throttle_{$type}_{$creation->getKey()}";
        if (Cache::get($throttleKey)) {
            return;
        }
        Cache::put($throttleKey, 1, self::SYNC_THROTTLE_SECONDS);

        try {
            $status = $this->fal->statusByUrl($statusUrl);
        } catch (\Throwable $e) {
            report($e);
            Log::warning('fal.sync.status_failed', [
                'type' => $type,
                'creation_id' => $creation->getKey(),
                'error' => $e->getMessage(),
            ]);

            return;
        }

        $state = strtoupper((string) ($status['status'] ?? ''));

        if ($state === 'IN_QUEUE') {
            $queuePosition = isset($status['queue_position']) ? (int) $status['queue_position'] : null;
            $creation->forceFill([
                'status' => $creation::STATUS_QUEUED,
                'queue_position' => $queuePosition,
                'progress_message' => LabCreationPresenter::queueProgressMessage($queuePosition),
            ])->save();
            $this->broadcast($type, $creation->fresh() ?? $creation);

            return;
        }

        if ($state === 'IN_PROGRESS') {
            if (method_exists($creation, 'markInProgress')) {
                $creation->markInProgress(null, $type === 'music' ? 'Composing…' : __('messages.generating'));
            }
            $this->broadcast($type, $creation->fresh() ?? $creation);

            return;
        }

        if (in_array($state, ['FAILED', 'ERROR', 'CANCELLED'], true) || ! empty($status['error'])) {
            $this->fail($type, $creation, [
                'error' => (string) ($status['error'] ?? __('messages.generation_failed')),
                'error_type' => $status['error_type'] ?? 'error',
            ]);
            $creation->refresh();
            $this->finalizeFailure($type, $creation);
            $this->broadcast($type, $creation);

            return;
        }

        if ($state !== 'COMPLETED') {
            return;
        }

        if (! empty($status['error'])) {
            $this->fail($type, $creation, [
                'error' => (string) $status['error'],
                'error_type' => $status['error_type'] ?? 'error',
            ]);
            $creation->refresh();
            $this->finalizeFailure($type, $creation);
            $this->broadcast($type, $creation);

            return;
        }

        $responseUrl = $creation->getAttribute('fal_response_url');
        try {
            $result = is_string($responseUrl) && $responseUrl !== ''
                ? $this->fal->resultByUrl($responseUrl)
                : [];
        } catch (\Throwable $e) {
            report($e);
            Log::warning('fal.sync.result_failed', [
                'type' => $type,
                'creation_id' => $creation->getKey(),
                'error' => $e->getMessage(),
            ]);

            return;
        }

        // Reuse webhook completion path (payload shape matches fal result JSON).
        $this->handle([
            'request_id' => (string) $creation->getAttribute('fal_request_id'),
            'status' => 'OK',
            'payload' => $result,
        ]);

        Log::info('fal.sync.completed_via_status', [
            'type' => $type,
            'creation_id' => $creation->getKey(),
            'request_id' => $creation->getAttribute('fal_request_id'),
        ]);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public function handle(array $payload): void
    {
        $requestId = isset($payload['request_id']) && is_string($payload['request_id'])
            ? $payload['request_id']
            : null;

        if ($requestId === null || $requestId === '') {
            Log::warning('fal.webhook.missing_request_id', [
                'keys' => array_keys($payload),
            ]);

            return;
        }

        $found = $this->findByRequestId($requestId);
        if ($found === null) {
            Log::info('fal.webhook.unknown_request', [
                'request_id' => $requestId,
                'status' => $payload['status'] ?? null,
            ]);

            return;
        }

        [$type, $creation] = $found;

        DB::transaction(function () use ($type, $creation, $payload, $requestId) {
            /** @var Model $locked */
            $locked = $creation->newQuery()->whereKey($creation->getKey())->lockForUpdate()->first();
            if ($locked === null) {
                return;
            }

            if (method_exists($locked, 'isTerminal') && $locked->isTerminal()) {
                Log::info('fal.webhook.already_terminal', [
                    'type' => $type,
                    'creation_id' => $locked->getKey(),
                    'request_id' => $requestId,
                    'status' => $locked->status,
                ]);

                return;
            }

            $status = strtoupper((string) ($payload['status'] ?? ''));

            if ($status === 'ERROR' || $status === 'FAILED') {
                $this->fail($type, $locked, $payload);

                return;
            }

            if ($status !== 'OK') {
                Log::warning('fal.webhook.unexpected_status', [
                    'type' => $type,
                    'creation_id' => $locked->getKey(),
                    'request_id' => $requestId,
                    'status' => $status,
                ]);

                return;
            }

            if (! empty($payload['payload_error'])) {
                $this->fail($type, $locked, [
                    'error' => (string) $payload['payload_error'],
                    'error_type' => 'payload_error',
                ]);

                return;
            }

            $result = is_array($payload['payload'] ?? null) ? $payload['payload'] : null;
            if ($result === null) {
                $this->fail($type, $locked, [
                    'error' => 'Empty fal webhook payload.',
                    'error_type' => 'empty_payload',
                ]);

                return;
            }

            match ($type) {
                'image' => $this->completeImage($locked, $result),
                'video' => $this->completeVideo($locked, $result),
                'music' => $this->completeMusic($locked, $result),
                'voice' => $this->completeVoice($locked, $result),
                default => null,
            };
        });

        $creation->refresh();
        $this->broadcast($type, $creation);

        if ((string) $creation->getAttribute('status') === 'completed') {
            try {
                $this->walletCost->recordAfterCompletion($creation);
            } catch (\Throwable $e) {
                report($e);
                Log::warning('fal.webhook.wallet_cost_failed', [
                    'type' => $type,
                    'creation_id' => $creation->getKey(),
                    'error' => $e->getMessage(),
                ]);
            }
        } elseif ((string) $creation->getAttribute('status') === 'failed') {
            try {
                $this->finalizeFailure($type, $creation);
            } catch (\Throwable $e) {
                report($e);
                Log::warning('fal.webhook.failure_finalize_failed', [
                    'type' => $type,
                    'creation_id' => $creation->getKey(),
                    'error' => $e->getMessage(),
                ]);
            }
        }
    }

    private function finalizeFailure(string $type, Model $creation): void
    {
        $this->failureRefund->finalize($type, $creation, false);
    }

    /**
     * @return array{0: string, 1: Model}|null
     */
    public function findByRequestId(string $requestId): ?array
    {
        $map = [
            'image' => UserImageCreation::class,
            'video' => UserVideoCreation::class,
            'music' => UserMusicCreation::class,
            'voice' => UserVoiceCreation::class,
        ];

        foreach ($map as $type => $class) {
            /** @var Model|null $row */
            $row = $class::query()->where('fal_request_id', $requestId)->first();
            if ($row !== null) {
                return [$type, $row];
            }
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function fail(string $type, Model $creation, array $payload): void
    {
        $message = $this->friendlyError($type, $payload);
        $errorType = is_string($payload['error_type'] ?? null)
            ? $payload['error_type']
            : 'fal_error';

        if (method_exists($creation, 'markFailed')) {
            $creation->markFailed($message, $errorType);
        }

        Log::info('fal.webhook.failed_creation', [
            'type' => $type,
            'creation_id' => $creation->getKey(),
            'request_id' => $creation->getAttribute('fal_request_id'),
            'error' => $message,
            'error_type' => $errorType,
        ]);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function friendlyError(string $type, array $payload): string
    {
        $raw = '';
        if (is_string($payload['error'] ?? null) && $payload['error'] !== '') {
            $raw = $payload['error'];
        }

        $detail = $payload['payload']['detail'] ?? null;
        if (is_array($detail)) {
            $parts = [];
            foreach ($detail as $item) {
                if (is_array($item) && is_string($item['msg'] ?? null)) {
                    $parts[] = $item['msg'];
                } elseif (is_string($item)) {
                    $parts[] = $item;
                }
            }
            if ($parts !== []) {
                $raw = $raw !== '' ? $raw.' — '.implode('; ', $parts) : implode('; ', $parts);
            }
        }

        $raw = trim($raw);
        if ($raw === '') {
            return __('messages.generation_failed');
        }

        // Keep toast readable; full detail stays in logs.
        return mb_strlen($raw) > 280 ? mb_substr($raw, 0, 277).'…' : $raw;
    }

    /**
     * @param  array<string, mixed>  $result
     */
    private function completeImage(Model $creation, array $result): void
    {
        $images = [];
        $raw = $result['images'] ?? [];
        if (is_array($raw)) {
            foreach ($raw as $img) {
                if (! is_array($img) || empty($img['url']) || ! is_string($img['url'])) {
                    continue;
                }
                $images[] = [
                    'url' => $img['url'],
                    'content_type' => $img['content_type'] ?? null,
                    'width' => $img['width'] ?? null,
                    'height' => $img['height'] ?? null,
                ];
            }
        }

        if ($images === []) {
            $this->fail('image', $creation, [
                'error' => __('messages.no_image'),
                'error_type' => 'empty_result',
            ]);

            return;
        }

        $creation->forceFill([
            'status' => UserImageCreation::STATUS_COMPLETED,
            'result_assets' => $images,
            'result_preview_url' => $images[0]['url'] ?? null,
            'progress_message' => 'Completed',
            'queue_position' => null,
            'completed_at' => now(),
            'error_message' => null,
            'error_type' => null,
        ])->save();

        Log::info('fal.webhook.completed', [
            'type' => 'image',
            'creation_id' => $creation->getKey(),
            'request_id' => $creation->getAttribute('fal_request_id'),
            'assets' => count($images),
        ]);
    }

    /**
     * @param  array<string, mixed>  $result
     */
    private function completeVideo(Model $creation, array $result): void
    {
        $video = $this->extractVideo($result);
        if ($video === null) {
            $this->fail('video', $creation, [
                'error' => __('messages.no_video'),
                'error_type' => 'empty_result',
            ]);

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

        Log::info('fal.webhook.completed', [
            'type' => 'video',
            'creation_id' => $creation->getKey(),
            'request_id' => $creation->getAttribute('fal_request_id'),
        ]);
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
     * @param  array<string, mixed>  $result
     */
    private function completeMusic(Model $creation, array $result): void
    {
        $audioUrl = $this->extractAudioUrl($result);
        if ($audioUrl === null) {
            $this->fail('music', $creation, [
                'error' => __('messages.no_audio'),
                'error_type' => 'empty_result',
            ]);

            return;
        }

        $duration = null;
        foreach (['audio', 'audio_file', 'music', 'output'] as $key) {
            $node = $result[$key] ?? null;
            if (is_array($node) && isset($node['duration'])) {
                $duration = (int) round((float) $node['duration']);
                break;
            }
        }

        $coverUrl = null;
        foreach (['image', 'cover', 'thumbnail'] as $key) {
            $node = $result[$key] ?? null;
            if (is_array($node) && is_string($node['url'] ?? null) && $node['url'] !== '') {
                $coverUrl = $node['url'];
                break;
            }
        }

        $creation->forceFill([
            'status' => UserMusicCreation::STATUS_COMPLETED,
            'result_audio_url' => $audioUrl,
            'result_preview_url' => $audioUrl,
            'cover_url' => $coverUrl,
            'result_assets' => [
                [
                    'url' => $audioUrl,
                    'content_type' => 'audio/mpeg',
                    'duration' => $duration,
                ],
            ],
            'duration_seconds' => $duration ?? $creation->getAttribute('duration_seconds'),
            'progress_message' => 'Completed',
            'queue_position' => null,
            'completed_at' => now(),
            'error_message' => null,
            'error_type' => null,
        ])->save();

        Log::info('fal.webhook.completed', [
            'type' => 'music',
            'creation_id' => $creation->getKey(),
            'request_id' => $creation->getAttribute('fal_request_id'),
        ]);
    }

    /**
     * @param  array<string, mixed>  $result
     */
    private function completeVoice(Model $creation, array $result): void
    {
        $audioUrl = $this->extractAudioUrl($result);
        if ($audioUrl === null) {
            $this->fail('voice', $creation, [
                'error' => __('messages.no_audio'),
                'error_type' => 'empty_result',
            ]);

            return;
        }

        $duration = null;
        $audio = $result['audio'] ?? null;
        if (is_array($audio) && isset($audio['duration'])) {
            $duration = (int) round((float) $audio['duration']);
        }

        $creation->forceFill([
            'status' => UserVoiceCreation::STATUS_COMPLETED,
            'result_audio_url' => $audioUrl,
            'result_preview_url' => $audioUrl,
            'result_assets' => [
                [
                    'url' => $audioUrl,
                    'content_type' => is_array($audio) ? ($audio['content_type'] ?? 'audio/mpeg') : 'audio/mpeg',
                    'duration' => $duration,
                ],
            ],
            'duration_seconds' => $duration,
            'progress_message' => 'Completed',
            'queue_position' => null,
            'completed_at' => now(),
            'error_message' => null,
            'error_type' => null,
        ])->save();

        Log::info('fal.webhook.completed', [
            'type' => 'voice',
            'creation_id' => $creation->getKey(),
            'request_id' => $creation->getAttribute('fal_request_id'),
        ]);
    }

    /**
     * @param  array<string, mixed>  $result
     */
    private function extractAudioUrl(array $result): ?string
    {
        foreach (['audio', 'audio_file', 'music', 'output'] as $key) {
            $node = $result[$key] ?? null;
            if (is_array($node) && is_string($node['url'] ?? null) && $node['url'] !== '') {
                return $node['url'];
            }
            if (is_string($node) && str_starts_with($node, 'http')) {
                return $node;
            }
        }

        if (is_string($result['audio_url'] ?? null) && $result['audio_url'] !== '') {
            return $result['audio_url'];
        }
        if (is_string($result['url'] ?? null) && $result['url'] !== '') {
            return $result['url'];
        }

        return null;
    }

    public function broadcastSnapshot(string $type, Model $creation): void
    {
        $this->broadcast($type, $creation);
    }

    /**
     * Presented creation payload (same shape the UI receives via Pusher).
     *
     * @return array<string, mixed>
     */
    public function snapshot(string $type, Model $creation): array
    {
        return $this->present($type, $creation);
    }

    private function broadcast(string $type, Model $creation): void
    {
        $userId = (int) $creation->getAttribute('user_id');
        if ($userId <= 0) {
            return;
        }

        try {
            event(new CreationUpdated(
                userId: $userId,
                type: $type,
                creationId: (int) $creation->getKey(),
                payload: $this->present($type, $creation),
            ));
        } catch (\Throwable $e) {
            report($e);
            Log::warning('fal.webhook.broadcast_failed', [
                'type' => $type,
                'creation_id' => $creation->getKey(),
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function present(string $type, Model $creation): array
    {
        $base = [
            'id' => (int) $creation->getKey(),
            'type' => $type,
            'status' => $creation->getAttribute('status'),
            'queue_position' => $creation->getAttribute('queue_position'),
            'progress_message' => $creation->getAttribute('progress_message'),
            'progress_percent' => LabCreationPresenter::progressPercent($creation),
            'error' => $creation->getAttribute('error_message'),
            'prompt' => $creation->getAttribute('prompt'),
            'model_name' => $creation->getAttribute('model_name'),
            'preview_url' => $creation->getAttribute('result_preview_url'),
            'created_at' => optional($creation->getAttribute('created_at'))?->toIso8601String(),
            'token_balance' => (int) (User::query()->whereKey($creation->getAttribute('user_id'))->value('tokens') ?? 0),
        ];

        return match ($type) {
            'image' => array_merge($base, [
                'images' => collect($creation->getAttribute('result_assets') ?? [])
                    ->pluck('url')
                    ->filter()
                    ->values()
                    ->all(),
            ]),
            'video' => array_merge($base, [
                'video_url' => $creation->getAttribute('result_video_url'),
                'thumbnail_url' => $creation->getAttribute('thumbnail_url'),
                'aspect' => $creation->getAttribute('aspect_ratio'),
                'mode' => $creation->getAttribute('mode'),
            ]),
            'music' => array_merge($base, [
                'audio_url' => $creation->getAttribute('result_audio_url'),
                'cover_url' => $creation->getAttribute('cover_url'),
                'title' => $creation->getAttribute('title'),
                'lyrics' => $creation->getAttribute('lyrics'),
                'instrumental' => (bool) $creation->getAttribute('instrumental'),
                'duration' => $creation->getAttribute('duration_seconds'),
            ]),
            'voice' => array_merge($base, [
                'audio_url' => $creation->getAttribute('result_audio_url'),
                'voice' => $creation->getAttribute('voice'),
                'duration' => $creation->getAttribute('duration_seconds'),
            ]),
            default => $base,
        };
    }
}
