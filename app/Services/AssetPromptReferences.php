<?php

namespace App\Services;

/**
 * Converts UI aliases such as @image1 into Fal-native @Image1 / @Video1 / @Audio1.
 *
 * The media URLs are still sent through each fal endpoint's real media fields;
 * Seedance (and similar) R2V prompts bind those ordered arrays via these tags.
 */
class AssetPromptReferences
{
    /**
     * @param  array{image?: int, video?: int, audio?: int}  $counts
     */
    public function resolve(string $prompt, array $counts): string
    {
        return preg_replace_callback(
            '/@(image|video|audio)([1-9]\d*)\b/i',
            static function (array $match) use ($counts): string {
                $kind = strtolower($match[1]);
                $index = (int) $match[2];
                $available = max(0, (int) ($counts[$kind] ?? 0));

                if ($index > $available) {
                    // Do not silently point an invalid alias at the wrong asset.
                    return $match[0];
                }

                return '@'.ucfirst($kind).$index;
            },
            $prompt,
        ) ?? $prompt;
    }
}
