<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

class TrendTemplate extends Model
{
    /**
     * Default Trends R2V path: Higgsfield Genjutsu Motion Transfer
     * (fal character sheets + Genjutsu motion transfer).
     */
    public const DEFAULT_ENDPOINT = 'higgsfield/genjutsu/motion-transfer/v1.0';

    /** Fal Seedance fallback (catalog-backed). */
    public const FALLBACK_ENDPOINT = 'bytedance/seedance-2.5/reference-to-video';

    /** Secondary fal fallback if Seedance catalog row is missing. */
    public const SECONDARY_FALLBACK_ENDPOINT = 'minimax/h3/reference-to-video';

    public const DEFAULT_SHEET_ENDPOINT = 'fal-ai/nano-banana-pro/edit';

    /**
     * Character-sheet-only Trends product (Nano Banana Pro edit).
     * No motion sketch / video — upload a photo, get a multi-angle sheet.
     */
    public const CHARACTER_SHEET_ENDPOINT = 'fal-ai/nano-banana-pro/edit';

    /** MiniMax H3 product R2V (no section split / no face sheets). */
    public const WORKFLOW_H3_DIRECT = 'h3_direct';

    /** MiniMax H3 Sogni-style section split + FlashVSR. */
    public const WORKFLOW_H3_SPLIT = 'h3_split';

    protected $fillable = [
        'title',
        'slug',
        'description',
        'cover_url',
        'is_published',
        'is_featured',
        'sort_order',
        'uses_count',
        'endpoint_id',
        'workflow',
        'model_name',
        'prompt',
        'prompt_editable',
        'aspect_ratio',
        'resolution',
        'duration',
        'generate_audio',
        'fal_estimate_usd',
        'trend_cost',
        'locked_assets',
        'slots',
        'sheet_endpoint_id',
        'sheet_prompt',
    ];

    protected function casts(): array
    {
        return [
            'is_published' => 'boolean',
            'is_featured' => 'boolean',
            'generate_audio' => 'boolean',
            'prompt_editable' => 'boolean',
            'sort_order' => 'integer',
            'uses_count' => 'integer',
            'trend_cost' => 'integer',
            'fal_estimate_usd' => 'float',
            'locked_assets' => 'array',
            'slots' => 'array',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (self $model): void {
            if (! filled($model->slug) && filled($model->title)) {
                $model->slug = static::uniqueSlug((string) $model->title);
            }
            if (! filled($model->endpoint_id)) {
                $model->endpoint_id = self::DEFAULT_ENDPOINT;
            }
            if (! filled($model->sheet_endpoint_id)) {
                $model->sheet_endpoint_id = self::DEFAULT_SHEET_ENDPOINT;
            }
            if ($model->slots === null) {
                $model->slots = self::defaultSlots();
            }
            if (! filled($model->prompt)) {
                $model->prompt = self::defaultPromptScaffold();
            }
            if (! filled($model->sheet_prompt)) {
                $model->sheet_prompt = self::defaultSheetPrompt();
            }
        });

        static::saved(fn () => static::bustFeedCache());
        static::deleted(fn () => static::bustFeedCache());
    }

    public static function bustFeedCache(): void
    {
        foreach ([60, 120, 200] as $limit) {
            Cache::forget("trends.feed.v3.{$limit}");
            Cache::forget("trends.feed.v2.{$limit}");
        }
    }

