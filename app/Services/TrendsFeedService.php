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
        $this->ensureTrendColumns();

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

    private function ensureTrendColumns(): void
    {
        foreach (['user_image_creations', 'user_video_creations', 'user_music_creations'] as $table) {
            if (! \Illuminate\Support\Facades\Schema::hasTable($table)) {
                continue;
            }

            if (! \Illuminate\Support\Facades\Schema::hasColumn($table, 'uses_count')) {
                \Illuminate\Support\Facades\Schema::table($table, function (\Illuminate\Database\Schema\Blueprint $blueprint) {
                    $blueprint->unsignedInteger('uses_count')->default(0)->after('is_public');
                });
            }

            if (! \Illuminate\Support\Facades\Schema::hasColumn($table, 'is_featured')) {
                \Illuminate\Support\Facades\Schema::table($table, function (\Illuminate\Database\Schema\Blueprint $blueprint) {
                    $blueprint->boolean('is_featured')->default(false)->after('is_public');
                });
            }
        }
    }

    /**
     * @deprecated use ensureTrendColumns
     */
    private function ensureUsesCountColumns(): void
    {
        $this->ensureTrendColumns();
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
                ->where('is_public', true)
                ->where('status', UserImageCreation::STATUS_COMPLETED)
                ->first(),
            'video' => UserVideoCreation::query()
                ->whereKey($id)
                ->where('is_public', true)
                ->where('status', UserVideoCreation::STATUS_COMPLETED)
                ->first(),
            'music' => UserMusicCreation::query()
                ->whereKey($id)
                ->where('is_public', true)
                ->where('status', UserMusicCreation::STATUS_COMPLETED)
                ->first(),
            default => null,
        };
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
        }
        if ($isFeatured !== null) {
            $payload['is_featured'] = $isFeatured;
            // Featured items must be public to appear on Trends
            if ($isFeatured) {
                $payload['is_public'] = true;
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
            'name' => $this->titleFromPrompt($creation->prompt, 'Image'),
            'category' => 'Images',
            'cover' => $cover,
            'coverType' => 'image',
            'samples' => $samples ?: [$cover],
            'description' => trim((string) $creation->prompt) ?: 'Community image creation',
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
            'name' => $this->titleFromPrompt($creation->prompt, 'Video'),
            'category' => 'Videos',
            // Prefer a still thumbnail for the cover; keep the playable URL on video_url.
            'cover' => ($creation->thumbnail_url ?: $creation->result_preview_url) ?: ($videoUrl ?: $cover),
            'coverType' => $videoUrl ? 'video' : 'image',
            'video_url' => $videoUrl,
            'thumbnail_url' => $creation->thumbnail_url ?: $creation->result_preview_url,
            'samples' => $samples ?: array_values(array_filter([$cover, $videoUrl])),
            'description' => trim((string) $creation->prompt) ?: 'Community video creation',
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
        $title = trim((string) ($creation->title ?: ''));
        if ($title === '') {
            $title = $this->titleFromPrompt($creation->prompt, 'Track');
        }

        return $this->baseCard($creation, 'music', [
            'name' => $title,
            'category' => 'Music',
            'cover' => $cover,
            'coverType' => 'audio',
            'audio_url' => $audioUrl,
            'samples' => array_values(array_filter([$cover])),
            'description' => trim((string) ($creation->prompt ?: $creation->lyrics)) ?: 'Community music creation',
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
            'credits' => (int) round((float) ($creation->credits_charged ?? 0)),
            'model' => $creation->model_name ?: 'AI Model',
            'endpoint_id' => $creation->endpoint_id,
            'created_at' => $creation->completed_at?->toIso8601String()
                ?: $creation->created_at?->toIso8601String(),
        ], $extra);
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

    private function titleFromPrompt(?string $prompt, string $fallback): string
    {
        $text = trim((string) $prompt);
        if ($text === '') {
            return $fallback;
        }

        return Str::limit($text, 48, '…');
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
