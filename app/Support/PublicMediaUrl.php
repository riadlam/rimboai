<?php

namespace App\Support;

/**
 * Build browser-safe public URLs that work on any host (local / production).
 * Prefer relative /storage/... paths over URLs baked with APP_URL / localhost.
 */
final class PublicMediaUrl
{
    /**
     * @return non-empty-string
     */
    public static function storagePath(string $relativePath): string
    {
        $relative = ltrim(str_replace('\\', '/', $relativePath), '/');

        return '/storage/'.$relative;
    }

    /**
     * Turn absolute app/localhost URLs into relative paths; leave external CDNs alone.
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

        if (! str_starts_with($url, 'http://') && ! str_starts_with($url, 'https://')) {
            return str_starts_with($url, '/') ? $url : '/'.$url;
        }

        $parts = parse_url($url);
        if (! is_array($parts)) {
            return $url;
        }

        $host = strtolower((string) ($parts['host'] ?? ''));
        $path = (string) ($parts['path'] ?? '');
        $query = isset($parts['query']) && $parts['query'] !== '' ? '?'.$parts['query'] : '';

        // Local /storage assets should never hardcode a host
        if ($path !== '' && str_starts_with($path, '/storage/')) {
            return $path.$query;
        }

        $localHosts = ['localhost', '127.0.0.1', '::1', '0.0.0.0'];
        $appHost = strtolower((string) parse_url((string) config('app.url'), PHP_URL_HOST));

        $isAppHost = $host !== '' && (
            in_array($host, $localHosts, true)
            || ($appHost !== '' && ($host === $appHost || str_ends_with($host, '.'.$appHost)))
        );

        if ($isAppHost && $path !== '') {
            return $path.$query;
        }

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
}
