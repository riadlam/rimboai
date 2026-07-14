<?php

namespace App\Services;

/**
 * Builds fal text-to-music request payloads per curated endpoint.
 */
class FalMusicInputBuilder
{
    /**
     * @param  array{
     *   prompt: string,
     *   lyrics?: string|null,
     *   instrumental?: bool,
     *   vocal_gender?: 'male'|'female'|null,
     *   auto_enhance?: bool,
     *   default_duration_seconds?: int|null,
     *   max_duration?: int|null,
     *   audio_url?: string|null,
     *   edit_mode?: 'remix'|'lyrics'|null,
     * }  $options
     * @return array<string, mixed>
     */
    public function build(string $endpointId, array $options): array
    {
        $id = strtolower($endpointId);
        $prompt = trim((string) ($options['prompt'] ?? ''));
        $lyrics = trim((string) ($options['lyrics'] ?? ''));
        $instrumental = (bool) ($options['instrumental'] ?? false);
        $gender = $options['vocal_gender'] ?? null;
        $duration = $this->resolveDurationSeconds($options);
        $audioUrl = trim((string) ($options['audio_url'] ?? ''));

        if (
            ! str_contains($id, 'ace-step')
            && ! $instrumental
            && is_string($gender)
            && ($gender === 'male' || $gender === 'female')
        ) {
            $hint = $gender === 'male' ? 'male vocals' : 'female vocals';
            if (! str_contains(strtolower($prompt), 'male vocal') && ! str_contains(strtolower($prompt), 'female vocal')) {
                $prompt = $prompt !== '' ? "{$prompt}, {$hint}" : $hint;
            }
        }

        if (str_contains($id, 'ace-step') && str_contains($id, 'audio-to-audio')) {
            // fal-ai/ace-step/audio-to-audio required: audio_url, original_tags, tags
            // tags must be short comma-separated genres — not a full essay prompt.
            // lyrics edit = local vocal rewrite (needs source singing). remix = better for instrumental + new vocals.
            $editMode = $options['edit_mode'] ?? null;
            if (! is_string($editMode) || ! in_array($editMode, ['remix', 'lyrics'], true)) {
                $editMode = (! $instrumental && $lyrics !== '') ? 'lyrics' : 'remix';
            }

            if ($editMode === 'lyrics') {
                $instrumental = false;
            }

            // Instrumental bed + new singing works far better as remix than lyrics-edit.
            if ($editMode === 'lyrics' && $lyrics !== '') {
                // keep as requested; payload still sets original_lyrics empty below
            }

            $willSing = ! $instrumental && ! ($lyrics === '' && $editMode === 'remix');
            $tags = $this->aceTagsFromPrompt($prompt, $willSing ? $gender : null);
            $normalizedLyrics = $this->normalizeAceLyrics($lyrics);

            $input = [
                'audio_url' => $audioUrl,
                'edit_mode' => $editMode,
                'original_tags' => $tags,
                'tags' => $tags,
            ];

            if ($instrumental) {
                $input['lyrics'] = '[inst]';
                $input['original_lyrics'] = '';
            } elseif ($lyrics === '' && $editMode === 'remix') {
                $input['lyrics'] = '[inst]';
                $input['original_lyrics'] = '';
            } elseif ($editMode === 'lyrics') {
                // Source instrumental / unknown originals: don't claim the pasted text is "original"
                // (that confuses lyrics-edit into preserving an empty vocal track).
                $input['original_lyrics'] = '';
                $input['lyrics'] = $normalizedLyrics;
            } else {
                // Remix + singing: same text for both is fine as conditioning.
                $input['original_lyrics'] = '';
                $input['lyrics'] = $normalizedLyrics;
            }

            return $input;
        }

        if (str_contains($id, 'minimax-music')) {
            $input = [
                'prompt' => $prompt,
                'is_instrumental' => $instrumental,
            ];
            if (! $instrumental) {
                if ($lyrics !== '') {
                    $input['lyrics'] = $lyrics;
                    $input['lyrics_optimizer'] = false;
                } else {
                    $input['lyrics'] = '';
                    $input['lyrics_optimizer'] = true;
                }
            }

            return $input;
        }

        if (str_contains($id, 'lyria')) {
            $fullPrompt = $prompt;
            if (! $instrumental && $lyrics !== '') {
                $fullPrompt = trim($prompt."\n\nLyrics:\n".$lyrics);
            }
            $input = ['prompt' => $fullPrompt];
            if ($instrumental) {
                $input['negative_prompt'] = 'vocals, singing, voice, lyrics';
            }

            return $input;
        }

        if (str_contains($id, 'elevenlabs') && str_contains($id, 'music')) {
            $fullPrompt = $prompt;
            if (! $instrumental && $lyrics !== '') {
                $fullPrompt = trim($prompt."\n\nLyrics:\n".$lyrics);
            }

            return [
                'prompt' => $fullPrompt,
                'force_instrumental' => $instrumental,
                'music_length_ms' => max(3000, min(600000, $duration * 1000)),
            ];
        }

        if (str_contains($id, 'cassette')) {
            return [
                'prompt' => $prompt,
                'duration' => max(1, min(180, $duration)),
            ];
        }

        if (str_contains($id, 'stable-audio')) {
            return [
                'prompt' => $prompt,
                'seconds_total' => max(1, min(190, $duration)),
            ];
        }

        // Generic fallback
        $input = ['prompt' => $prompt];
        if ($lyrics !== '' && ! $instrumental) {
            $input['lyrics'] = $lyrics;
        }

        return $input;
    }

