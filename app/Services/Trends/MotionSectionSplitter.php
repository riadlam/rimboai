<?php

namespace App\Services\Trends;

use App\Services\MediaMuxService;
use App\Services\MediaNormalizeService;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Process;
use RuntimeException;

/**
 * Pack a motion sketch into MiniMax H3-safe sections (5–14.5s),
 * preferring camera-cut boundaries when ffmpeg can detect them.
 */
class MotionSectionSplitter
{
    public const MAX_SECTION_SECONDS = 14.5;

    public const MIN_SECTION_SECONDS = 5.0;

    public const TARGET_SECTION_SECONDS = 10.0;

    public function __construct(
        private MediaMuxService $mux,
        private MediaNormalizeService $normalize,
    ) {}

    /**
     * @return list<array{start: float, end: float, duration: float}>
     */
    public function split(string $videoUrl, ?float $knownDuration = null): array
    {
        $duration = $knownDuration;
        if ($duration === null || $duration <= 0) {
            $duration = $this->mux->probeUrlDurationSeconds($videoUrl);
        }
        if ($duration === null || $duration <= 0) {
            throw new RuntimeException('Could not probe motion sketch duration for H3 split.');
        }

        if ($duration <= self::MAX_SECTION_SECONDS) {
            $end = max(self::MIN_SECTION_SECONDS, $duration);

            return [[
                'start' => 0.0,
                'end' => $end,
                'duration' => $end,
            ]];
        }

        $cuts = $this->detectSceneCuts($videoUrl, $duration);
        $sections = $this->packSections($duration, $cuts);

        Log::info('trends.h3.split_plan', [
            'duration' => $duration,
            'cuts' => count($cuts),
            'sections' => count($sections),
            'plan' => $sections,
        ]);

        return $sections;
    }

    /**
     * @param  list<float>  $cuts  cut times in seconds (exclusive of 0 / duration)
     * @return list<array{start: float, end: float, duration: float}>
     */
    public function packSections(float $duration, array $cuts): array
    {
        $duration = max(self::MIN_SECTION_SECONDS, $duration);
        $boundaries = [0.0];
        foreach ($cuts as $cut) {
            if ($cut > 0.35 && $cut < ($duration - 0.35)) {
                $boundaries[] = round((float) $cut, 3);
            }
        }
        $boundaries[] = $duration;
        $boundaries = array_values(array_unique($boundaries));
        sort($boundaries);

        $sections = [];
        $start = 0.0;
        $i = 1;
        while ($start < $duration - 0.05) {
            $targetEnd = min($duration, $start + self::TARGET_SECTION_SECONDS);
            $hardEnd = min($duration, $start + self::MAX_SECTION_SECONDS);

            $best = null;
            while ($i < count($boundaries) && $boundaries[$i] <= $hardEnd + 0.001) {
                $candidate = $boundaries[$i];
                $len = $candidate - $start;
                if ($len >= self::MIN_SECTION_SECONDS - 0.05) {
                    $best = $candidate;
                }
                if ($candidate >= $targetEnd) {
                    break;
                }
                $i++;
            }

            if ($best === null) {
                // No usable cut — take max stretch or remainder.
                $best = $hardEnd;
                if (($best - $start) < self::MIN_SECTION_SECONDS && $best < $duration) {
                    $best = min($duration, $start + self::MIN_SECTION_SECONDS);
                }
            }

            // Advance boundary cursor past chosen end.
            while ($i < count($boundaries) && $boundaries[$i] <= $best + 0.001) {
                $i++;
            }

            $end = min($duration, max($start + 0.5, $best));
            $len = $end - $start;
            if ($len < 0.5) {
                break;
            }

            // If the last stub would be < MIN, merge into previous section when possible.
            $remaining = $duration - $end;
            if ($remaining > 0.05 && $remaining < self::MIN_SECTION_SECONDS) {
                if (($duration - $start) <= self::MAX_SECTION_SECONDS + 0.05) {
                    $end = $duration;
                    $len = $end - $start;
                    $remaining = 0;
                } else {
                    // Leave enough for a valid final section.
                    $end = $duration - self::MIN_SECTION_SECONDS;
                    $len = $end - $start;
                }
            }

            $sections[] = [
                'start' => round($start, 3),
                'end' => round($end, 3),
                'duration' => round($len, 3),
            ];
            $start = $end;
        }

        if ($sections === []) {
            return [[
                'start' => 0.0,
                'end' => $duration,
                'duration' => $duration,
            ]];
        }

        // Ensure last section reaches duration.
        $last = count($sections) - 1;
        if ($sections[$last]['end'] < $duration - 0.05) {
            $sections[$last]['end'] = round($duration, 3);
            $sections[$last]['duration'] = round($sections[$last]['end'] - $sections[$last]['start'], 3);
        }

        // Split any oversized section (merge edge cases).
        $normalized = [];
        foreach ($sections as $section) {
            if ($section['duration'] <= self::MAX_SECTION_SECONDS + 0.05) {
                $normalized[] = $section;
                continue;
            }
            foreach ($this->equalChunks($section['start'], $section['end']) as $chunk) {
                $normalized[] = $chunk;
            }
        }

        return $normalized;
    }

    /**
     * @return list<float>
     */
    private function detectSceneCuts(string $videoUrl, float $duration): array
    {
        $ffmpeg = $this->normalize->ffmpegBinary();
        if ($ffmpeg === null) {
            return $this->syntheticCuts($duration);
        }

        try {
            $result = Process::timeout(120)->run([
                $ffmpeg,
                '-hide_banner',
                '-i', $videoUrl,
                '-filter:v', "select='gt(scene,0.25)',showinfo",
                '-an',
                '-f', 'null',
                '-',
            ]);

            $stderr = $result->errorOutput()."\n".$result->output();
            $cuts = [];
            if (preg_match_all('/pts_time:([0-9.]+)/', $stderr, $matches)) {
                foreach ($matches[1] as $raw) {
                    $t = (float) $raw;
                    if ($t > 0.4 && $t < ($duration - 0.4)) {
                        $cuts[] = round($t, 3);
                    }
                }
            }

            $cuts = array_values(array_unique($cuts));
            sort($cuts);

            if ($cuts === []) {
                return $this->syntheticCuts($duration);
            }

            return $cuts;
        } catch (\Throwable $e) {
            Log::warning('trends.h3.scene_detect_failed', ['error' => $e->getMessage()]);

            return $this->syntheticCuts($duration);
        }
    }

    /**
     * @return list<float>
     */
    private function syntheticCuts(float $duration): array
    {
        $cuts = [];
        $t = self::TARGET_SECTION_SECONDS;
        while ($t < $duration - 0.5) {
            $cuts[] = round($t, 3);
            $t += self::TARGET_SECTION_SECONDS;
        }

        return $cuts;
    }

    /**
     * @return list<array{start: float, end: float, duration: float}>
     */
    private function equalChunks(float $start, float $end): array
    {
        $len = $end - $start;
        $n = max(1, (int) ceil($len / self::MAX_SECTION_SECONDS));
        $chunk = $len / $n;
        $out = [];
        for ($i = 0; $i < $n; $i++) {
            $s = $start + ($i * $chunk);
            $e = ($i === $n - 1) ? $end : ($start + (($i + 1) * $chunk));
            $out[] = [
                'start' => round($s, 3),
                'end' => round($e, 3),
                'duration' => round($e - $s, 3),
            ];
        }

        return $out;
    }
}
