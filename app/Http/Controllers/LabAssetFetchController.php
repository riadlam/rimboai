<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Same-origin proxy so the lab UI can turn remote result / reference URLs into File uploads
 * (fal CDN CORS often blocks browser fetch). Supports HTTP Range so <video> can seek
 * without downloading the entire MP4 (needed on hosts without ffmpeg).
 */
class LabAssetFetchController extends Controller
{
    public function __invoke(Request $request): StreamedResponse|\Illuminate\Http\Response
    {
        $data = $request->validate([
            'url' => ['required', 'string', 'max:4096'],
        ]);

        $url = $this->normalizeUrl($data['url']);
        if ($url === null || ! $this->isAllowedUrl($url)) {
            return response('Asset URL is not allowed.', 422);
        }

        // Prefer reading local public disk when the URL points at /storage/...
        $local = $this->tryLocalStorage($url, $request->header('Range'));
        if ($local !== null) {
            return $local;
        }

        $headers = [
            'Accept' => '*/*',
            'User-Agent' => 'ChameleonLabAssetFetch/1.0',
        ];
        $range = $request->header('Range');
        if (is_string($range) && $range !== '') {
            $headers['Range'] = $range;
        }

        try {
            $remote = Http::timeout(180)
                ->withOptions([
                    'stream' => true,
                    'allow_redirects' => ['max' => 5],
                ])
                ->withHeaders($headers)
                ->get($url);
        } catch (\Throwable $e) {
            report($e);

            return response('Could not fetch asset.', 502);
        }

        $status = $remote->status();
        if (! in_array($status, [200, 206], true)) {
            return response('Could not fetch asset.', 502);
        }

        $contentType = $remote->header('Content-Type') ?: 'application/octet-stream';
        $contentType = Str::before($contentType, ';') ?: 'application/octet-stream';
        $body = $remote->toPsrResponse()->getBody();

        $outHeaders = [
            'Content-Type' => $contentType,
            'Cache-Control' => 'private, max-age=120',
            'X-Content-Type-Options' => 'nosniff',
            'Accept-Ranges' => 'bytes',
        ];

        foreach (['Content-Length', 'Content-Range', 'Content-Disposition'] as $h) {
            $value = $remote->header($h);
            if (is_string($value) && $value !== '') {
                $outHeaders[$h] = $value;
            }
        }

        return response()->stream(function () use ($body) {
            while (! $body->eof()) {
                echo $body->read(1024 * 64);
                if (function_exists('ob_flush')) {
                    @ob_flush();
                }
                flush();
            }
        }, $status, $outHeaders);
    }

    private function normalizeUrl(string $raw): ?string
    {
        $raw = trim($raw);
        if ($raw === '') {
            return null;
        }

        if (Str::startsWith($raw, '/')) {
            return url($raw);
        }

        if (! filter_var($raw, FILTER_VALIDATE_URL)) {
            return null;
        }

        $scheme = strtolower((string) parse_url($raw, PHP_URL_SCHEME));
        if (! in_array($scheme, ['http', 'https'], true)) {
            return null;
        }

        return $raw;
    }

    private function isAllowedUrl(string $url): bool
    {
        $host = strtolower((string) parse_url($url, PHP_URL_HOST));
        if ($host === '') {
            return false;
        }

        $appHost = strtolower((string) parse_url((string) config('app.url'), PHP_URL_HOST));
        if ($appHost !== '' && ($host === $appHost || Str::endsWith($host, '.'.$appHost))) {
            return true;
        }

        if (in_array($host, ['localhost', '127.0.0.1', '::1'], true)) {
            return true;
        }

        // fal CDN + delivery hosts (incl. nested subdomains)
        if ($host === 'fal.media' || Str::endsWith($host, '.fal.media')) {
            return true;
        }
        if ($host === 'fal.ai' || Str::endsWith($host, '.fal.ai')) {
            return true;
        }

        // Some fal uploads land on GCS / Cloudflare R2 style hosts
        if (Str::endsWith($host, '.googleusercontent.com')
            || Str::endsWith($host, '.googleapis.com')
            || Str::endsWith($host, '.r2.cloudflarestorage.com')
            || Str::endsWith($host, '.cloudflarestorage.com')) {
            return true;
        }

        return false;
    }

    private function tryLocalStorage(string $url, ?string $range): ?StreamedResponse
    {
        $path = (string) parse_url($url, PHP_URL_PATH);
        if ($path === '' || ! Str::startsWith($path, '/storage/')) {
            return null;
        }

        $relative = ltrim(Str::after($path, '/storage/'), '/');
        if ($relative === '' || Str::contains($relative, '..')) {
            return null;
        }

        if (! Storage::disk('public')->exists($relative)) {
            return null;
        }

        $fullPath = Storage::disk('public')->path($relative);
        $size = filesize($fullPath);
        if ($size === false) {
            return null;
        }

        $mime = Storage::disk('public')->mimeType($relative) ?: 'application/octet-stream';
        $start = 0;
        $end = $size - 1;
        $status = 200;

        if (is_string($range) && preg_match('/bytes=(\d*)-(\d*)/', $range, $m)) {
            if ($m[1] !== '') {
                $start = (int) $m[1];
            }
            if ($m[2] !== '') {
                $end = (int) $m[2];
            }
            $end = min($end, $size - 1);
            if ($start > $end || $start >= $size) {
                return response('Range Not Satisfiable', 416, [
                    'Content-Range' => "bytes */{$size}",
                ]);
            }
            $status = 206;
        }

        $length = $end - $start + 1;
        $headers = [
            'Content-Type' => $mime,
            'Cache-Control' => 'private, max-age=120',
            'X-Content-Type-Options' => 'nosniff',
            'Accept-Ranges' => 'bytes',
            'Content-Length' => (string) $length,
        ];
        if ($status === 206) {
            $headers['Content-Range'] = "bytes {$start}-{$end}/{$size}";
        }

        return response()->stream(function () use ($fullPath, $start, $length) {
            $handle = fopen($fullPath, 'rb');
            if ($handle === false) {
                return;
            }
            fseek($handle, $start);
            $remaining = $length;
            while ($remaining > 0 && ! feof($handle)) {
                $chunk = fread($handle, min(1024 * 64, $remaining));
                if ($chunk === false) {
                    break;
                }
                echo $chunk;
                $remaining -= strlen($chunk);
                if (function_exists('ob_flush')) {
                    @ob_flush();
                }
                flush();
            }
            fclose($handle);
        }, $status, $headers);
    }
}
