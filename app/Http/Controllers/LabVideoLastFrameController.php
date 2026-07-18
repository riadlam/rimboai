<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * Extract the last visible frame of a remote lab video as a JPEG.
 * Preferred path for "Continue from last frame" (avoids browser downloading
 * the whole MP4 through /lab/asset-fetch, which often times out as "Failed to fetch").
 */
class LabVideoLastFrameController extends Controller
{
    public function __invoke(Request $request): BinaryFileResponse|\Illuminate\Http\JsonResponse
    {
        $data = $request->validate([
            'url' => ['required', 'string', 'max:4096'],
        ]);

        $url = $this->normalizeUrl($data['url']);
        if ($url === null || ! $this->isAllowedUrl($url)) {
            return response()->json(['message' => 'Video URL is not allowed.'], 422);
        }

        $ffmpeg = $this->ffmpegBinary();
        if ($ffmpeg === null) {
            return response()->json([
                'message' => 'Server frame capture is unavailable.',
                'code' => 'ffmpeg_missing',
            ], 501);
        }

        $videoPath = sys_get_temp_dir().DIRECTORY_SEPARATOR.'labvid_'.Str::random(12).'.mp4';
        $jpegPath = sys_get_temp_dir().DIRECTORY_SEPARATOR.'labfrm_'.Str::random(12).'.jpg';

        try {
            $remote = Http::timeout(180)
                ->withOptions([
                    'sink' => $videoPath,
                    'allow_redirects' => ['max' => 5],
                ])
                ->withHeaders([
                    'Accept' => '*/*',
                    'User-Agent' => 'ChameleonLabLastFrame/1.0',
                ])
                ->get($url);

            if (! $remote->successful() || ! is_file($videoPath) || filesize($videoPath) < 64) {
                return response()->json(['message' => 'Could not download video for frame capture.'], 502);
            }

            $extracted = $this->extractLastFrame($ffmpeg, $videoPath, $jpegPath);
            if (! $extracted || ! is_file($jpegPath) || filesize($jpegPath) < 32) {
                return response()->json(['message' => 'Could not extract the last frame.'], 502);
            }

            return response()->file($jpegPath, [
                'Content-Type' => 'image/jpeg',
                'Cache-Control' => 'private, no-store',
                'X-Content-Type-Options' => 'nosniff',
            ])->deleteFileAfterSend(true);
        } catch (\Throwable $e) {
            report($e);

            return response()->json(['message' => 'Could not capture the last frame.'], 502);
        } finally {
            if (is_file($videoPath)) {
                @unlink($videoPath);
            }
        }
    }

    private function extractLastFrame(string $ffmpeg, string $videoPath, string $jpegPath): bool
    {
        // Prefer seek-from-end (avoids needing an accurate duration).
        $attempts = [
            ['-sseof', '-0.35', '-i', $videoPath, '-frames:v', '1', '-q:v', '2', $jpegPath],
            ['-sseof', '-1', '-i', $videoPath, '-frames:v', '1', '-q:v', '2', $jpegPath],
        ];

        $duration = $this->probeDuration($ffmpeg, $videoPath);
        if ($duration !== null && $duration > 0.2) {
            $ss = max(0, $duration - 0.12);
            array_unshift($attempts, [
                '-ss', (string) $ss, '-i', $videoPath, '-frames:v', '1', '-q:v', '2', $jpegPath,
            ]);
        }

        foreach ($attempts as $args) {
            @unlink($jpegPath);
            $result = Process::timeout(120)->run(array_merge([
                $ffmpeg,
                '-hide_banner',
                '-loglevel', 'error',
                '-y',
            ], $args));

            if ($result->successful() && is_file($jpegPath) && filesize($jpegPath) >= 32) {
                return true;
            }
        }

        return false;
    }

    private function probeDuration(string $ffmpeg, string $videoPath): ?float
    {
        $ffprobe = $this->ffprobeBinary($ffmpeg);
        if ($ffprobe === null) {
            return null;
        }

        $result = Process::timeout(30)->run([
            $ffprobe,
            '-v', 'error',
            '-show_entries', 'format=duration',
            '-of', 'default=noprint_wrappers=1:nokey=1',
            $videoPath,
        ]);

        if (! $result->successful()) {
            return null;
        }

        $raw = trim($result->output());
        if ($raw === '' || ! is_numeric($raw)) {
            return null;
        }

        return (float) $raw;
    }

    private function ffprobeBinary(string $ffmpeg): ?string
    {
        $dir = dirname($ffmpeg);
        $candidate = $dir.DIRECTORY_SEPARATOR.(PHP_OS_FAMILY === 'Windows' ? 'ffprobe.exe' : 'ffprobe');
        if (is_executable($candidate)) {
            return $candidate;
        }

        $which = Process::timeout(5)->run(
            PHP_OS_FAMILY === 'Windows' ? ['where', 'ffprobe'] : ['which', 'ffprobe']
        );
        if ($which->successful()) {
            $path = trim(Str::before($which->output(), "\n"));
            if ($path !== '' && is_executable($path)) {
                return $path;
            }
        }

        return null;
    }

    private function ffmpegBinary(): ?string
    {
        $configured = config('services.ffmpeg_path');
        if (is_string($configured) && $configured !== '' && (is_executable($configured) || is_file($configured))) {
            return $configured;
        }

        $names = PHP_OS_FAMILY === 'Windows'
            ? ['ffmpeg.exe', 'ffmpeg']
            : ['ffmpeg'];

        foreach ($names as $name) {
            $which = Process::timeout(5)->run(
                PHP_OS_FAMILY === 'Windows' ? ['where', $name] : ['which', $name]
            );
            if ($which->successful()) {
                $path = trim(Str::before($which->output(), "\n"));
                if ($path !== '' && (is_executable($path) || is_file($path))) {
                    return $path;
                }
            }
        }

        foreach (['/usr/bin/ffmpeg', '/usr/local/bin/ffmpeg'] as $candidate) {
            if (is_executable($candidate)) {
                return $candidate;
            }
        }

        return null;
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

        if ($host === 'fal.media' || Str::endsWith($host, '.fal.media')) {
            return true;
        }
        if ($host === 'fal.ai' || Str::endsWith($host, '.fal.ai')) {
            return true;
        }

        if (Str::endsWith($host, '.googleusercontent.com')
            || Str::endsWith($host, '.googleapis.com')
            || Str::endsWith($host, '.r2.cloudflarestorage.com')
            || Str::endsWith($host, '.cloudflarestorage.com')) {
            return true;
        }

        return false;
    }
}