    public function scopePublished(Builder $query): Builder
    {
        return $query->where('is_published', true);
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function clientSlots(): array
    {
        $slots = is_array($this->slots) ? $this->slots : [];
        $out = [];
        foreach ($slots as $index => $slot) {
            if (! is_array($slot)) {
                continue;
            }
            $kind = (string) ($slot['kind'] ?? 'image');
            if ($kind !== 'image') {
                continue;
            }
            $out[] = [
                'key' => (string) ($slot['key'] ?? "face_{$index}"),
                'kind' => 'image',
                'label' => (string) ($slot['label'] ?? 'Upload photo'),
                'role' => (string) ($slot['role'] ?? 'extra'),
                'hint' => (string) ($slot['hint'] ?? ''),
                'accept' => (string) ($slot['accept'] ?? 'image/*'),
                'required' => (bool) ($slot['required'] ?? true),
            ];
        }

        return $out;
    }

    public function motionSketchUrl(): ?string
    {
        foreach (is_array($this->locked_assets) ? $this->locked_assets : [] as $asset) {
            if (! is_array($asset)) {
                continue;
            }
            $role = (string) ($asset['role'] ?? '');
            $kind = (string) ($asset['kind'] ?? '');
            $url = $asset['url'] ?? null;
            if ($role === 'motion_sketch' && is_string($url) && $url !== '') {
                return $url;
            }
            if ($kind === 'video' && is_string($url) && $url !== '') {
                return $url;
            }
        }

        return null;
    }

    public function optionalAudioUrl(): ?string
    {
        foreach (is_array($this->locked_assets) ? $this->locked_assets : [] as $asset) {
            if (! is_array($asset)) {
                continue;
            }
            if (($asset['role'] ?? '') === 'audio' || ($asset['kind'] ?? '') === 'audio') {
                $url = $asset['url'] ?? null;
                if (is_string($url) && $url !== '') {
                    return $url;
                }
            }
        }

        return null;
    }

    public function feedKey(): string
    {
        return 'template-'.$this->getKey();
    }

    public static function isCharacterSheetEndpoint(?string $endpointId): bool
    {
        $id = strtolower(trim((string) $endpointId));
        if ($id === '') {
            return false;
        }

        return $id === strtolower(self::CHARACTER_SHEET_ENDPOINT)
            || ($id === 'fal-ai/nano-banana-pro' || str_ends_with($id, 'nano-banana-pro/edit'));
    }

    public function isCharacterSheetTemplate(): bool
    {
        return self::isCharacterSheetEndpoint($this->endpoint_id);
    }

    /**
     * @return list<array<string, mixed>>
     */
    public static function characterSheetSlots(): array
    {
        return [
            [
                'key' => 'subject',
                'kind' => 'image',
                'label' => 'Your photo',
                'role' => 'subject',
                'hint' => 'Clear face + outfit photo. Becomes a multi-angle character sheet.',
                'accept' => 'image/*',
                'required' => true,
            ],
        ];
    }

    /**
     * Product packshot remake (MiniMax H3 direct R2V).
     *
     * @return list<array<string, mixed>>
     */
    public static function productSlots(): array
    {
        return [
            [
                'key' => 'product',
                'kind' => 'image',
                'label' => 'Your product photo',
                'role' => 'product',
                'hint' => 'Clear packshot of your bottle / product. Becomes @Image1 — edit the prompt with your brand name & colors.',
                'accept' => 'image/*',
                'required' => true,
            ],
        ];
    }

    public function isPromptEditable(): bool
    {
        return (bool) $this->prompt_editable;
    }

    public function isH3DirectWorkflow(): bool
    {
        $workflow = strtolower(trim((string) $this->workflow));
        if ($workflow === self::WORKFLOW_H3_DIRECT) {
            return true;
        }
        if ($workflow === self::WORKFLOW_H3_SPLIT) {
            return false;
        }

        // Auto: product upload slots → direct R2V (not face-split).
        foreach ($this->clientSlots() as $slot) {
            if (($slot['role'] ?? '') === 'product') {
                return true;
            }
        }

        return false;
    }

    public static function uniqueSlug(string $title): string
    {
        $base = Str::slug($title) ?: 'trend-template';
        $slug = $base;
        $i = 2;
        while (static::query()->where('slug', $slug)->exists()) {
            $slug = $base.'-'.$i;
            $i++;
        }

        return $slug;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public static function defaultSlots(): array
    {
        return [
            [
                'key' => 'body_right',
                'kind' => 'image',
                'label' => 'Person on the right',
                'role' => 'body_right',
                'hint' => 'Clear full-body or portrait photo. Becomes @Image1 (RIGHT performer).',
                'accept' => 'image/*',
                'required' => true,
            ],
            [
                'key' => 'body_left',
                'kind' => 'image',
                'label' => 'Person on the left',
                'role' => 'body_left',
                'hint' => 'Clear full-body or portrait photo. Becomes @Image2 (LEFT performer).',
                'accept' => 'image/*',
                'required' => true,
            ],
        ];
    }

    public static function defaultPromptScaffold(): string
    {
        // Kapwing Hotel Lobby AI Trend prompt (verbatim structure), tags adapted for our pipeline:
        // https://www.kapwing.com/resources/how-to-do-the-hotel-lobby-ai-trend-with-seedance-2-5/
        // MiniMax H3 rewrites @ImageN/@Video1 → "Image N"/"Video 1" at submit time.
        return <<<'PROMPT'
Use the input video @Video1 only as the reference for motion, body movement, mouth movement, timing, and camera movement. It is a line drawing; the output is NOT line art. Ignore every piece of clothing and jewelry drawn in @Video1. Ignore all shading, grey tones, black fills, and background color from @Video1 — @Video1 must not influence the set color at all.

Create a photoreal video of the same performance. @Image1 stands on the RIGHT. @Image2 stands on the LEFT. @Image1: face exactly as shown in their character sheet, wearing the outfit exactly as shown in their character sheet. @Image2: face exactly as shown in their character sheet, wearing the outfit exactly as shown in their character sheet. Each person wears only what their character sheet shows: bare hands and bare wrists, with no rings, bracelets, watches, or chains unless their character sheet explicitly includes them. They perform every gesture, hand movement, head turn, body movement, and mouth movement of the two performers in @Video1, frame for frame and perfectly in time.

The set is a seamless burnt-orange studio backdrop, warm and saturated, filling the entire frame edge to edge, with a silver condenser microphone hanging on a thin cable between them. No hotel lobby, no windows, no furniture, no dark walls, no grey or shadowy background — only bright burnt-orange.

Only the performer who is mouthing / speaking in @Video1 should move their lips; the other keeps a closed or reacting mouth matching @Video1. Do not make both people lip-sync at once unless @Video1 shows both singing together.

Locked-off camera with the same framing as the input video. Warm studio lighting. Photoreal, sharp, clearly detailed faces, natural skin texture. 16:9 landscape. Same duration as @Video1. Generate as one continuous video with no separate scenes.
PROMPT;
    }

    /**
     * Adapt a Genjutsu/Seedance-style prompt for MiniMax H3 split:
     * keep creative direction, drop "character sheet" wording (users upload refs already),
     * and ensure @Audio1 is present for lip-sync timing.
     */
    public static function adaptPromptForH3Split(string $prompt): string
    {
        $prompt = trim($prompt);
        if ($prompt === '') {
            $prompt = self::defaultPromptScaffold();
        }

        $prompt = preg_replace('/\btheir character sheets?\b/i', 'their uploaded reference photo', $prompt) ?? $prompt;
        $prompt = preg_replace('/\bcharacter sheets?\b/i', 'uploaded reference photo', $prompt) ?? $prompt;
        $prompt = preg_replace(
            '/face exactly as shown in their uploaded reference photo, wearing the outfit exactly as shown in their uploaded reference photo/i',
            'face and outfit exactly as shown in their uploaded reference photo (Image N)',
            $prompt,
        ) ?? $prompt;

        if (! preg_match('/@Audio\d+|Audio\s+\d+/i', $prompt)) {
            $prompt .= "\n\nUse @Audio1 as the performance soundtrack for timing and lip sync. Match mouth shapes to @Audio1 together with @Video1. Keep the exact song timing; do not invent different lyrics or a different beat.";
        }

        if (! str_contains(strtolower($prompt), 'uploaded reference photo')
            && ! preg_match('/@Image\d+|Image\s+\d+/i', $prompt)) {
            $prompt .= "\n\n@Image1 and @Image2 are the identity/outfit reference photos uploaded by the user (already prepared sheets if they want). Use them for faces and wardrobe only.";
        }

        return trim($prompt);
    }

    public static function defaultSheetPrompt(): string
    {
        return <<<'PROMPT'
A photoreal character reference sheet of the person in the input image.

Layout: two rows on a plain light grey studio backdrop with soft, even lighting and no shadows on the backdrop.

TOP ROW, taking approximately the top 60% of the image: three large head-and-shoulders portraits side by side, pin sharp, with natural skin texture:
1. straight-on front view
2. three-quarter view
3. right-side profile

BOTTOM ROW: four smaller full-body views at the same scale, relaxed standing pose with arms at the sides:
1. front
2. three-quarter
3. right profile
4. back

Same person, same outfit, same lighting in all seven views. Preserve their face exactly as shown in the input image.

No text, labels, captions, or watermarks anywhere in the image.
PROMPT;
    }
}