    /**
     * @param  array{default_duration_seconds?: int|null, max_duration?: int|null}  $options
     */
    private function resolveDurationSeconds(array $options): int
    {
        $configured = $options['default_duration_seconds'] ?? null;
        if (is_numeric($configured) && (int) $configured > 0) {
            return (int) $configured;
        }

        $max = $options['max_duration'] ?? null;
        if (is_numeric($max) && (int) $max > 0) {
            return min(180, max(60, (int) round(((int) $max) * 0.5)));
        }

        return 120;
    }

    /**
     * ACE tags expect short comma-separated genres (not a production brief).
     * Long essays (esp. with "INSTRUMENTAL:") push the model toward no vocals.
     */
    private function aceTagsFromPrompt(string $prompt, mixed $gender): string
    {
        $prompt = trim($prompt);
        if ($prompt === '') {
            $tags = ['music'];
        } elseif (strlen($prompt) <= 140 && ! str_contains($prompt, "\n")) {
            $tags = array_values(array_filter(array_map('trim', explode(',', $prompt))));
        } else {
            $lower = strtolower($prompt);
            $lexicon = [
                'rai', 'pop', 'r&b', 'rnb', 'emotional', 'ballad', 'ambient', 'synth',
                'trap', 'hiphop', 'hip hop', 'lofi', 'lo-fi', 'arabic', 'algerian',
                'darja', 'darija', 'chaabi', 'breakia', 'oriental', 'electronic',
                'dance', 'breakwave', 'break-pop', 'indie', 'soft', 'breathy',
                'autotune', 'female vocals', 'male vocals', 'break fusion',
            ];
            $tags = [];
            foreach ($lexicon as $word) {
                if (str_contains($lower, $word)) {
                    $tags[] = $word === 'hip hop' ? 'hiphop' : ($word === 'lo-fi' ? 'lofi' : $word);
                }
            }
            $tags = array_values(array_unique($tags));
            if ($tags === []) {
                $first = trim((string) strtok(str_replace(["\r\n", "\r"], "\n", $prompt), "\n"));
                $first = preg_replace('/\s+/', ' ', $first) ?? $first;
                $tags = [mb_substr($first, 0, 80)];
            }
        }

        if (is_string($gender) && ($gender === 'male' || $gender === 'female')) {
            $hint = $gender === 'male' ? 'male vocals' : 'female vocals';
            $joined = strtolower(implode(', ', $tags));
            if (! str_contains($joined, 'male vocal') && ! str_contains($joined, 'female vocal')) {
                $tags[] = $hint;
            }
        }

        // Avoid reinforcing instrumental when we want singing
        $tags = array_values(array_filter(
            $tags,
            static fn (string $t): bool => ! preg_match('/^instrumental$/i', trim($t)),
        ));

        $out = implode(', ', array_slice($tags, 0, 12));

        return $out !== '' ? $out : 'music';
    }

    /**
     * Map common (Verse)/(Chorus) headings to ACE [verse]/[chorus] markers.
     */
    private function normalizeAceLyrics(string $lyrics): string
    {
        $lyrics = trim($lyrics);
        if ($lyrics === '') {
            return '';
        }

        $lines = preg_split("/\r\n|\n|\r/", $lyrics) ?: [$lyrics];
        $out = [];
        foreach ($lines as $line) {
            $trimmed = trim($line);
            if (preg_match('/^\(?\s*intro\b.*\)?$/iu', $trimmed)) {
                $out[] = '[intro]';
            } elseif (preg_match('/^\(?\s*verse\b.*\)?$/iu', $trimmed)) {
                $out[] = '[verse]';
            } elseif (preg_match('/^\(?\s*pre[-\s]?chorus\b.*\)?$/iu', $trimmed)) {
                $out[] = '[pre-chorus]';
            } elseif (preg_match('/^\(?\s*(final\s+)?chorus\b.*\)?$/iu', $trimmed)) {
                $out[] = '[chorus]';
            } elseif (preg_match('/^\(?\s*bridge\b.*\)?$/iu', $trimmed)) {
                $out[] = '[bridge]';
            } elseif (preg_match('/^\(?\s*outro\b.*\)?$/iu', $trimmed)) {
                $out[] = '[outro]';
            } else {
                $out[] = $line;
            }
        }

        return implode("\n", $out);
    }
}
