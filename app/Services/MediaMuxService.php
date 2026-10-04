<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Kapwing-style post step: attach locked performance audio onto a silent/AI video.
 */
class MediaMuxService
{
    public function __construct(private MediaNormalizeService $normalize) {}

    /**
     * Download video + audio, mux with ffmpeg, store on the public disk.
     *
     * @return array{url: string, path: string, content_type: string}
     */
    public function muxToPublicStorage(
        string $videoUrl,
        string $audioUrl,
        string $storageDir = 'trend-remakes/muxed',
        ?string $filename = null,
    ): array {
        $ffmpeg = $this->normalize->ffmpegBinary();
        if ($ffmpeg === null) {
            throw new RuntimeException('ffmpeg is not available to mux trend audio.');
        }

        $videoTmp = $this->downloadToTemp($videoUrl, 'mux-vid-');
        $audioTmp = $this->downloadToTemp($audioUrl, 'mux-aud-');
        $outTmp = tempnam(sys_get_temp_dir(), 'mux-out-');
        if ($outTmp === false) {
            @unlink($videoTmp);
            @unlink($audioTmp);
            throw new RuntimeException('Could not create temp file for mux output.');
        }
        @unlink($outTmp);
        $outMp4 = $outTmp.'.mp4';

        try {
            $ok = $this->runMux($ffmpeg, $videoTmp, $audioTmp, $outMp4);
            if (! $ok) {
                throw new RuntimeException('ffmpeg mux failed for trend audio.');
            }

            $bytes = @file_get_contents($outMp4);
            if ($bytes === false || $bytes === '') {
                throw new RuntimeException('Muxed video was empty.');
            }

            $name = $filename ?: (Str::uuid()->toString().'.mp4');
            if (! str_ends_with(strtolower($name), '.mp4')) {
                $name .= '.mp4';
            }
            $path = trim($storageDir, '/').'/'.$name;
            Storage::disk('public')->put($path, $bytes);

            return [
                'url' => url('/storage/'.$path),
                'path' => $path,
                'content_type' => 'video/mp4',
            ];
        } finally {
            @unlink($videoTmp);
            @unlink($audioTmp);
            @unlink($outMp4);
        }
    }

    public function probeUrlDurationSeconds(string $url): ?float
    {
        try {
            $tmp = $this->downloadToTemp($url, 'probe-');
        } catch (\Throwable $e) {
            Log::warning('media.mux.probe_download_failed', ['error' => $e->getMessage()]);

            return null;
        }

        try {
            return $this->probePathDurationSeconds($tmp);
        } finally {
            @unlink($tmp);
        }
    }

    public function probePathDurationSeconds(string $path): ?float
    {
        $ffmpeg = $this->normalize->ffmpegBinary();
        if ($ffmpeg === null || ! is_file($path)) {
            return null;
        }

        $ffprobe = $this->ffprobeBinary($ffmpeg);
        if ($ffprobe === null) {
            return null;
        }

        $result = Process::timeout(30)->run([
            $ffprobe,
            '-v', 'error',
            '-show_entries', 'format=duration',
            '-of', 'default=noprint_wrappers=1:nokey=1',
            $path,
        ]);

        if (! $result->successful()) {
            return null;
        }

        $raw = trim($result->output());
        if ($raw === '' || ! is_numeric($raw)) {
            return null;
        }

        $seconds = (float) $raw;

        return $seconds > 0 ? $seconds : null;
    }

    private function runMux(string $ffmpeg, string $videoPath, string $audioPath, string $outPath): bool
    {
        // Drop any AI-invented audio; keep picture; attach locked track; match video length.
        $result = Process::timeout(180)->run([
            $ffmpeg,
            '-y',
            '-i', $videoPath,
            '-stream_loop', '-1',
            '-i', $audioPath,
            '-map', '0:v:0',
            '-map', '1:a:0',
            '-c:v', 'copy',
            '-c:a', 'aac',
            '-b:a', '192k',
            '-shortest',
            '-movflags', '+faststart',
            '-f', 'mp4',
            $outPath,
        ]);

        if (! $result->successful() || ! is_file($outPath) || filesize($outPath) <= 0) {
            Log::warning('media.mux.ffmpeg_failed', [
                'exit' => $result->exitCode(),
                'stderr' => substr($result->errorOutput(), 0, 500),
            ]);

            return false;
        }

        return true;
    }

    private function downloadToTemp(string $url, string $prefix): string
    {
        $url = trim($url);
        if ($url === '') {
            throw new RuntimeException('Media URL is empty.');
        }

        // Prefer local public disk when the URL points at our /storage/...
        $local = $this->resolveLocalPublicPath($url);
        if ($local !== null) {
            $tmp = tempnam(sys_get_temp_dir(), $prefix);
            if ($tmp === false) {
                throw new RuntimeException('Could not create temp file.');
            }
            if (! @copy($local, $tmp)) {
                @unlink($tmp);
                throw new RuntimeException('Could not copy local media for mux.');
            }

            return $tmp;
        }

        $response = Http::timeout(120)
            ->withHeaders(['User-Agent' => 'rimboai-media-mux/1.0'])
            ->get($url);

        if (! $response->successful()) {
            throw new RuntimeException('Could not download media for mux (HTTP '.$response->status().').');
        }

        $bytes = $response->body();
        if ($bytes === '') {
            throw new RuntimeException('Downloaded media for mux was empty.');
        }

        $tmp = tempnam(sys_get_temp_dir(), $prefix);
        if ($tmp === false) {
            throw new RuntimeException('Could not create temp file.');
        }
        file_put_contents($tmp, $bytes);

        return $tmp;
    }

    private function resolveLocalPublicPath(string $url): ?string
    {
        $path = (string) (parse_url($url, PHP_URL_PATH) ?: '');
        if (! str_contains($path, '/storage/')) {
            return null;
        }
        $relative = ltrim((string) Str::after($path, '/storage/'), '/');
        if ($relative === '') {
            return null;
        }
        $full = Storage::disk('public')->path($relative);

        return is_file($full) ? $full : null;
    }

    private function ffprobeBinary(string $ffmpeg): ?string
    {
        $dir = dirname($ffmpeg);
        $candidate = $dir.DIRECTORY_SEPARATOR.(PHP_OS_FAMILY === 'Windows' ? 'ffprobe.exe' : 'ffprobe');
        if (is_file($candidate)) {
            return $candidate;
        }

        $which = Process::timeout(5)->run(
            PHP_OS_FAMILY === 'Windows' ? ['where', 'ffprobe'] : ['which', 'ffprobe']
        );
        if ($which->successful()) {
            $path = trim(Str::before($which->output(), "\n"));
            if ($path !== '' && is_file($path)) {
                return $path;
            }
        }

        foreach (['/usr/bin/ffprobe', '/usr/local/bin/ffprobe'] as $path) {
            if (is_file($path)) {
                return $path;
            }
        }

        return null;
    }
}
