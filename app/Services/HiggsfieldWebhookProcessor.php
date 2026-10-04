<?php

namespace App\Services;

use App\Models\UserVideoCreation;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * Higgsfield async completion callbacks + status poll sync.
 *
 * Maps Higgsfield payloads onto FalWebhookProcessor so refunds, wallet cost,
 * and CreationUpdated broadcasts stay shared.
 */
class HiggsfieldWebhookProcessor
{
    private const SYNC_THROTTLE_SECONDS = 4;

    public function __construct(
        private HiggsfieldService $higgsfield,
        private FalWebhookProcessor $falProcessor,
    ) {}

    /**
     * @param  array<string, mixed>  $payload
     */
    public function handle(array $payload): void
    {
        $requestId = isset($payload['request_id']) && is_string($payload['request_id'])
            ? $payload['request_id']
            : null;
        if ($requestId === null || $requestId === '') {
            Log::warning('higgsfield.webhook.missing_request_id');

            return;
        }

        $status = strtolower((string) ($payload['status'] ?? ''));
        $mapped = $this->mapToFalPayload($requestId, $status, $payload);
        if ($mapped === null) {
            Log::info('higgsfield.webhook.non_terminal', [
                'request_id' => $requestId,
                'status' => $status,
            ]);

            $this->applyNonTerminal($requestId, $status);

            return;
        }

        $this->falProcessor->handle($mapped);
    }

    public function sync(Model $creation): void
    {
        if (! $creation instanceof UserVideoCreation) {
            return;
        }

        if (method_exists($creation, 'isTerminal') && $creation->isTerminal()) {
            return;
        }

        $statusUrl = $creation->getAttribute('fal_status_url');
        $requestId = $creation->getAttribute('fal_request_id');
        if ((! is_string($statusUrl) || $statusUrl === '') && (! is_string($requestId) || $requestId === '')) {
            return;
        }

        $throttleKey = 'higgsfield_sync_throttle_video_'.$creation->getKey();
        if (Cache::get($throttleKey)) {
            return;
        }
        Cache::put($throttleKey, 1, self::SYNC_THROTTLE_SECONDS);

        try {
            $status = is_string($statusUrl) && $statusUrl !== ''
                ? $this->higgsfield->statusByUrl($statusUrl)
                : $this->higgsfield->status((string) $requestId);
        } catch (\Throwable $e) {
            report($e);
            Log::warning('higgsfield.sync.status_failed', [
                'creation_id' => $creation->getKey(),
                'error' => $e->getMessage(),
            ]);

            return;
        }

        $state = strtolower((string) ($status['status'] ?? ''));
        $rid = is_string($status['request_id'] ?? null)
            ? $status['request_id']
            : (string) $requestId;

        $mapped = $this->mapToFalPayload($rid, $state, $status);
        if ($mapped === null) {
            $this->applyNonTerminal($rid, $state, $creation);

            return;
        }

        $this->falProcessor->handle($mapped);
    }

    public static function isHiggsfieldCreation(Model $creation): bool
    {
        $provider = $creation->getAttribute('provider');
        if (is_string($provider) && strtolower($provider) === 'higgsfield') {
            return true;
        }

        $settings = $creation->getAttribute('settings');
        if (is_array($settings) && strtolower((string) ($settings['provider'] ?? '')) === 'higgsfield') {
            return true;
        }

        return HiggsfieldService::isHiggsfieldEndpoint(
            is_string($creation->getAttribute('endpoint_id'))
                ? $creation->getAttribute('endpoint_id')
                : null
        );
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>|null  Fal-shaped payload, or null for non-terminal
     */
    private function mapToFalPayload(string $requestId, string $status, array $payload): ?array
    {
        if (in_array($status, ['failed', 'nsfw', 'canceled', 'cancelled', 'error'], true)) {
            $error = $payload['error'] ?? null;
            if (! is_string($error) || $error === '') {
                $error = match ($status) {
                    'nsfw' => 'Generation blocked (NSFW).',
                    'canceled', 'cancelled' => 'Generation was canceled.',
                    default => 'Higgsfield generation failed.',
                };
            }

            return [
                'request_id' => $requestId,
                'status' => 'ERROR',
                'error' => $error,
                'error_type' => 'higgsfield_'.$status,
            ];
        }

        if ($status !== 'completed') {
            return null;
        }

        $videoUrl = $this->extractVideoUrl($payload);
        if ($videoUrl === null) {
            return [
                'request_id' => $requestId,
                'status' => 'ERROR',
                'error' => 'Higgsfield completed without a video URL.',
                'error_type' => 'empty_result',
            ];
        }

        return [
            'request_id' => $requestId,
            'status' => 'OK',
            'payload' => [
                'video' => [
                    'url' => $videoUrl,
                    'content_type' => 'video/mp4',
                ],
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function extractVideoUrl(array $payload): ?string
    {
        $nested = $payload['payload'] ?? null;
        if (is_array($nested)) {
            $video = $nested['video'] ?? null;
            if (is_array($video) && is_string($video['url'] ?? null) && $video['url'] !== '') {
                return $video['url'];
            }
            if (is_string($nested['video_url'] ?? null) && $nested['video_url'] !== '') {
                return $nested['video_url'];
            }
        }

        $video = $payload['video'] ?? null;
        if (is_array($video) && is_string($video['url'] ?? null) && $video['url'] !== '') {
            return $video['url'];
        }

        if (is_string($payload['video_url'] ?? null) && $payload['video_url'] !== '') {
            return $payload['video_url'];
        }

        return null;
    }

    private function applyNonTerminal(string $requestId, string $status, ?Model $creation = null): void
    {
        if ($creation === null) {
            $found = $this->falProcessor->findByRequestId($requestId);
            if ($found === null) {
                return;
            }
            [, $creation] = $found;
        }

        if (! $creation instanceof UserVideoCreation) {
            return;
        }

        if (method_exists($creation, 'isTerminal') && $creation->isTerminal()) {
            return;
        }

        if (in_array($status, ['queued', 'in_queue'], true)) {
            $creation->forceFill([
                'status' => UserVideoCreation::STATUS_QUEUED,
                'progress_message' => LabCreationPresenter::queueProgressMessage(
                    isset($creation->queue_position) ? (int) $creation->queue_position : null
                ),
            ])->save();
            $this->falProcessor->broadcastSnapshot('video', $creation->fresh() ?? $creation);

            return;
        }

        if (in_array($status, ['in_progress', 'processing', 'running'], true)) {
            if (method_exists($creation, 'markInProgress')) {
                $creation->markInProgress(null, __('messages.generating'));
            }
            $this->falProcessor->broadcastSnapshot('video', $creation->fresh() ?? $creation);
        }
    }
}
