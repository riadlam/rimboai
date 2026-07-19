<?php

namespace App\Services;

use App\Models\User;
use App\Models\UserImageCreation;
use App\Models\UserMusicCreation;
use App\Models\UserVideoCreation;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class TrendsFeedService
{
    /**
     * Public completed community creations for /trends.
     *
     * @return list<array<string, mixed>>
     */
    public function feed(int $limit = 120): array
    {
        $limit = max(1, min(200, $limit));

        return \Illuminate\Support\Facades\Cache::remember(
            "trends.feed.v1.{$limit}",
            now()->addSeconds(45),
            fn () => $this->buildFeed($limit),
        );
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function buildFeed(int $limit): array
    {
        $images = $this->publicCompletedQuery(UserImageCreation::class)
            ->limit($limit)
            ->get();

        $videos = $this->publicCompletedQuery(UserVideoCreation::class)
            ->limit($limit)
            ->get();

        $music = $this->publicCompletedQuery(UserMusicCreation::class)
            ->limit($limit)
            ->get();

        $items = collect()
            ->merge($images->map(fn (UserImageCreation $c) => $this->mapImage($c)))
            ->merge($videos->map(fn (UserVideoCreation $c) => $this->mapVideo($c)))
            ->merge($music->map(fn (UserMusicCreation $c) => $this->mapMusic($c)))
            ->filter()
            ->values();

        return $items
            ->sort(function (array $a, array $b) {
                if ((bool) $a['featured'] !== (bool) $b['featured']) {
                    return (bool) $b['featured'] <=> (bool) $a['featured'];
                }
                if ($a['uses'] !== $b['uses']) {
                    return $b['uses'] <=> $a['uses'];
                }

                return strcmp((string) ($b['created_at'] ?? ''), (string) ($a['created_at'] ?? ''));
            })
            ->take($limit)
            ->values()
            ->all();
    }

    /**
     * @param  class-string<Model>  $modelClass
     * @return \Illuminate\Database\Eloquent\Builder<Model>
     */
    private function publicCompletedQuery(string $modelClass)
    {
        /** @var Model $model */
        $model = new $modelClass;
        $table = $model->getTable();

        $query = $modelClass::query()
            ->with('user:id,name,email')
            ->notDiscarded()
            ->where('is_public', true)
            ->where('status', $modelClass::STATUS_COMPLETED);

        if (\Illuminate\Support\Facades\Schema::hasColumn($table, 'is_featured')) {
            $query->orderByDesc('is_featured');
        }

        if (\Illuminate\Support\Facades\Schema::hasColumn($table, 'uses_count')) {
            $query->orderByDesc('uses_count');
        }

        return $query->orderByDesc('completed_at')->orderByDesc('id');
    }

    /**
     * Columns live in migrations — never ALTER here on request path.
     *
     * @deprecated No-op kept for older call sites.
     */
    private function ensureTrendColumns(): void
    {
        // intentionally empty
    }

    /**
     * @deprecated use ensureTrendColumns
     */
    private function ensureUsesCountColumns(): void
    {
        // intentionally empty
    }

    /**
     * @return array{item: array<string, mixed>, uses: int}|null
     */
    public function useTemplate(string $type, int $id): ?array
    {
        $this->ensureUsesCountColumns();

        $creation = $this->findPublicCompleted($type, $id);
        if (! $creation) {
            return null;
        }

        $creation->increment('uses_count');
        $creation->refresh();

        $item = match ($type) {
            'image' => $this->mapImage($creation->loadMissing('user:id,name,email')),
            'video' => $this->mapVideo($creation->loadMissing('user:id,name,email')),
            'music' => $this->mapMusic($creation->loadMissing('user:id,name,email')),
            default => null,
        };

        if (! $item) {
            return null;
        }

        return [
            'item' => $item,
            'uses' => (int) $creation->uses_count,
        ];
    }

    public function findPublicCompleted(string $type, int $id): ?Model
    {
        return match ($type) {
            'image' => UserImageCreation::query()
                ->whereKey($id)
                ->notDiscarded()
                ->where('is_public', true)
                ->where('status', UserImageCreation::STATUS_COMPLETED)
                ->first(),
            'video' => UserVideoCreation::query()
                ->whereKey($id)
                ->notDiscarded()
                ->where('is_public', true)
                ->where('status', UserVideoCreation::STATUS_COMPLETED)
                ->first(),
            'music' => UserMusicCreation::query()
                ->whereKey($id)
                ->notDiscarded()
                ->where('is_public', true)
                ->where('status', UserMusicCreation::STATUS_COMPLETED)
                ->first(),
            default => null,
        };
    }

    /**
     * Full workspace for /trends/{type}-{id}: example media + upload slots + locked settings.
     *
     * @return array<string, mixed>|null
     */
    public function workspace(string $type, int $id): ?array
    {
        $creation = $this->findPublicCompleted($type, $id);
        if (! $creation) {
            return null;
        }

        $creation->loadMissing('user:id,name,email');

        $card = match ($type) {
            'image' => $this->mapImage($creation),
            'video' => $this->mapVideo($creation),
            'music' => $this->mapMusic($creation),
            default => null,
        };

        if (! $card) {
            return null;
        }

        $mode = (string) ($creation->mode ?? '');
        if (str_starts_with($mode, 'tool:')) {
            return null;
        }

        $settings = is_array($creation->settings) ? $creation->settings : [];
        $inputAssets = $this->normalizeInputAssets(
            is_array($creation->input_assets) ? $creation->input_assets : null
        );

        $uploads = [];
        foreach ($inputAssets as $index => $asset) {
            $kind = $asset['kind'];
            $n = $index + 1;
            $uploads[] = [
                'key' => "asset_{$index}",
                'kind' => $kind,
                'label_key' => match ($kind) {
                    'video' => 'uploadVideo',
                    'audio' => 'uploadAudio',
                    default => 'uploadImage',
                },
                'label' => match ($kind) {
                    'video' => $n > 1 ? "Video {$n}" : 'Upload video',
                    'audio' => $n > 1 ? "Audio {$n}" : 'Upload audio',
                    default => $n > 1 ? "Image {$n}" : 'Upload image',
                },
                'accept' => match ($kind) {
                    'video' => 'video/*',
                    'audio' => 'audio/*',
                    default => 'image/*',
                },
                'required' => true,
            ];
        }

        // Image / I2V templates with no stored assets still need a reference image slot.
        if (
            $uploads === []
            && (
                $type === 'image'
                || str_contains($mode, 'image-to-video')
                || str_contains($mode, 'reference-to-video')
            )
        ) {
            $uploads[] = [
                'key' => 'asset_0',
                'kind' => 'image',
                'label_key' => 'uploadImage',
                'label' => 'Upload image',
                'accept' => 'image/*',
                'required' => true,
            ];
        }

        $locked = match ($type) {
            'image' => [
                'prompt' => (string) ($creation->prompt ?? ''),
                'endpoint_id' => $creation->endpoint_id,
                'model_name' => $creation->model_name,
                'aspect' => $settings['aspect'] ?? '1:1',
                'resolution' => $settings['resolution'] ?? '1K',
                'quantity' => max(1, min(4, (int) ($settings['quantity'] ?? 1))),
                'image_mode' => ($settings['mode'] ?? null) === 'variations' ? 'variations' : 'create',
                'mode' => $mode !== '' ? $mode : 'text-to-image',
            ],
            'video' => [
                'prompt' => (string) ($creation->prompt ?? ''),
                'endpoint_id' => $creation->endpoint_id,
                'model_name' => $creation->model_name,
                'aspect' => $creation->aspect_ratio ?? ($settings['aspect'] ?? '16:9'),
                'resolution' => $creation->resolution ?? ($settings['resolution'] ?? '720p'),
                'duration' => $creation->duration_value
                    ?? ($settings['duration'] ?? ($creation->duration_seconds !== null ? (string) $creation->duration_seconds : '5')),
                'audio' => (bool) ($creation->with_audio ?? $settings['audio'] ?? true),
                'mode' => $mode !== '' ? $mode : 'text-to-video',
                'frame_mode' => $settings['frame_mode'] ?? null,
            ],
            'music' => [
                'prompt' => (string) ($creation->prompt ?? ''),
                'lyrics' => $creation->lyrics,
                'endpoint_id' => $creation->endpoint_id,
                'model_name' => $creation->model_name,
                'mode' => $mode !== '' ? $mode : 'text-to-music',
            ],
            default => [],
        };

        $trendCost = $creation->trend_cost ?? null;
        $credits = ($trendCost !== null && is_numeric($trendCost) && (float) $trendCost > 0)
            ? (int) round((float) $trendCost)
            : 0;

        $user = auth()->user();
        $userRemakes = $user
            ? $this->userRemakesForTrend($type, $id, (int) $user->id)
            : ['count' => 0, 'latest' => null];

        return [
            'key' => "{$type}-{$id}",
            'type' => $type,
            'creation_id' => $id,
            'template' => $card,
            'uploads' => $uploads,
            'locked' => $locked,
            'credits' => $credits,
            /** Completed remakes by the current user from this template. */
            'user_remake_count' => $userRemakes['count'],
            /** Latest completed remake preview — used to replace example when count > 1. */
            'user_latest' => $userRemakes['latest'],
            'generate_url' => match ($type) {
                'image' => '/lab/image/generate',
                'video' => '/lab/video/generate',
                'music' => '/lab/music/generate',
                default => '/lab/video/generate',
            },
            'lab_href' => match ($type) {
                'image' => '/lab?type=text-to-image',
                'video' => '/lab?type=text-to-video',
                'music' => '/lab?type=text-to-music',
                default => '/lab',
            },
        ];
    }

    /**
     * Completed remakes the signed-in user made from this Trends template.
     *
     * @return array{count: int, latest: array<string, mixed>|null}
     */
    public function userRemakesForTrend(string $type, int $trendId, int $userId): array
    {
        $modelClass = match ($type) {
            'image' => UserImageCreation::class,
            'video' => UserVideoCreation::class,
            'music' => UserMusicCreation::class,
            default => null,
        };

        if ($modelClass === null) {
            return ['count' => 0, 'latest' => null];
        }

        $query = $modelClass::query()
            ->where('user_id', $userId)
            ->where('status', $modelClass::STATUS_COMPLETED)
            ->where('settings->from_trend_id', $trendId)
            ->orderByDesc('id');

        $count = (clone $query)->count();
        if ($count === 0) {
            return ['count' => 0, 'latest' => null];
        }

        /** @var Model $latest */
        $latest = $query->first();
        if (! $latest) {
            return ['count' => $count, 'latest' => null];
        }

        $payload = match ($type) {
            'video' => [
                'id' => $latest->id,
                'video_url' => $latest->result_video_url,
                'preview_url' => $latest->result_preview_url ?: $latest->result_video_url,
                'thumbnail_url' => $latest->thumbnail_url,
            ],
            'image' => (static function () use ($latest): array {
                $assets = is_array($latest->result_assets) ? $latest->result_assets : [];
                $urls = collect($assets)
                    ->map(fn ($a) => is_array($a) ? ($a['url'] ?? null) : (is_string($a) ? $a : null))
                    ->filter()
                    ->values()
                    ->all();

                return [
                    'id' => $latest->id,
                    'preview_url' => $latest->result_preview_url ?: ($urls[0] ?? null),
                    'images' => $urls,
                ];
            })(),
            'music' => [
                'id' => $latest->id,
                'audio_url' => $latest->result_audio_url,
                'preview_url' => $latest->result_preview_url,
                'cover_url' => $latest->cover_url,
            ],
            default => null,
        };

        return ['count' => $count, 'latest' => $payload];
    }

    /**
     * @param  array<int, mixed>|null  $assets
     * @return list<array{url: string, kind: string, name: string|null}>
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

            $type = (string) ($asset['type'] ?? $asset['kind'] ?? 'image');
            $kind = in_array($type, ['image', 'video', 'audio'], true) ? $type : 'image';
            $name = $asset['original_name'] ?? $asset['name'] ?? null;

            $out[] = [
                'url' => $candidates[0],
                'kind' => $kind,
                'name' => is_string($name) ? $name : null,
            ];
        }

        return $out;
    }

    /**
     * Owner-only visibility / featured flags.
     *
     * @return array<string, mixed>|null
     */
    public function setVisibility(User $user, string $type, int $id, ?bool $isPublic = null, ?bool $isFeatured = null): ?array
    {
        $this->ensureTrendColumns();

        $creation = match ($type) {
            'image' => UserImageCreation::query()->whereKey($id)->where('user_id', $user->id)->first(),
            'video' => UserVideoCreation::query()->whereKey($id)->where('user_id', $user->id)->first(),
            'music' => UserMusicCreation::query()->whereKey($id)->where('user_id', $user->id)->first(),
            default => null,
        };

        if (! $creation) {
            return null;
        }

        if ($creation->status !== $creation::STATUS_COMPLETED) {
            return null;
        }

        $payload = [];
        if ($isPublic !== null) {
            $payload['is_public'] = $isPublic;
            if ($isPublic === false) {
                $payload['is_featured'] = false;
            }
            // Freeze the Trends token price from the original charge when publishing.
            if ($isPublic === true) {
                $existingTrendCost = $creation->getAttribute('trend_cost');
                if ($existingTrendCost === null || ! is_numeric($existingTrendCost) || (float) $existingTrendCost <= 0) {
                    $charged = $creation->getAttribute('credits_charged');
                    if ($charged !== null && is_numeric($charged) && (float) $charged > 0) {
                        $payload['trend_cost'] = (int) round((float) $charged);
                    }
                }
            }
        }
        if ($isFeatured !== null) {
            $payload['is_featured'] = $isFeatured;
            // Featured items must be public to appear on Trends
            if ($isFeatured) {
                $payload['is_public'] = true;
                $existingTrendCost = $creation->getAttribute('trend_cost');
                if (
                    ($existingTrendCost === null || ! is_numeric($existingTrendCost) || (float) $existingTrendCost <= 0)
                    && ! isset($payload['trend_cost'])
                ) {
                    $charged = $creation->getAttribute('credits_charged');
                    if ($charged !== null && is_numeric($charged) && (float) $charged > 0) {
                        $payload['trend_cost'] = (int) round((float) $charged);
                    }
                }
            }
        }

        if ($payload === []) {
            return null;
        }

        $creation->forceFill($payload)->save();

        return [
            'type' => $type,
            'id' => $creation->id,
            'is_public' => (bool) $creation->is_public,
            'is_featured' => (bool) ($creation->is_featured ?? false),
            'trend_cost' => $creation->trend_cost !== null ? (int) round((float) $creation->trend_cost) : null,
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    private function mapImage(UserImageCreation $creation): ?array
    {
        $assets = is_array($creation->result_assets) ? $creation->result_assets : [];
        $samples = $this->sampleUrls($assets);
        $cover = $creation->result_preview_url ?: ($samples[0] ?? null);
        if (! $cover) {
            return null;
        }

        $settings = is_array($creation->settings) ? $creation->settings : [];
        $uses = (int) ($creation->uses_count ?? 0);

        return $this->baseCard($creation, 'image', [
            'name' => $this->trendDisplayTitle($creation, 'Image'),
            'category' => 'Images',
            'cover' => $cover,
            'coverType' => 'image',
            'samples' => $samples ?: [$cover],
            'description' => 'Community image creation',
            'prompt' => (string) ($creation->prompt ?? ''),
            'aspect' => $settings['aspect'] ?? '1:1',
            'resolution' => $settings['resolution'] ?? '1K',
            'quantity' => max(1, min(4, (int) ($settings['quantity'] ?? 1))),
            'image_mode' => ($settings['mode'] ?? null) === 'variations' ? 'variations' : 'create',
            'duration' => null,
            'generate_audio' => null,
            'lyrics' => null,
            'featured' => (bool) ($creation->is_featured ?? false),
            'hot' => $uses >= 5,
        ]);
    }

    /**
     * @return array<string, mixed>|null
     */
    private function mapVideo(UserVideoCreation $creation): ?array
    {
        $videoUrl = $creation->result_video_url;
        $cover = $creation->thumbnail_url
            ?: $creation->result_preview_url
            ?: $videoUrl;
        if (! $cover && ! $videoUrl) {
            return null;
        }

        $settings = is_array($creation->settings) ? $creation->settings : [];
        $uses = (int) ($creation->uses_count ?? 0);
        $assets = is_array($creation->result_assets) ? $creation->result_assets : [];
        $samples = $this->sampleUrls($assets);
        if ($cover && ! in_array($cover, $samples, true)) {
            array_unshift($samples, $cover);
        }

        $duration = $creation->duration_value
            ?? ($settings['duration'] ?? ($creation->duration_seconds !== null ? (string) $creation->duration_seconds : '5'));

        $audio = $creation->with_audio;
        if ($audio === null && array_key_exists('audio', $settings)) {
            $audio = (bool) $settings['audio'];
        }

        return $this->baseCard($creation, 'video', [
            'name' => $this->trendDisplayTitle($creation, 'Video'),
            'category' => 'Videos',
            // Prefer a still thumbnail for the cover; keep the playable URL on video_url.
            'cover' => ($creation->thumbnail_url ?: $creation->result_preview_url) ?: ($videoUrl ?: $cover),
            'coverType' => $videoUrl ? 'video' : 'image',
            'video_url' => $videoUrl,
            'thumbnail_url' => $creation->thumbnail_url ?: $creation->result_preview_url,
            'samples' => $samples ?: array_values(array_filter([$cover, $videoUrl])),
            'description' => 'Community video creation',
            'prompt' => (string) ($creation->prompt ?? ''),
            'aspect' => $creation->aspect_ratio ?? ($settings['aspect'] ?? '16:9'),
            'resolution' => $creation->resolution ?? ($settings['resolution'] ?? '720p'),
            'duration' => $duration,
            'generate_audio' => (bool) ($audio ?? true),
            'quantity' => 1,
            'image_mode' => null,
            'lyrics' => null,
            'featured' => (bool) ($creation->is_featured ?? false),
            'hot' => $uses >= 5,
        ]);
    }

    /**
     * @return array<string, mixed>|null
     */
    private function mapMusic(UserMusicCreation $creation): ?array
    {
        $audioUrl = $creation->result_audio_url ?: $creation->result_preview_url;
        if (! $audioUrl) {
            return null;
        }

        $cover = $creation->cover_url ?: $creation->result_preview_url ?: $audioUrl;
        $uses = (int) ($creation->uses_count ?? 0);
        $manual = trim((string) ($creation->trend_title ?? ''));
        if ($manual !== '') {
            $title = $manual;
        } else {
            $title = trim((string) ($creation->title ?: ''));
            if ($title === '') {
                $title = 'Track';
            }
        }

        return $this->baseCard($creation, 'music', [
            'name' => $title,
            'category' => 'Music',
            'cover' => $cover,
            'coverType' => 'audio',
            'audio_url' => $audioUrl,
            'samples' => array_values(array_filter([$cover])),
            'description' => 'Community music creation',
            'prompt' => (string) ($creation->prompt ?? ''),
            'lyrics' => $creation->lyrics,
            'aspect' => null,
            'resolution' => null,
            'duration' => $creation->duration_seconds,
            'generate_audio' => null,
            'quantity' => 1,
            'image_mode' => null,
            'featured' => (bool) ($creation->is_featured ?? false),
            'hot' => $uses >= 5,
        ]);
    }

    /**
     * @param  array<string, mixed>  $extra
     * @return array<string, mixed>
     */
    private function baseCard(Model $creation, string $type, array $extra): array
    {
        /** @var User|null $user */
        $user = $creation->relationLoaded('user') ? $creation->user : null;
        $username = $user?->name ?: 'Creator';
        $uses = (int) ($creation->uses_count ?? 0);

        return array_merge([
            'id' => "{$type}-{$creation->id}",
            'creation_id' => $creation->id,
            'type' => $type,
            'creator' => $username,
            'avatar' => $this->avatarUrl($username),
            'uses' => $uses,
            'rating' => null,
            'credits' => (int) round((float) (
                ($creation->trend_cost ?? null) !== null && (float) $creation->trend_cost > 0
                    ? $creation->trend_cost
                    : 0
            )),
            'model' => $creation->model_name ?: 'AI Model',
            'endpoint_id' => $creation->endpoint_id,
            'trend_title' => $this->manualTrendTitle($creation),
            'created_at' => $creation->completed_at?->toIso8601String()
                ?: $creation->created_at?->toIso8601String(),
        ], $extra);
    }

    private function manualTrendTitle(Model $creation): ?string
    {
        $manual = trim((string) ($creation->trend_title ?? ''));

        return $manual !== '' ? $manual : null;
    }

    private function trendDisplayTitle(Model $creation, string $fallback): string
    {
        return $this->manualTrendTitle($creation) ?? $fallback;
    }

    /**
     * @param  list<mixed>  $assets
     * @return list<string>
     */
    private function sampleUrls(array $assets): array
    {
        $urls = [];
        foreach ($assets as $asset) {
            if (! is_array($asset) || empty($asset['url'])) {
                continue;
            }
            $urls[] = (string) $asset['url'];
        }

        return array_values(array_unique($urls));
    }

    private function avatarUrl(string $name): string
    {
        $initials = collect(preg_split('/\s+/', trim($name)) ?: [])
            ->filter()
            ->take(2)
            ->map(fn (string $part) => Str::upper(Str::substr($part, 0, 1)))
            ->implode('');

        if ($initials === '') {
            $initials = 'U';
        }

        $hash = abs(crc32(Str::lower($name)));
        $palette = ['FF5733', 'F59E0B', '10B981', '3B82F6', '8B5CF6', 'EC4899', '14B8A6', 'F97316'];
        $bg = $palette[$hash % count($palette)];

        return 'https://ui-avatars.com/api/?name='.urlencode($initials)
            .'&background='.$bg
            .'&color=fff&bold=true&size=128&format=png';
    }
}
