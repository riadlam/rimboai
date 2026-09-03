<?php

namespace App\Services;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Str;
use Throwable;

/**
 * Server-side duration / FPS / dimension probe. Client metadata is a hint only.
 */
class MediaProbeService
{
    /**
     * @return array{duration: ?float, fps: ?float, width: ?int, height: ?int, source: string}|null
     */
    public function probeUploaded(UploadedFile $file): ?array
    {
        $path = $file->getRealPath();
        if (! is_string($path) || $path === '' || ! is_file($path)) {
            return null;
        }

        $mime = (string) $file->getMimeType();
        if (str_starts_with($mime, 'image/')) {
            return $this->probeImageFile($path);
        }

        return $this->probeAvFile($path);
    }

    /**
     * @return array{duration: ?float, fps: ?float, width: ?int, height: ?int, source: string}|null
     */
    public function probeLocalPath(string $path): ?array
    {
        if ($path === '' || ! is_file($path)) {
            return null;
        }

        $mime = (string) (mime_content_type($path) ?: '');
        if (str_starts_with($mime, 'image/')) {
            return $this->probeImageFile($path);
        }

        return $this->probeAvFile($path);
    }

    /**
     * Prefer uploaded files. Remote URLs are probed only when ffprobe can read them.
     *
     * @return array{duration: ?float, fps: ?float, width: ?int, height: ?int, source: string}|null
     */
    public function probeUrl(string $url): ?array
    {
        if (! filter_var($url, FILTER_VALIDATE_URL)) {
            return null;
        }

        return $this->probeAvFile($url);
    }

    /**
     * @return array{duration: ?float, fps: ?float, width: ?int, height: ?int, source: string}
     */
    private function probeImageFile(string $path): array
    {
        $size = @getimagesize($path);
        if (! is_array($size)) {
            return ['duration' => null, 'fps' => null, 'width' => null, 'height' => null, 'source' => 'unreadable'];
        }

        return [
            'duration' => null,
            'fps' => null,
            'width' => (int) ($size[0] ?? 0) ?: null,
            'height' => (int) ($size[1] ?? 0) ?: null,
            'source' => 'getimagesize',
        ];
    }

    /**
     * @return array{duration: ?float, fps: ?float, width: ?int, height: ?int, source: string}|null
     */
    private function probeAvFile(string $path): ?array
    {
        $ffprobe = $this->ffprobeBinary();
        if ($ffprobe === null) {
            return null;
        }

        try {
            $result = Process::timeout(20)->run([
                $ffprobe,
                '-v', 'error',
                '-select_streams', 'v:0',
                '-show_entries', 'format=duration:stream=width,height,r_frame_rate',
                '-of', 'json',
                $path,
            ]);
        } catch (Throwable $e) {
            Log::warning('ffprobe failed', ['path' => $path, 'error' => $e->getMessage()]);

            return null;
        }

        if (! $result->successful()) {
            return null;
        }

        $json = json_decode($result->output(), true);
        if (! is_array($json)) {
            return null;
        }

        $duration = isset($json['format']['duration']) && is_numeric($json['format']['duration'])
            ? (float) $json['format']['duration']
            : null;
        $stream = is_array($json['streams'][0] ?? null) ? $json['streams'][0] : [];
        $width = isset($stream['width']) && is_numeric($stream['width']) ? (int) $stream['width'] : null;
        $height = isset($stream['height']) && is_numeric($stream['height']) ? (int) $stream['height'] : null;
        $fps = $this->parseFps($stream['r_frame_rate'] ?? null);

        return [
            'duration' => $duration !== null && $duration > 0 ? $duration : null,
            'fps' => $fps,
            'width' => $width,
            'height' => $height,
            'source' => 'ffprobe',
        ];
    }

    private function parseFps(mixed $rate): ?float
    {
        if (! is_string($rate) || $rate === '' || $rate === '0/0') {
            return null;
        }
        if (str_contains($rate, '/')) {
            [$n, $d] = array_map('floatval', explode('/', $rate, 2));
            if ($d > 0 && $n > 0) {
                return round($n / $d, 3);
            }

            return null;
        }

        return is_numeric($rate) && (float) $rate > 0 ? (float) $rate : null;
    }

    public function ffprobeBinary(): ?string
    {
        $ffmpeg = (string) config('services.ffmpeg_path', '');
        if ($ffmpeg !== '') {
            $dir = dirname($ffmpeg);
            $candidate = $dir.DIRECTORY_SEPARATOR.(PHP_OS_FAMILY === 'Windows' ? 'ffprobe.exe' : 'ffprobe');
            if (is_file($candidate)) {
                return $candidate;
            }
        }

        $names = PHP_OS_FAMILY === 'Windows' ? ['ffprobe.exe', 'ffprobe'] : ['ffprobe'];
        foreach ($names as $name) {
            $which = Process::timeout(5)->run(
                PHP_OS_FAMILY === 'Windows' ? ['where', $name] : ['which', $name]
            );
            if ($which->successful()) {
                $path = trim(Str::before($which->output(), "\n"));
                if ($path !== '' && is_file($path)) {
                    return $path;
                }
            }
        }

        foreach (['/usr/bin/ffprobe', '/usr/local/bin/ffprobe'] as $candidate) {
            if (is_file($candidate)) {
                return $candidate;
            }
        }

        return null;
    }
}
