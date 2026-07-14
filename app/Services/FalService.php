<?php

namespace App\Services;

use Illuminate\Http\Client\RequestException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
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
     * Submit a request to the queue. Returns fal payload including
     * request_id, status_url, response_url, queue_position.
     *
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     *
     * @throws RequestException
     */
    public function submit(string $endpointId, array $input): array
    {
        $response = Http::withHeaders($this->headers())
            ->timeout(30)
            ->post("{$this->queueBase}/{$endpointId}", $input);

        $response->throw();

        return $response->json() ?? [];
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
     * Upload a reference image to fal CDN so inference works on localhost too.
     * Flow: initiate → PUT bytes → return public v3.fal.media URL.
     *
     * @throws RequestException|RuntimeException
     */
    public function uploadToCdn(UploadedFile $file): string
    {
        $contentType = $file->getMimeType() ?: 'application/octet-stream';
        $filename = Str::uuid()->toString().'.'.strtolower($file->guessExtension() ?: 'bin');

        $initResponse = Http::withHeaders([
            'Authorization' => 'Key '.$this->key,
            'Content-Type' => 'application/json',
            'Accept' => 'application/json',
        ])
            ->timeout(30)
            ->post('https://rest.fal.ai/storage/upload/initiate?storage_type=fal-cdn-v3', [
                'content_type' => $contentType,
                'file_name' => $filename,
            ]);

        $initResponse->throw();

        $init = $initResponse->json();
        $uploadUrl = is_array($init) ? ($init['upload_url'] ?? null) : null;
        $fileUrl = is_array($init) ? ($init['file_url'] ?? null) : null;

        if (! is_string($uploadUrl) || ! is_string($fileUrl) || $uploadUrl === '' || $fileUrl === '') {
            throw new RuntimeException('fal storage upload did not return URLs.');
        }

        $uploadResponse = Http::withHeaders([
            'Content-Type' => $contentType,
        ])
            ->timeout(60)
            ->withBody((string) file_get_contents($file->getRealPath()), $contentType)
            ->put($uploadUrl);

        $uploadResponse->throw();

        return $fileUrl;
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
