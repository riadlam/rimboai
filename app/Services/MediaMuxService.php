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

    /**
     * If media is longer than $maxSeconds, trim from the start and store on public disk.
     * Returns the original URL when already short enough.
     *
     * @return array{url: string, path: string|null, trimmed: bool, original_seconds: float|null}
     */
    public function ensureMaxDurationPublicUrl(
        string $url,
        float $maxSeconds,
        string $storageDir,
        string $filename,
        string $kind = 'video',
    ): array {
        $maxSeconds = max(0.5, $maxSeconds);
        $tmp = $this->downloadToTemp($url, 'trim-src-');
        $originalSeconds = $this->probePathDurationSeconds($tmp);

        try {
            if ($originalSeconds === null || $originalSeconds <= ($maxSeconds + 0.05)) {
                return [
                    'url' => $url,
                    'path' => null,
                    'trimmed' => false,
                    'original_seconds' => $originalSeconds,
                ];
            }

            $ffmpeg = $this->normalize->ffmpegBinary();
            if ($ffmpeg === null) {
                throw new RuntimeException('ffmpeg is not available to trim media for H3.');
            }

            $outTmp = tempnam(sys_get_temp_dir(), 'trim-out-');
            if ($outTmp === false) {
                throw new RuntimeException('Could not create temp file for trim output.');
            }
            @unlink($outTmp);

            $isAudio = $kind === 'audio';
            $ext = $isAudio
                ? (str_ends_with(strtolower($filename), '.wav') ? '.wav' : '.mp3')
                : '.mp4';
            $outPath = $outTmp.$ext;

            $ok = $isAudio
                ? $this->runTrimAudio($ffmpeg, $tmp, $outPath, $maxSeconds)
                : $this->runTrimVideo($ffmpeg, $tmp, $outPath, $maxSeconds);

            if (! $ok) {
                throw new RuntimeException('ffmpeg trim failed for '.($isAudio ? 'audio' : 'video').'.');
            }

            $bytes = @file_get_contents($outPath);
            if ($bytes === false || $bytes === '') {
                throw new RuntimeException('Trimmed media was empty.');
            }

            if (! str_contains($filename, '.')) {
                $filename .= $ext;
            }
            $path = trim($storageDir, '/').'/'.$filename;
            Storage::disk('public')->put($path, $bytes);
            @unlink($outPath);

            Log::info('media.mux.trimmed', [
                'kind' => $kind,
                'original_seconds' => $originalSeconds,
                'max_seconds' => $maxSeconds,
                'path' => $path,
            ]);

            return [
                'url' => url('/storage/'.$path),
                'path' => $path,
                'trimmed' => true,
                'original_seconds' => $originalSeconds,
            ];
        } finally {
            @unlink($tmp);
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

    private function runTrimVideo(string $ffmpeg, string $sourcePath, string $outPath, float $maxSeconds): bool
    {
        $t = number_format($maxSeconds, 3, '.', '');

        // Prefer stream copy for speed; fall back to re-encode if keyframes break the cut.
        if ($this->runFfmpeg($ffmpeg, [
            '-y', '-i', $sourcePath,
            '-t', $t,
            '-map', '0:v:0',
            '-an',
            '-c:v', 'copy',
            '-movflags', '+faststart',
            '-f', 'mp4',
            $outPath,
        ])) {
            return true;
        }

        @unlink($outPath);

        return $this->runFfmpeg($ffmpeg, [
            '-y', '-i', $sourcePath,
            '-t', $t,
            '-map', '0:v:0',
            '-an',
            '-c:v', 'libx264',
            '-preset', 'veryfast',
            '-crf', '18',
            '-pix_fmt', 'yuv420p',
            '-movflags', '+faststart',
            '-f', 'mp4',
            $outPath,
        ]);
    }

    private function runTrimAudio(string $ffmpeg, string $sourcePath, string $outPath, float $maxSeconds): bool
    {
        $t = number_format($maxSeconds, 3, '.', '');
        $ext = strtolower(pathinfo($outPath, PATHINFO_EXTENSION));

        if ($ext === 'wav') {
            return $this->runFfmpeg($ffmpeg, [
                '-y', '-i', $sourcePath,
                '-t', $t,
                '-vn',
                '-c:a', 'pcm_s16le',
                $outPath,
            ]);
        }

        return $this->runFfmpeg($ffmpeg, [
            '-y', '-i', $sourcePath,
            '-t', $t,
            '-vn',
            '-c:a', 'libmp3lame',
            '-b:a', '192k',
            $outPath,
        ]);
    }

    /**
     * @param  list<string>  $args
     */
    private function runFfmpeg(string $ffmpeg, array $args): bool
    {
        $result = Process::timeout(180)->run(array_merge([$ffmpeg], $args));
        if (! $result->successful() || ! is_file($args[array_key_last($args)]) || filesize($args[array_key_last($args)]) <= 0) {
            Log::info('media.mux.trim_ffmpeg_exit', [
                'exit' => $result->exitCode(),
                'stderr' => substr($result->errorOutput(), 0, 400),
            ]);

            return false;
        }

        return true;
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
