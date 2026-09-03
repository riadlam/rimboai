<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;

/**
 * Shared catalog cache keys for Lab brand lists.
 */
class CatalogCache
{
    /**
     * @return list<array{0: string, 1: string}>
     */
    public static function brandPairs(): array
    {
        return [
            ['text_to_video_models', 'text_to_video_categories'],
            ['image_to_video_models', 'image_to_video_categories'],
            ['text_to_image_models', 'text_to_image_categories'],
            ['text_to_music_models', 'text_to_music_categories'],
            ['text_to_voice_models', 'text_to_voice_categories'],
        ];
    }

    public static function brandsKey(string $locale, string $models, string $categories): string
    {
        return "catalog.brands.v5.{$locale}.{$models}.{$categories}";
    }

    public static function forgetBrands(): void
    {
        $locales = ['en', 'fr', 'ar'];
        foreach (self::brandPairs() as [$models, $categories]) {
            foreach ($locales as $locale) {
                Cache::forget(self::brandsKey($locale, $models, $categories));
            }
            Cache::forget("catalog.brands.v4.{$models}.{$categories}");
            Cache::forget("catalog.brands.v3.{$models}.{$categories}");
        }

        Cache::forget('inertia.token_packages.active');
    }
}
