<?php

namespace App\Services;

use Illuminate\Http\Client\RequestException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use InvalidArgumentException;
use RuntimeException;

/**
 * Server-side gateway to the fal.ai queue API.
 *
 * The FAL key lives only here (from config/services.php → env). It is never
 * returned to the client. The browser only ever talks to our own routes.
 */
class FalService
{
    private string $key;

    private string $queueBase = 'https://queue.fal.run';

    public function __construct()
    {
        $this->key = (string) config('services.fal.key', '');
    }

    public function configured(): bool
    {
        return $this->key !== '';
    }

    /**
     * Public HTTPS URL fal should POST when the job finishes.
     * Null on local http:// so submit still works without webhooks.
     */
    public function webhookUrl(): ?string
    {
        $configured = config('services.fal.webhook_url');
        if (is_string($configured) && $configured !== '') {
            $configured = trim($configured);
            if (str_starts_with($configured, 'https://')) {
                return $configured;
            }

            Log::warning('fal.webhook_url_ignored_not_https', ['url' => $configured]);

            return null;
        }

        $appUrl = rtrim((string) config('app.url'), '/');
        if (str_starts_with($appUrl, 'https://')) {
            return $appUrl.'/webhooks/fal';
        }

        return null;
    }

    /**
     * Submit a request to the queue. Returns fal payload including
     * request_id, status_url, response_url, queue_position.
     *
     * When an HTTPS webhook URL is available it is attached as ?fal_webhook=
     * so fal notifies us on completion (no long client polling required).
     *
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     *
     * @throws RequestException
     */
    public function submit(string $endpointId, array $input, ?string $webhookUrl = null): array
    {
        $url = "{$this->queueBase}/{$endpointId}";
        $hook = $webhookUrl ?? $this->webhookUrl();
        if (is_string($hook) && $hook !== '') {
            $url .= (str_contains($url, '?') ? '&' : '?').'fal_webhook='.rawurlencode($hook);
        }

        Log::info('fal.queue.submit', [
            'endpoint' => $endpointId,
            'webhook' => $hook ? true : false,
        ]);

        $response = Http::withHeaders($this->headers())
            ->timeout(30)
            ->post($url, $input);

        $response->throw();

        $json = $response->json() ?? [];

        Log::info('fal.queue.submitted', [
            'endpoint' => $endpointId,
            'request_id' => $json['request_id'] ?? null,
            'queue_position' => $json['queue_position'] ?? null,
            'webhook' => $hook ? true : false,
        ]);

        return $json;
    }

    /**
     * Poll status using the fal-provided status URL (safest for nested
     * endpoints whose status path differs from the submit path).
     *
     * @return array<string, mixed>
     *
     * @throws RequestException
     */
    public function statusByUrl(string $statusUrl): array
    {
        $this->assertFalUrl($statusUrl);

        $response = Http::withHeaders($this->headers())
            ->timeout(20)
            ->get($statusUrl, ['logs' => 1]);

        $response->throw();

        return $response->json() ?? [];
    }

    /**
     * Fetch the final result payload using the fal-provided response URL.
     *
     * @return array<string, mixed>
     *
     * @throws RequestException
     */
    public function resultByUrl(string $responseUrl): array
    {
        $this->assertFalUrl($responseUrl);

        $response = Http::withHeaders($this->headers())
            ->timeout(30)
            ->get($responseUrl);

        $response->throw();

        return $response->json() ?? [];
    }

    /**
     * Upload a reference file to fal CDN so inference works on localhost too.
     * Flow: initiate → PUT bytes → return public v3.fal.media URL.
     *
     * Shared hosts often mis-detect MP3 as application/octet-stream; we normalize
     * content_type from the file extension so fal accepts the upload.
     *
     * @throws RequestException|RuntimeException
     */
    public function uploadToCdn(UploadedFile $file): string
    {
        [$contentType, $ext] = $this->resolveUploadContentType($file);
        $filename = Str::uuid()->toString().'.'.$ext;
        $path = $file->getRealPath();

        if ($path === false || ! is_readable($path)) {
            throw new RuntimeException('Uploaded file is not readable on the server.');
        }

        $bytes = @file_get_contents($path);
        if ($bytes === false || $bytes === '') {
            throw new RuntimeException('Could not read the uploaded file from disk.');
        }

        return $this->uploadBytesToCdn($bytes, $filename, $contentType);
    }

