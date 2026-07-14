<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Same-origin proxy so the lab UI can turn remote result / reference URLs into File uploads
 * (fal CDN CORS often blocks browser fetch). Reusable from History → Lab later.
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
        $local = $this->tryLocalStorage($url);
        if ($local !== null) {
            return $local;
        }

        try {
            $remote = Http::timeout(120)
                ->withOptions([
                    'stream' => true,
                    'allow_redirects' => ['max' => 5],
                ])
                ->withHeaders([
                    'Accept' => '*/*',
                    'User-Agent' => 'ChameleonLabAssetFetch/1.0',
                ])
                ->get($url);
        } catch (\Throwable $e) {
            report($e);

            return response('Could not fetch asset.', 502);
        }

        if (! $remote->successful()) {
            return response('Could not fetch asset.', 502);
        }

        $contentType = $remote->header('Content-Type') ?: 'application/octet-stream';
        $contentType = Str::before($contentType, ';') ?: 'application/octet-stream';
        $body = $remote->toPsrResponse()->getBody();

        return response()->stream(function () use ($body) {
            while (! $body->eof()) {
                echo $body->read(1024 * 64);
                if (function_exists('ob_flush')) {
                    @ob_flush();
                }
                flush();
            }
        }, 200, [
            'Content-Type' => $contentType,
            'Cache-Control' => 'private, max-age=120',
            'X-Content-Type-Options' => 'nosniff',
        ]);
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

    private function tryLocalStorage(string $url): ?StreamedResponse
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

        $mime = Storage::disk('public')->mimeType($relative) ?: 'application/octet-stream';
        $stream = Storage::disk('public')->readStream($relative);
        if ($stream === false) {
            return null;
        }

        return response()->stream(function () use ($stream) {
            fpassthru($stream);
            if (is_resource($stream)) {
                fclose($stream);
            }
        }, 200, [
            'Content-Type' => $mime,
            'Cache-Control' => 'private, max-age=120',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }
}
