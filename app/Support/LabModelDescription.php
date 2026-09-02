<?php

namespace App\Support;

/**
 * Resolve locale-aware Lab model / voice / example descriptions.
 * English lives in `description`; translations in `description_fr` / `description_ar`.
 * Fal sync must only write English into `description` and never clear FR/AR.
 */
final class LabModelDescription
{
    public static function resolve(object|array $row, ?string $locale = null): string
    {
        $locale = $locale ?? app()->getLocale();
        $en = self::field($row, 'description');
        $fr = self::field($row, 'description_fr');
        $ar = self::field($row, 'description_ar');

        return match ($locale) {
            'fr' => $fr !== '' ? $fr : $en,
            'ar' => $ar !== '' ? $ar : $en,
            default => $en,
        };
    }

    /**
     * @param  object|array<string, mixed>  $row
     */
    private static function field(object|array $row, string $key): string
    {
        $value = is_array($row) ? ($row[$key] ?? null) : ($row->{$key} ?? null);

        return is_string($value) ? trim($value) : '';
    }
}
