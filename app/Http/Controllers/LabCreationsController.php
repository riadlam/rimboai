<?php

namespace App\Http\Controllers;

use App\Models\UserImageCreation;
use App\Models\UserMusicCreation;
use App\Models\UserVideoCreation;
use App\Models\UserVoiceCreation;
use App\Services\FalWebhookProcessor;
use App\Services\LabCreationPresenter;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class LabCreationsController extends Controller
{
    public function index(Request $request, FalWebhookProcessor $processor): JsonResponse
    {
        $type = $request->validate([
            'type' => ['required', 'string', Rule::in([
                'text-to-image',
                'text-to-video',
                'text-to-music',
                'text-to-sound',
                'text-to-voice',
            ])],
        ])['type'];

        $userId = $request->user()->id;

        // One-shot catch-up if fal webhook was missed (no browser polling loop).
        $this->syncActiveCreations($userId, $type, $processor);

        return match ($type) {
            'text-to-image' => response()->json([
                'type' => $type,
                'images' => $this->loadImageCreations($userId),
            ]),
            'text-to-video' => response()->json([
                'type' => $type,
                'images' => $this->loadVideoCreations($userId),
            ]),
            'text-to-music', 'text-to-sound' => response()->json([
                'type' => $type,
                'tracks' => $this->loadMusicCreations($userId),
            ]),
            'text-to-voice' => response()->json([
                'type' => $type,
                'voices' => $this->loadVoiceCreations($userId),
            ]),
        };
    }

    /**
     * Batched live-status sync for active creations.
     *
     * One request updates several creations at once. Each fal HTTP call is throttled
     * server-side (shared across tabs/users), so scaling users does not scale fal traffic.
     */
    public function statusBatch(Request $request, FalWebhookProcessor $processor): JsonResponse
    {
        $data = $request->validate([
            'items' => ['required', 'array', 'min:1', 'max:8'],
            'items.*.type' => ['required', 'string', Rule::in(['image', 'video', 'music', 'voice'])],
            'items.*.id' => ['required', 'integer'],
        ]);

        $userId = $request->user()->id;
        $tokenBalance = (int) ($request->user()->tokens ?? 0);
        $creations = [];

        foreach ($data['items'] as $item) {
            $type = $item['type'];
            $class = match ($type) {
                'image' => UserImageCreation::class,
                'video' => UserVideoCreation::class,
                'music' => UserMusicCreation::class,
                'voice' => UserVoiceCreation::class,
                default => null,
            };
            if ($class === null) {
                continue;
            }

            /** @var Model|null $creation */
            $creation = $class::query()
                ->where('user_id', $userId)
                ->notDiscarded()
                ->whereKey($item['id'])
                ->first();
            if ($creation === null) {
                continue;
            }

            if (method_exists($creation, 'isTerminal') && ! $creation->isTerminal() && $creation->getAttribute('fal_request_id')) {
                $processor->syncFromFal($type, $creation);
                $creation->refresh();
            }

            $creations[] = $processor->snapshot($type, $creation);
        }

        return response()->json([
            'creations' => $creations,
            'token_balance' => $tokenBalance,
        ]);
    }

    /**
     * Soft-hide creations from Lab / History (delete hover + dismiss failed card).
     */
    public function discard(Request $request): JsonResponse
    {
        $data = $request->validate([
            'type' => ['required', 'string', Rule::in(['image', 'video', 'music', 'voice'])],
            'ids' => ['required', 'array', 'min:1', 'max:50'],
            'ids.*' => ['integer', 'min:1'],
        ]);

        $class = match ($data['type']) {
            'image' => UserImageCreation::class,
            'video' => UserVideoCreation::class,
            'music' => UserMusicCreation::class,
            'voice' => UserVoiceCreation::class,
        };

        $ids = array_values(array_unique(array_map('intval', $data['ids'])));
        $userId = $request->user()->id;

        $updated = $class::query()
            ->where('user_id', $userId)
            ->whereIn('id', $ids)
            ->notDiscarded()
            ->update(['discarded' => 1]);

        return response()->json([
            'ok' => true,
            'discarded' => $updated,
        ]);
    }

    private function syncActiveCreations(int $userId, string $labType, FalWebhookProcessor $processor): void
    {
        [$type, $class] = match ($labType) {
            'text-to-image' => ['image', UserImageCreation::class],
            'text-to-video' => ['video', UserVideoCreation::class],
            'text-to-music', 'text-to-sound' => ['music', UserMusicCreation::class],
            'text-to-voice' => ['voice', UserVoiceCreation::class],
            default => [null, null],
        };

        if ($type === null || $class === null) {
            return;
        }

        /** @var \Illuminate\Support\Collection<int, Model> $active */
        $active = $class::query()
            ->where('user_id', $userId)
            ->notDiscarded()
            ->whereIn('status', [
                $class::STATUS_PENDING,
                $class::STATUS_QUEUED,
                $class::STATUS_IN_PROGRESS,
            ])
            ->whereNotNull('fal_status_url')
            ->orderByDesc('id')
            ->limit(5)
            ->get();

        foreach ($active as $creation) {
            $processor->syncFromFal($type, $creation);
        }
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function loadImageCreations(int $userId): array
    {
        $creations = UserImageCreation::query()
            ->where('user_id', $userId)
            ->notDiscarded()
            ->orderByDesc('created_at')
            ->limit(120)
            ->get();

        $items = [];

        foreach ($creations as $creation) {
            $settings = is_array($creation->settings) ? $creation->settings : [];
            $aspect = $settings['aspect'] ?? '1:1';
            $quantity = max(1, min(4, (int) ($settings['quantity'] ?? 1)));
            $resolution = $settings['resolution'] ?? '1K';
            $imageMode = ($settings['mode'] ?? null) === 'variations' ? 'variations' : 'create';
            $inputAssets = $this->normalizeInputAssets(
                is_array($creation->input_assets) ? $creation->input_assets : null
            );
            $batchId = "creation-{$creation->id}";
            $createdMs = $creation->created_at?->getTimestampMs() ?? now()->getTimestampMs();
            $method = $creation->mode === 'image-to-image' ? 'image-to-image' : 'text-to-image';

            $reuseMeta = [
                'aspect' => $aspect,
                'resolution' => $resolution,
                'quantity' => $quantity,
                'image_mode' => $imageMode,
                'input_assets' => $inputAssets,
            ];

            if ($creation->status === UserImageCreation::STATUS_COMPLETED) {
                $assets = is_array($creation->result_assets) ? $creation->result_assets : [];
                if ($assets === [] && $creation->result_preview_url) {
                    $assets = [['url' => $creation->result_preview_url]];
                }

                foreach ($assets as $idx => $asset) {
                    if (! is_array($asset) || empty($asset['url'])) {
                        continue;
                    }

                    $items[] = $this->imageItem([
                        'id' => "{$creation->id}-{$idx}",
                        'creation_id' => $creation->id,
                        'batch_id' => $batchId,
                        'batch_index' => $idx,
                        'prompt' => $creation->prompt,
                        'src' => $asset['url'],
                        'favorite' => (bool) $creation->is_favorite,
                'is_public' => (bool) $creation->is_public,
                'is_featured' => (bool) ($creation->is_featured ?? false),
                        'created_at' => $creation->created_at?->toIso8601String(),
                        'method' => $method,
                        'model' => $creation->model_name,
                        'status' => 'completed',
                        ...$reuseMeta,
                    ]);
                }

                continue;
            }

            if ($creation->isTerminal()) {
                $items[] = $this->imageItem([
                    'id' => "{$creation->id}-0",
                    'creation_id' => $creation->id,
                    'batch_id' => $batchId,
                    'batch_index' => 0,
                    'prompt' => $creation->prompt,
                    'src' => '',
                    'favorite' => (bool) $creation->is_favorite,
                'is_public' => (bool) $creation->is_public,
                'is_featured' => (bool) ($creation->is_featured ?? false),
                    'created_at' => $creation->created_at?->toIso8601String(),
                    'method' => $method,
                    'model' => $creation->model_name,
                    'status' => $creation->status,
                    'error' => $creation->error_message,
                    ...$reuseMeta,
                ]);

                continue;
            }

            for ($i = 0; $i < $quantity; $i++) {
                $items[] = $this->imageItem([
                    'id' => "{$batchId}-{$i}",
                    'creation_id' => $creation->id,
                    'batch_id' => $batchId,
                    'batch_index' => $i,
                    'prompt' => $creation->prompt,
                    'src' => '',
                    'favorite' => (bool) $creation->is_favorite,
                'is_public' => (bool) $creation->is_public,
                'is_featured' => (bool) ($creation->is_featured ?? false),
                    'created_at' => $creation->created_at?->toIso8601String(),
                    'started_at' => $createdMs,
                    'method' => $method,
                    'model' => $creation->model_name,
                    'status' => $creation->status,
                    'progress' => $creation->progress_message,
                    'queue_position' => $creation->queue_position,
                    'progress_percent' => LabCreationPresenter::progressPercent($creation),
                    ...$reuseMeta,
                ]);
            }
        }

        return $items;
    }

    /**
     * Video lab reuses the image grid — cards for active + completed videos.
     *
     * @return array<int, array<string, mixed>>
     */
    private function loadVideoCreations(int $userId): array
    {
        $creations = UserVideoCreation::query()
            ->where('user_id', $userId)
            ->notDiscarded()
            ->where('mode', 'not like', 'tool:%')
            ->orderByDesc('created_at')
            ->limit(100)
            ->get();

        $items = [];

        foreach ($creations as $creation) {
            $batchId = "video-creation-{$creation->id}";
            $createdMs = $creation->created_at?->getTimestampMs() ?? now()->getTimestampMs();
            $settings = is_array($creation->settings) ? $creation->settings : [];
            $aspect = $creation->aspect_ratio ?? ($settings['aspect'] ?? '16:9');
            $resolution = $creation->resolution ?? ($settings['resolution'] ?? '720p');
            $duration = $creation->duration_value ?? ($settings['duration'] ?? null);
            $audio = $creation->with_audio;
            if ($audio === null && array_key_exists('audio', $settings)) {
                $audio = (bool) $settings['audio'];
            }
            $inputAssets = $this->normalizeInputAssets(
                is_array($creation->input_assets) ? $creation->input_assets : null
            );
            $mode = (string) ($creation->mode ?: 'text-to-video');
            $method = match ($mode) {
                'image-to-video' => 'image-to-video',
                'reference-to-video' => 'reference-to-video',
                default => 'text-to-video',
            };

            $reuseMeta = [
                'aspect' => $aspect,
                'resolution' => $resolution,
                'duration' => $duration,
                'audio' => $audio,
                'input_assets' => $inputAssets,
            ];

            if ($creation->status === UserVideoCreation::STATUS_COMPLETED) {
                $src = $creation->thumbnail_url
                    ?: $creation->result_preview_url
                    ?: $creation->result_video_url
                    ?: '';

                if ($src === '') {
                    continue;
                }

                $items[] = $this->imageItem([
                    'id' => "video-{$creation->id}",
                    'creation_id' => $creation->id,
                    'batch_id' => $batchId,
                    'batch_index' => 0,
                    'prompt' => $creation->prompt,
                    'src' => $src,
                    'video_url' => $creation->result_video_url,
                    'favorite' => (bool) $creation->is_favorite,
                'is_public' => (bool) $creation->is_public,
                'is_featured' => (bool) ($creation->is_featured ?? false),
                    'created_at' => $creation->created_at?->toIso8601String(),
                    'method' => $method,
                    'model' => $creation->model_name,
                    'status' => 'completed',
                    ...$reuseMeta,
                ]);

                continue;
            }

            if ($creation->isTerminal()) {
                $items[] = $this->imageItem([
                    'id' => "video-{$creation->id}",
                    'creation_id' => $creation->id,
                    'batch_id' => $batchId,
                    'batch_index' => 0,
                    'prompt' => $creation->prompt,
                    'src' => '',
                    'favorite' => (bool) $creation->is_favorite,
                'is_public' => (bool) $creation->is_public,
                'is_featured' => (bool) ($creation->is_featured ?? false),
                    'created_at' => $creation->created_at?->toIso8601String(),
                    'method' => $method,
                    'model' => $creation->model_name,
                    'status' => $creation->status,
                    'error' => $creation->error_message,
                    ...$reuseMeta,
                ]);

                continue;
            }

            $items[] = $this->imageItem([
                'id' => "{$batchId}-0",
                'creation_id' => $creation->id,
                'batch_id' => $batchId,
                'batch_index' => 0,
                'prompt' => $creation->prompt,
                'src' => '',
                'favorite' => (bool) $creation->is_favorite,
                'is_public' => (bool) $creation->is_public,
                'is_featured' => (bool) ($creation->is_featured ?? false),
                'created_at' => $creation->created_at?->toIso8601String(),
                'started_at' => $createdMs,
                'method' => $method,
                'model' => $creation->model_name,
                'status' => $creation->status,
                'progress' => $creation->progress_message,
                'queue_position' => $creation->queue_position,
                'progress_percent' => LabCreationPresenter::progressPercent($creation),
                ...$reuseMeta,
            ]);
        }

        return $items;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function loadMusicCreations(int $userId): array
    {
        return UserMusicCreation::query()
            ->where('user_id', $userId)
            ->notDiscarded()
            ->whereIn('status', [
                UserMusicCreation::STATUS_PENDING,
                UserMusicCreation::STATUS_QUEUED,
                UserMusicCreation::STATUS_IN_PROGRESS,
                UserMusicCreation::STATUS_COMPLETED,
                UserMusicCreation::STATUS_FAILED,
            ])
            ->orderByDesc('created_at')
            ->limit(100)
            ->get()
            ->map(function (UserMusicCreation $creation) {
                $duration = $creation->duration_seconds;
                $durationLabel = null;
                if (is_int($duration) && $duration > 0) {
                    $durationLabel = sprintf('%d:%02d', intdiv($duration, 60), $duration % 60);
                }

                $cover = $creation->cover_url ?: '';
                // Completed tracks without cover still show if they have audio
                if ($cover === '' && $creation->status === UserMusicCreation::STATUS_COMPLETED) {
                    $cover = $creation->result_preview_url ?: '';
                }

                return [
                    'id' => "track-{$creation->id}",
                    'creation_id' => $creation->id,
                    'title' => $creation->title ?: 'Untitled Track',
                    'style' => $creation->prompt ?? '',
                    'lyrics' => $creation->lyrics,
                    'cover' => $cover,
                    'favorite' => (bool) $creation->is_favorite,
                    'is_public' => (bool) $creation->is_public,
                'is_featured' => (bool) ($creation->is_featured ?? false),
                    'created_at' => $creation->created_at?->toIso8601String(),
                    'instrumental' => (bool) $creation->instrumental,
                    'model' => $creation->model_name,
                    'duration' => $durationLabel,
                    'audio_url' => $creation->result_audio_url,
                    'status' => $creation->status,
                    'progress' => $creation->progress_message,
                    'queue_position' => $creation->queue_position,
                    'progress_percent' => LabCreationPresenter::progressPercent($creation),
                    'error' => $creation->error_message,
                ];
            })
            ->filter(function (array $item) {
                if (in_array($item['status'], ['pending', 'queued', 'in_progress', 'failed'], true)) {
                    return true;
                }

                return ($item['audio_url'] ?? null) || ($item['cover'] ?? '') !== '';
            })
            ->values()
            ->all();
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function loadVoiceCreations(int $userId): array
    {
        return UserVoiceCreation::query()
            ->where('user_id', $userId)
            ->notDiscarded()
            ->whereIn('status', [
                UserVoiceCreation::STATUS_PENDING,
                UserVoiceCreation::STATUS_QUEUED,
                UserVoiceCreation::STATUS_IN_PROGRESS,
                UserVoiceCreation::STATUS_COMPLETED,
                UserVoiceCreation::STATUS_FAILED,
            ])
            ->orderByDesc('created_at')
            ->limit(100)
            ->get()
            ->map(function (UserVoiceCreation $creation) {
                $settings = is_array($creation->settings) ? $creation->settings : [];
                $text = $creation->prompt ?? '';

                return [
                    'id' => "voice-{$creation->id}",
                    'creation_id' => $creation->id,
                    'title' => mb_strlen($text) > 42 ? mb_substr($text, 0, 42).'…' : $text,
                    'text' => $text,
                    'voice' => $creation->voice_name ?? 'Unknown voice',
                    'favorite' => (bool) $creation->is_favorite,
                'is_public' => (bool) $creation->is_public,
                'is_featured' => (bool) ($creation->is_featured ?? false),
                    'created_at' => $creation->created_at?->toIso8601String(),
                    'model' => $creation->model_name,
                    'duration' => isset($creation->duration_seconds)
                        ? sprintf('%d:%02d', intdiv((int) $creation->duration_seconds, 60), (int) $creation->duration_seconds % 60)
                        : null,
                    'audio_url' => $creation->result_audio_url,
                    'gradient' => $settings['gradient'] ?? null,
                    'status' => $creation->status,
                    'progress' => $creation->progress_message,
                    'queue_position' => $creation->queue_position,
                    'progress_percent' => LabCreationPresenter::progressPercent($creation),
                    'error' => $creation->error_message,
                ];
            })
            ->values()
            ->all();
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function imageItem(array $data): array
    {
        return [
            'id' => $data['id'],
            'creation_id' => $data['creation_id'] ?? null,
            'batch_id' => $data['batch_id'] ?? null,
            'batch_index' => $data['batch_index'] ?? null,
            'prompt' => $data['prompt'] ?? '',
            'src' => $data['src'] ?? '',
            'favorite' => (bool) ($data['favorite'] ?? false),
            'is_public' => (bool) ($data['is_public'] ?? false),
            'is_featured' => (bool) ($data['is_featured'] ?? false),
            'created_at' => $data['created_at'] ?? null,
            'started_at' => $data['started_at'] ?? null,
            'aspect' => $data['aspect'] ?? '1:1',
            'resolution' => $data['resolution'] ?? null,
            'duration' => $data['duration'] ?? null,
            'audio' => array_key_exists('audio', $data) ? $data['audio'] : null,
            'quantity' => $data['quantity'] ?? null,
            'image_mode' => $data['image_mode'] ?? null,
            'input_assets' => $data['input_assets'] ?? [],
            'method' => $data['method'] ?? 'text-to-image',
            'model' => $data['model'] ?? null,
            'status' => $data['status'] ?? 'completed',
            'progress' => $data['progress'] ?? null,
            'queue_position' => $data['queue_position'] ?? null,
            'progress_percent' => $data['progress_percent'] ?? null,
            'error' => $data['error'] ?? null,
            'video_url' => $data['video_url'] ?? null,
        ];
    }

    /**
     * @param  array<int, mixed>|null  $assets
     * @return array<int, array{url: string, kind: string, name: string|null, fallback_urls: array<int, string>}>
     */
    private function normalizeInputAssets(?array $assets): array
    {
        if ($assets === null || $assets === []) {
            return [];
        }

        $out = [];
        foreach ($assets as $asset) {
            if (! is_array($asset)) {
                continue;
            }

            // Prefer fal CDN — local /storage may 404 without the public symlink
            $candidates = [];
            foreach (['fal_url', 'url', 'local_url'] as $key) {
                $value = $asset[$key] ?? null;
                if (is_string($value) && $value !== '' && ! in_array($value, $candidates, true)) {
                    $candidates[] = $value;
                }
            }
            if ($candidates === []) {
                continue;
            }

            $type = (string) ($asset['type'] ?? 'image');
            $kind = in_array($type, ['image', 'video', 'audio'], true) ? $type : 'image';
            $name = $asset['original_name'] ?? null;

            $out[] = [
                'url' => $candidates[0],
                'kind' => $kind,
                'name' => is_string($name) ? $name : null,
                'fallback_urls' => array_values(array_slice($candidates, 1)),
            ];
        }

        return $out;
    }
}
