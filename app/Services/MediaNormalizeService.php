<?php

namespace App\Services;

use Illuminate\Process\Exceptions\ProcessFailedException;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Remux/transcode user videos into fal/partner-friendly MP4s.
 *
 * Phone/Windows captures often put moov at the end of the file. Several fal
 * partner models (Lucy/Decart, MiniMax, etc.) then fail with
 * "Failed to load the video file" even when the URL is a valid fal CDN link.
 */
class MediaNormalizeService
{
    public function ffmpegBinary(): ?string
    {
        $configured = config('services.ffmpeg_path');
        if (is_string($configured) && $configured !== '' && (is_executable($configured) || is_file($configured))) {
            return $configured;
        }

        $names = PHP_OS_FAMILY === 'Windows' ? ['ffmpeg.exe', 'ffmpeg'] : ['ffmpeg'];
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

    public function looksLikeVideo(string $pathOrName, ?string $contentType = null): bool
    {
        if (is_string($contentType) && str_starts_with(strtolower($contentType), 'video/')) {
            return true;
        }

        $name = strtolower($pathOrName);
        foreach (['.mp4', '.mov', '.webm', '.m4v', '.qt', '.mkv'] as $ext) {
            if (str_ends_with($name, $ext)) {
                return true;
            }
        }

        return false;
    }

    /**
     * True when moov is missing from the file head (classic phone/Windows MP4 layout)
     * or no H.264 marker is present in the head+tail scan.
     */
    public function needsInferenceRemux(string $sourcePath): bool
    {
        if (! is_file($sourcePath) || ! is_readable($sourcePath)) {
            return true;
        }

        $size = filesize($sourcePath);
        if ($size === false || $size < 64) {
            return true;
        }

        $headLen = (int) min($size, 2 * 1024 * 1024);
        $head = file_get_contents($sourcePath, false, null, 0, $headLen) ?: '';
        $tailLen = (int) min($size, 2 * 1024 * 1024);
        $tail = $tailLen > 0
            ? (file_get_contents($sourcePath, false, null, max(0, $size - $tailLen), $tailLen) ?: '')
            : '';

        $moovHead = strpos($head, 'moov');
        $mdatHead = strpos($head, 'mdat');
        $hasAvc = strpos($head, 'avc1') !== false || strpos($tail, 'avc1') !== false;

        // moov after mdat (or only in the tail) breaks several partner downloaders.
        if ($mdatHead !== false && ($moovHead === false || $moovHead > $mdatHead)) {
            return true;
        }
        if ($moovHead === false && strpos($tail, 'moov') !== false) {
            return true;
        }
        if (! $hasAvc) {
            return true;
        }

        return false;
    }

    /**
     * Return a filesystem path to an inference-ready MP4.
     * Caller must delete the returned path when it differs from $sourcePath.
     *
     * @param  bool  $partnerSafe  When true, always re-encode to a Decart/Lucy-safe
     *                             1280×720 baseline H.264 (odd phone sizes like 956×530
     *                             intermittently 422 "Failed to load the video file").
     * @return array{path: string, cleanup: bool, content_type: string, filename: string}
     */
    public function normalizeVideoFile(string $sourcePath, ?string $filenameHint = null, bool $partnerSafe = false): array
    {
        if (! is_file($sourcePath) || ! is_readable($sourcePath)) {
            throw new RuntimeException('Video file is not readable for normalization.');
        }

        if (! $partnerSafe && ! $this->needsInferenceRemux($sourcePath)) {
            return [
                'path' => $sourcePath,
                'cleanup' => false,
                'content_type' => 'video/mp4',
                'filename' => $this->mp4Name($filenameHint),
            ];
        }

        $ffmpeg = $this->ffmpegBinary();
        if ($ffmpeg === null) {
            Log::warning('media.normalize.ffmpeg_missing', ['source' => basename($sourcePath)]);

            return [
                'path' => $sourcePath,
                'cleanup' => false,
                'content_type' => 'video/mp4',
                'filename' => $this->mp4Name($filenameHint),
            ];
        }

        $out = tempnam(sys_get_temp_dir(), 'rimboai-vid-');
        if ($out === false) {
            throw new RuntimeException('Could not create temp file for video normalization.');
        }
        @unlink($out);
        $outMp4 = $out.'.mp4';

        // Partner-safe path: standard 720p canvas, CFR 30, baseline H.264, no audio.
        if ($partnerSafe) {
            if ($this->runFfmpeg($ffmpeg, [
                '-y',
                '-i', $sourcePath,
                '-vf', 'scale=1280:720:force_original_aspect_ratio=decrease,pad=1280:720:(ow-iw)/2:(oh-ih)/2,fps=30',
                '-c:v', 'libx264',
                '-preset', 'veryfast',
                '-crf', '20',
                '-pix_fmt', 'yuv420p',
                '-profile:v', 'baseline',
                '-level', '3.1',
                '-an',
                '-movflags', '+faststart',
                '-f', 'mp4',
                $outMp4,
            ])) {
                return [
                    'path' => $outMp4,
                    'cleanup' => true,
                    'content_type' => 'video/mp4',
                    'filename' => $this->mp4Name($filenameHint),
                ];
            }
            @unlink($outMp4);
            Log::warning('media.normalize.partner_safe_failed', ['source' => basename($sourcePath)]);
        }

        // Fast path: remux + faststart (fixes moov-at-end without re-encode).
        if ($this->runFfmpeg($ffmpeg, [
            '-y',
            '-i', $sourcePath,
            '-map', '0:v:0',
            '-map', '0:a:0?',
            '-c', 'copy',
            '-movflags', '+faststart',
            '-f', 'mp4',
            $outMp4,
        ])) {
            return [
                'path' => $outMp4,
                'cleanup' => true,
                'content_type' => 'video/mp4',
                'filename' => $this->mp4Name($filenameHint),
            ];
        }

        @unlink($outMp4);

        // Slow path: re-encode to H.264/AAC for exotic codecs / broken containers.
        if ($this->runFfmpeg($ffmpeg, [
            '-y',
            '-i', $sourcePath,
            '-map', '0:v:0',
            '-map', '0:a:0?',
            '-c:v', 'libx264',
            '-preset', 'veryfast',
            '-crf', '18',
            '-pix_fmt', 'yuv420p',
            '-c:a', 'aac',
            '-b:a', '192k',
            '-movflags', '+faststart',
            '-f', 'mp4',
            $outMp4,
        ])) {
            return [
                'path' => $outMp4,
                'cleanup' => true,
                'content_type' => 'video/mp4',
                'filename' => $this->mp4Name($filenameHint),
            ];
        }

        @unlink($outMp4);
        Log::warning('media.normalize.ffmpeg_failed', ['source' => basename($sourcePath)]);

        return [
            'path' => $sourcePath,
            'cleanup' => false,
            'content_type' => 'video/mp4',
            'filename' => $this->mp4Name($filenameHint),
        ];
    }

    /**
     * @param  list<string>  $args
     */
    private function runFfmpeg(string $ffmpeg, array $args): bool
    {
        try {
            $result = Process::timeout(180)->run(array_merge([$ffmpeg], $args));
        } catch (ProcessFailedException $e) {
            Log::warning('media.normalize.process_exception', ['message' => $e->getMessage()]);

            return false;
        } catch (\Throwable $e) {
            Log::warning('media.normalize.process_exception', ['message' => $e->getMessage()]);

            return false;
        }

        if (! $result->successful() || ! is_file($args[array_key_last($args)])) {
            Log::info('media.normalize.ffmpeg_exit', [
                'exit' => $result->exitCode(),
                'stderr' => substr($result->errorOutput(), 0, 500),
            ]);

            return false;
        }

        return filesize($args[array_key_last($args)]) > 0;
    }

    private function mp4Name(?string $filenameHint): string
    {
        $base = is_string($filenameHint) && $filenameHint !== ''
            ? pathinfo($filenameHint, PATHINFO_FILENAME)
            : 'video';
        $base = preg_replace('/[^a-zA-Z0-9._-]+/', '-', (string) $base) ?: 'video';

        return $base.'.mp4';
    }
}
