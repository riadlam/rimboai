<?php

namespace App\Support;

/**
 * Build browser-safe public URLs that work on any host (local / production).
 * App-local assets use url()/APP_URL; external CDNs are left absolute.
 */
final class PublicMediaUrl
{
    /**
     * @return non-empty-string
     */
    public static function storagePath(string $relativePath): string
    {
        $relative = ltrim(str_replace('\\', '/', $relativePath), '/');

        return self::toAppUrl('/storage/'.$relative);
    }

    /**
     * Normalize a stored URL for the frontend.
     * - External CDNs (fal, etc.): unchanged
     * - Localhost / relative /storage paths: absolute via APP_URL
     */
    public static function normalize(?string $url): ?string
    {
        if (! is_string($url)) {
            return null;
        }

        $url = trim($url);
        if ($url === '') {
            return null;
        }

        // Relative / path-only
        if (! str_starts_with($url, 'http://') && ! str_starts_with($url, 'https://')) {
            $path = str_starts_with($url, '/') ? $url : '/'.$url;

            return self::toAppUrl($path);
        }

        $parts = parse_url($url);
        if (! is_array($parts)) {
            return $url;
        }

        $host = strtolower((string) ($parts['host'] ?? ''));
        $path = (string) ($parts['path'] ?? '');
        $query = isset($parts['query']) && $parts['query'] !== '' ? '?'.$parts['query'] : '';

        $localHosts = ['localhost', '127.0.0.1', '::1', '0.0.0.0'];
        $appHost = strtolower((string) parse_url((string) config('app.url'), PHP_URL_HOST));

        $isAppHost = $host !== '' && (
            in_array($host, $localHosts, true)
            || ($appHost !== '' && ($host === $appHost || str_ends_with($host, '.'.$appHost)))
        );

        // App /storage assets → absolute APP_URL (never leave as localhost)
        if ($path !== '' && str_starts_with($path, '/storage/')) {
            return self::toAppUrl($path.$query);
        }

        if ($isAppHost && $path !== '') {
            return self::toAppUrl($path.$query);
        }

        // External CDN — keep as-is
        return $url;
    }

    /**
     * Resolve a voice/music sample for the frontend.
     */
    public static function sample(?string $samplePath, ?string $sampleUrl, ?string $remoteUrl = null): ?string
    {
        if (is_string($samplePath) && trim($samplePath) !== '') {
            return self::storagePath($samplePath);
        }

        return self::normalize($sampleUrl) ?? self::normalize($remoteUrl);
    }

    /**
     * Prefix a path with APP_URL (respects ASSET_URL when set via url()/UrlGenerator).
     */
    public static function toAppUrl(string $path): string
    {
        $path = '/'.ltrim($path, '/');

        // asset() honors ASSET_URL; falls back to APP_URL
        return asset(ltrim($path, '/'));
    }
}
