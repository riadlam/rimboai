<?php

namespace App\Services;

use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\View;
use Illuminate\Support\Str;

class SeoService
{
    /**
     * Share SEO meta with the Blade layout (available before Inertia hydrates).
     *
     * @param  array<string, mixed>  $overrides
     */
    public function share(array $overrides = []): void
    {
        View::share('seo', $this->make($overrides));
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array{
     *   title: string,
     *   description: string,
     *   image: string,
     *   url: string,
     *   type: string,
     *   robots: string,
     *   site_name: string,
     *   twitter_card: string,
     *   twitter_handle: string|null,
     *   locale: string
     * }
     */
    public function make(array $overrides = []): array
    {
        $siteName = (string) config('seo.site_name', config('app.name', 'RIMBOAI'));

        $meta = array_merge([
            'title' => (string) config('seo.title', $siteName),
            'description' => (string) config('seo.description', ''),
            'image' => $this->absoluteUrl((string) config('seo.image', '/storage/ai_icons/music_home.jpg')),
            'url' => url()->current(),
            'type' => 'website',
            'robots' => 'index, follow',
            'site_name' => $siteName,
            'twitter_card' => 'summary_large_image',
            'twitter_handle' => config('seo.twitter_handle') ?: null,
            'locale' => (string) config('seo.locale', 'en_US'),
        ], $overrides);

        if (isset($meta['image']) && is_string($meta['image']) && ! str_starts_with($meta['image'], 'http')) {
            $meta['image'] = $this->absoluteUrl($meta['image']);
        }

        $meta['title'] = trim(strip_tags((string) $meta['title']));
        $meta['description'] = Str::limit(trim(strip_tags((string) $meta['description'])), 300, '…');

        if (! str_contains($meta['title'], $siteName) && $meta['title'] !== $siteName) {
            $meta['title'] = "{$meta['title']} — {$siteName}";
        }

        return $meta;
    }

  /**
     * Defaults for named public routes (overridden in controllers when dynamic).
     */
    public function forRoute(?string $routeName = null): array
    {
        $name = $routeName ?? Route::currentRouteName();

        return match ($name) {
            'home' => $this->make([
                'title' => (string) config('seo.title'),
                'description' => (string) config('seo.description'),
            ]),
            'pricing' => $this->make([
                'title' => 'Pricing & Token Packs',
                'description' => 'Transparent AI generation pricing. Buy token packs for video, image, voice, and music — pay only for what you create.',
            ]),
            'tools' => $this->make([
                'title' => 'AI Video Tools',
                'description' => 'Upscale, enhance, lip sync, face swap, denoise, and edit videos with specialized AI tools.',
            ]),
            'trends' => $this->make([
                'title' => 'Trends & Templates',
                'description' => 'Remix viral AI video, image, and music templates. One-click trends for social content.',
            ]),
            'innovation' => $this->make([
                'title' => 'Innovation & Prompt Ideas',
                'description' => 'Curated AI prompts and workflows for e-commerce, social media, and creative production.',
            ]),
            'lab' => $this->labMeta(),
            'history', 'settings', 'billing.history', 'login', 'register' => $this->make([
                'robots' => 'noindex, nofollow',
            ]),
            default => str_starts_with((string) $name, 'tools.')
                ? $this->toolMeta((string) $name)
                : $this->make(),
        };
    }

    private function labMeta(): array
    {
        $type = request()->query('type', 'text-to-video');

        return match ($type) {
            'text-to-image' => $this->make([
                'title' => 'Text to Image Lab',
                'description' => 'Generate studio-quality AI images from text. Multiple models, aspects, and resolutions.',
                'url' => url('/lab?type=text-to-image'),
            ]),
            'text-to-voice' => $this->make([
                'title' => 'Text to Voice Lab',
                'description' => 'Natural AI voiceovers and speech synthesis from text.',
                'url' => url('/lab?type=text-to-voice'),
            ]),
            'text-to-music', 'text-to-sound' => $this->make([
                'title' => 'Music & Sound Lab',
                'description' => 'Create AI music tracks and soundscapes from a text prompt.',
                'url' => url('/lab?type=text-to-music'),
            ]),
            default => $this->make([
                'title' => 'Text to Video Lab',
                'description' => 'Create cinematic AI videos from text and reference media. Kling, Veo, Wan, Seedance, and more.',
                'url' => url('/lab?type=text-to-video'),
            ]),
        };
    }

    private function toolMeta(string $routeName): array
    {
        $tools = collect(ToolsService::all())->keyBy('route');
        $tool = $tools->get($routeName);

        if (! $tool) {
            return $this->make();
        }

        return $this->make([
            'title' => $tool['name'],
            'description' => "Use {$tool['name']} on RIMBOAI — professional AI video processing in your browser.",
            'image' => $tool['poster'] ?? config('seo.image'),
            'type' => 'product',
        ]);
    }

    public function absoluteUrl(string $path): string
    {
        if ($path === '') {
            return url('/');
        }

        if (str_starts_with($path, 'http://') || str_starts_with($path, 'https://')) {
            return $path;
        }

        return url('/'.ltrim($path, '/'));
    }
}