    /**
     * Upload raw bytes to fal CDN (used for base64 audio that bypasses PHP multipart).
     *
     * @throws RequestException|RuntimeException
     */
    public function uploadBytesToCdn(string $bytes, string $filename, ?string $contentType = null): string
    {
        $ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION) ?: 'bin');
        $contentType = $contentType ?: $this->contentTypeFromExtension($ext);
        $safeName = Str::uuid()->toString().'.'.($ext !== '' ? $ext : 'bin');
        $size = strlen($bytes);
        $putTimeout = $size > 5 * 1024 * 1024 ? 180 : 90;

        $initResponse = Http::withHeaders([
            'Authorization' => 'Key '.$this->key,
            'Content-Type' => 'application/json',
            'Accept' => 'application/json',
        ])
            ->timeout(30)
            ->post('https://rest.fal.ai/storage/upload/initiate?storage_type=fal-cdn-v3', [
                'content_type' => $contentType,
                'file_name' => $safeName,
            ]);

        if (! $initResponse->successful()) {
            Log::warning('fal CDN initiate failed', [
                'status' => $initResponse->status(),
                'body' => substr($initResponse->body(), 0, 500),
                'content_type' => $contentType,
                'filename' => $safeName,
            ]);
            $initResponse->throw();
        }

        $init = $initResponse->json();
        $uploadUrl = is_array($init) ? ($init['upload_url'] ?? null) : null;
        $fileUrl = is_array($init) ? ($init['file_url'] ?? null) : null;

        if (! is_string($uploadUrl) || ! is_string($fileUrl) || $uploadUrl === '' || $fileUrl === '') {
            throw new RuntimeException('fal storage upload did not return URLs.');
        }

        $uploadResponse = Http::withHeaders([
            'Content-Type' => $contentType,
        ])
            ->timeout($putTimeout)
            ->withBody($bytes, $contentType)
            ->put($uploadUrl);

        if (! $uploadResponse->successful()) {
            Log::warning('fal CDN PUT failed', [
                'status' => $uploadResponse->status(),
                'body' => substr($uploadResponse->body(), 0, 500),
                'content_type' => $contentType,
                'size' => $size,
            ]);
            $uploadResponse->throw();
        }

        return $fileUrl;
    }

    private function contentTypeFromExtension(string $ext): string
    {
        return match ($ext) {
            'mp3', 'mpga', 'mpeg' => 'audio/mpeg',
            'wav' => 'audio/wav',
            'flac' => 'audio/flac',
            'ogg', 'oga' => 'audio/ogg',
            'm4a' => 'audio/mp4',
            'aac' => 'audio/aac',
            'mp4' => 'video/mp4',
            'mov' => 'video/quicktime',
            'webm' => 'video/webm',
            'jpg', 'jpeg' => 'image/jpeg',
            'png' => 'image/png',
            'webp' => 'image/webp',
            'gif' => 'image/gif',
            default => 'application/octet-stream',
        };
    }

    /**
     * @return array{0: string, 1: string}  [contentType, extension]
     */
    private function resolveUploadContentType(UploadedFile $file): array
    {
        $originalExt = strtolower((string) $file->getClientOriginalExtension());
        $guessedExt = strtolower((string) ($file->guessExtension() ?: ''));
        $ext = $originalExt !== '' ? $originalExt : ($guessedExt !== '' ? $guessedExt : 'bin');

        $byExt = [
            'mp3' => 'audio/mpeg',
            'mpga' => 'audio/mpeg',
            'mpeg' => 'audio/mpeg',
            'wav' => 'audio/wav',
            'flac' => 'audio/flac',
            'ogg' => 'audio/ogg',
            'oga' => 'audio/ogg',
            'm4a' => 'audio/mp4',
            'aac' => 'audio/aac',
            'mp4' => 'video/mp4',
            'mov' => 'video/quicktime',
            'webm' => 'video/webm',
            'jpg' => 'image/jpeg',
            'jpeg' => 'image/jpeg',
            'png' => 'image/png',
            'webp' => 'image/webp',
            'gif' => 'image/gif',
        ];

        $mime = (string) ($file->getMimeType() ?: '');

        // Shared hosts often report MP3/WAV as application/octet-stream.
        if ($mime === '' || $mime === 'application/octet-stream' || $mime === 'binary/octet-stream') {
            $mime = $byExt[$ext] ?? 'application/octet-stream';
        }

        // Normalize common audio aliases so fal gets a stable content_type.
        if (in_array($mime, ['audio/mp3', 'audio/x-mp3', 'audio/x-mpeg', 'audio/mpeg3'], true)) {
            $mime = 'audio/mpeg';
            $ext = 'mp3';
        } elseif (in_array($mime, ['audio/x-wav', 'audio/wave'], true)) {
            $mime = 'audio/wav';
            $ext = 'wav';
        } elseif (isset($byExt[$ext]) && str_starts_with($byExt[$ext], 'audio/') && ! str_starts_with($mime, 'audio/')) {
            // Extension says audio but finfo guessed something else — trust extension.
            $mime = $byExt[$ext];
        }

        if ($ext === 'bin' && isset($byExt[strtolower(pathinfo($file->getClientOriginalName(), PATHINFO_EXTENSION))])) {
            $ext = strtolower(pathinfo($file->getClientOriginalName(), PATHINFO_EXTENSION));
            $mime = $byExt[$ext];
        }

        return [$mime, $ext !== '' ? $ext : 'bin'];
    }

    /**
     * @return array<string, string>
     */
    private function headers(): array
    {
        return [
            'Authorization' => 'Key '.$this->key,
            'Content-Type' => 'application/json',
        ];
    }

    /**
     * Guard against SSRF: only allow fal-owned hosts.
     */
    private function assertFalUrl(string $url): void
    {
        $host = parse_url($url, PHP_URL_HOST);

        if (! is_string($host) || $host === '') {
            throw new InvalidArgumentException('Invalid fal URL.');
        }

        $host = strtolower($host);
        $allowed = $host === 'fal.run'
            || $host === 'fal.ai'
            || str_ends_with($host, '.fal.run')
            || str_ends_with($host, '.fal.ai');

        if (! $allowed) {
            throw new InvalidArgumentException('Untrusted host for fal URL.');
        }
    }
}
