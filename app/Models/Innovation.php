<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Innovation extends Model
{
    protected $fillable = [
        'innovation_category_id',
        'slug',
        'title',
        'prompt',
        'media_type',
        'image_url',
        'image_urls',
        'video_url',
        'audio_url',
        'model_name',
        'endpoint_id',
        'lab_type',
        'aspect_ratio',
        'resolution',
        'duration',
        'quantity',
        'generate_audio',
        'image_mode',
        'style_prompt',
        'settings',
        'sort',
        'status',
        'is_featured',
    ];

    protected function casts(): array
    {
        return [
            'image_urls' => 'array',
            'settings' => 'array',
            'is_featured' => 'boolean',
            'generate_audio' => 'boolean',
            'quantity' => 'integer',
        ];
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(InnovationCategory::class, 'innovation_category_id');
    }

    public function scopeActive($query)
    {
        return $query->where('status', 'active');
    }

    /**
     * Shape expected by the Innovation frontend.
     *
     * @return array<string, mixed>
     */
    public function toFrontend(): array
    {
        $category = $this->relationLoaded('category') ? $this->category : null;
        $settings = is_array($this->settings) ? $this->settings : [];

        $aspect = $this->aspect_ratio ?: ($settings['aspect'] ?? null);
        $resolution = $this->resolution ?: ($settings['resolution'] ?? null);
        $duration = $this->duration ?: ($settings['duration'] ?? null);
        $quantity = $this->quantity ?: ($settings['quantity'] ?? 1);
        $generateAudio = $this->generate_audio;
        if ($generateAudio === null && array_key_exists('audio', $settings)) {
            $generateAudio = (bool) $settings['audio'];
        }
        $imageMode = $this->image_mode ?: ($settings['image_mode'] ?? null);
        $stylePrompt = $this->style_prompt ?: ($settings['style'] ?? null);

        $gallery = [];
        if (is_array($this->image_urls)) {
            foreach ($this->image_urls as $url) {
                if (is_string($url) && $url !== '') {
                    $gallery[] = $url;
                }
            }
        }
        if ($this->image_url && ! in_array($this->image_url, $gallery, true)) {
            array_unshift($gallery, $this->image_url);
        }
        $gallery = array_values(array_unique($gallery));
        $primaryImage = $gallery[0] ?? $this->image_url;

        return [
            'id' => $this->slug,
            'db_id' => $this->id,
            'title' => $this->title,
            'category' => $category?->slug ?? 'other',
            'category_label' => $category?->name ?? 'Other',
            'media' => match ($this->media_type) {
                'video' => 'videos',
                'music' => 'music',
                default => 'images',
            },
            'media_type' => $this->media_type,
            'image' => $primaryImage,
            'images' => $gallery,
            'video' => $this->video_url,
            'audio' => $this->audio_url,
            'prompt' => $this->prompt,
            'model' => $this->model_name ?: 'AI Model',
            'endpoint_id' => $this->endpoint_id,
            'lab_type' => $this->lab_type,
            'aspect_ratio' => $aspect,
            'resolution' => $resolution,
            'duration' => $duration,
            'quantity' => (int) $quantity,
            'generate_audio' => $generateAudio,
            'image_mode' => $imageMode,
            'style_prompt' => $stylePrompt,
            'settings' => array_filter([
                ...$settings,
                'aspect' => $aspect,
                'resolution' => $resolution,
                'duration' => $duration,
                'quantity' => (int) $quantity,
                'audio' => $generateAudio,
                'image_mode' => $imageMode,
                'style' => $stylePrompt,
            ], static fn ($v) => $v !== null && $v !== ''),
            'gradient' => $category?->gradient,
            'is_featured' => (bool) $this->is_featured,
        ];
    }
}
