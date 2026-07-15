<?php

namespace App\Http\Controllers;

use App\Exceptions\InsufficientTokensException;
use App\Models\UserMusicCreation;
use App\Services\Credits\MusicGenerationCostEstimator;
use App\Services\FalMusicInputBuilder;
use App\Services\FalPricingService;
use App\Services\FalService;
use App\Services\Tokens\TokenService;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

class MusicGenerationController extends Controller
{
    public function store(
        Request $request,
        FalService $fal,
        FalMusicInputBuilder $inputBuilder,
        MusicGenerationCostEstimator $costEstimator,
        TokenService $tokens,
        FalPricingService $pricing,
    ): JsonResponse {
        // When PHP post_max_size is exceeded, $_POST/$_FILES are empty → fake 422s.
        $contentLength = (int) $request->server('CONTENT_LENGTH', 0);
        if ($contentLength > 0 && $request->all() === [] && $request->allFiles() === []) {
            return response()->json([
                'message' => 'Audio upload was blocked by the server size limit (post_max_size / upload_max_filesize). Use a smaller MP3 (under 15MB) or raise those PHP limits in cPanel.',
            ], 422);
        }

        // Inspect the raw PHP upload BEFORE Laravel's "file" rule, which only says
        // "The audio failed to upload." and hides UPLOAD_ERR_* details.
        $preflightAudio = $request->file('audio');
        if ($preflightAudio instanceof UploadedFile && ! $preflightAudio->isValid()) {
            $code = $preflightAudio->getError();
            $detail = $this->uploadErrorMessage($code);

            Log::warning('Music audio PHP upload error', [
                'upload_error' => $code,
                'upload_message' => $preflightAudio->getErrorMessage(),
                'name' => $preflightAudio->getClientOriginalName(),
                'size_client' => $preflightAudio->getSize(),
                'content_length' => $contentLength,
                'upload_tmp_dir' => ini_get('upload_tmp_dir') ?: sys_get_temp_dir(),
                'tmp_writable' => is_writable(ini_get('upload_tmp_dir') ?: sys_get_temp_dir()),
            ]);

            return response()->json([
                'message' => $detail,
                'upload_error' => $code,
            ], 422);
        }

        try {
            $data = $request->validate([
                'endpoint_id' => ['required', 'string', 'max:191'],
                'prompt' => ['required', 'string', 'min:1', 'max:5000'],
                'title' => ['nullable', 'string', 'max:120'],
                'lyrics' => ['nullable', 'string', 'max:5000'],
                // FormData sends "1"/"0" — avoid strict boolean rule edge cases.
                'instrumental' => ['nullable'],
                'auto_enhance' => ['nullable'],
                'vocal_gender' => ['nullable', 'string'],
                'audio_url' => ['nullable', 'string', 'max:2048'],
                'edit_mode' => ['nullable', 'string', 'in:remix,lyrics'],
                'duration_seconds' => ['nullable', 'numeric', 'min:0', 'max:7200'],
                // Do NOT use the "file" rule here — invalid PHP uploads already handled above.
                // "max" alone is enough once isValid() is true.
                'audio' => ['nullable', 'max:51200'],
                // Base64 bypass for hosts where PHP multipart temp uploads fail.
                'audio_base64' => ['nullable', 'string'],
                'audio_filename' => ['nullable', 'string', 'max:255'],
                'audio_mime' => ['nullable', 'string', 'max:100'],
            ]);
        } catch (\Illuminate\Validation\ValidationException $e) {
            $first = collect($e->errors())->flatten()->first();

            Log::warning('Music generate validation failed', [
                'errors' => $e->errors(),
                'has_audio_file' => $request->hasFile('audio'),
                'keys' => array_keys($request->all()),
                'file_keys' => array_keys($request->allFiles()),
                'audio_error' => $request->file('audio') instanceof UploadedFile
                    ? $request->file('audio')->getError()
                    : null,
            ]);

            return response()->json([
                'message' => is_string($first) && $first !== ''
                    ? $first
                    : 'Invalid music request.',
                'errors' => $e->errors(),
            ], 422);
        }

        $vocalGenderRaw = strtolower(trim((string) ($data['vocal_gender'] ?? '')));
        if ($vocalGenderRaw !== '' && ! in_array($vocalGenderRaw, ['male', 'female'], true)) {
            // Ignore bad FormData values like the literal string "undefined".
            $vocalGenderRaw = '';
        }

        if (! $fal->configured()) {
            return response()->json(['message' => 'Music service is not configured.'], 503);
        }

        $model = DB::table('text_to_music_models')
            ->where('endpoint_id', $data['endpoint_id'])
            ->where('status', 'active')
            ->first();

        if (! $model) {
            return response()->json(['message' => 'The selected music model is not available.'], 422);
        }

        $instrumental = $request->boolean('instrumental', true);
        $supportsVocals = (bool) ($model->supports_vocals ?? false);
        $supportsLyrics = (bool) ($model->supports_lyrics ?? false);
        $supportsAudio = Schema::hasColumn('text_to_music_models', 'supports_audio')
            ? (bool) ($model->supports_audio ?? false)
            : false;

        if (! $supportsVocals) {
            $instrumental = true;
        }

        $editMode = isset($data['edit_mode']) && in_array($data['edit_mode'], ['remix', 'lyrics'], true)
            ? $data['edit_mode']
            : null;

        // Lyrics-edit mode always targets vocals; remix may still be instrumental.
        if ($supportsAudio && $editMode === 'lyrics') {
            $instrumental = false;
        }

        $lyrics = $supportsLyrics && ! $instrumental ? trim((string) ($data['lyrics'] ?? '')) : '';
        $autoEnhance = $request->boolean('auto_enhance', false);
        $vocalGender = ! $instrumental
            ? ($vocalGenderRaw === 'male' || $vocalGenderRaw === 'female' ? $vocalGenderRaw : 'female')
            : null;

        $audioUrl = null;
        if ($supportsAudio) {
            $audioUrl = trim((string) ($data['audio_url'] ?? ''));

            // Preferred path on shared hosting: JSON base64 (avoids PHP multipart /tmp failures).
            $audioBase64 = trim((string) ($data['audio_base64'] ?? ''));
            if ($audioBase64 !== '') {
                $decoded = base64_decode($audioBase64, true);
                if ($decoded === false || $decoded === '') {
                    return response()->json(['message' => 'Could not decode the audio file. Please try another MP3.'], 422);
                }

                // ~20MB decoded limit to keep memory safe.
                if (strlen($decoded) > 20 * 1024 * 1024) {
                    return response()->json(['message' => 'Audio file is too large. Please use an MP3 under 20MB.'], 422);
                }

                $filename = trim((string) ($data['audio_filename'] ?? 'source.mp3'));
                if ($filename === '' || ! preg_match('/\.(mp3|wav|flac|ogg|m4a|aac|mpeg|mpga)$/i', $filename)) {
                    $filename = 'source.mp3';
                }

                $mime = trim((string) ($data['audio_mime'] ?? ''));
                if ($mime === '' || $mime === 'application/octet-stream') {
                    $mime = null;
                }

                try {
                    $audioUrl = $fal->uploadBytesToCdn($decoded, $filename, $mime);
                } catch (\Throwable $e) {
                    report($e);
                    Log::error('ACE base64 audio upload to fal CDN failed', [
                        'error' => $e->getMessage(),
                        'filename' => $filename,
                        'bytes' => strlen($decoded),
                    ]);

                    return response()->json([
                        'message' => 'Could not upload the source audio file to the music service. Try a smaller MP3, or check that the server can reach rest.fal.ai.',
                    ], 502);
                }
            }

            /** @var UploadedFile|null $audioFile */
            $audioFile = $request->file('audio');
            if ($audioUrl === '' && $audioFile instanceof UploadedFile) {
                if (! $audioFile->isValid()) {
                    return response()->json([
                        'message' => $this->uploadErrorMessage($audioFile->getError()),
                        'upload_error' => $audioFile->getError(),
                    ], 422);
                }

                if (! $this->isAllowedAudioUpload($audioFile)) {
                    return response()->json([
                        'message' => 'Unsupported audio file. Please upload MP3, WAV, FLAC, OGG, M4A, or AAC.',
                        'debug' => [
                            'name' => $audioFile->getClientOriginalName(),
                            'ext' => $audioFile->getClientOriginalExtension(),
                            'mime' => $audioFile->getMimeType(),
                        ],
                    ], 422);
                }

                try {
                    $audioUrl = $fal->uploadToCdn($audioFile);
                } catch (\Throwable $e) {
                    report($e);
                    Log::error('ACE source audio upload to fal CDN failed', [
                        'error' => $e->getMessage(),
                        'original_name' => $audioFile->getClientOriginalName(),
                        'mime' => $audioFile->getMimeType(),
                        'size' => $audioFile->getSize(),
                    ]);

                    return response()->json([
                        'message' => 'Could not upload the source audio file to the music service. Try MP3 under ~15MB, or check that the server can reach rest.fal.ai.',
                    ], 502);
                }
            }

            if ($audioUrl === '') {
                return response()->json(['message' => 'This model requires a source audio file.'], 422);
            }
        }

        $durationSeconds = isset($data['duration_seconds']) && is_numeric($data['duration_seconds'])
            ? (int) ceil((float) $data['duration_seconds'])
            : null;
        $supportsDurationControl = (bool) ($model->supports_duration_control ?? false);

        if ($supportsDurationControl) {
            $minimumDuration = max(1, (int) ($model->min_duration_seconds ?? 1));
            $maximumDuration = max($minimumDuration, (int) ($model->max_duration ?? $minimumDuration));
            $durationSeconds ??= (int) ($model->default_duration_seconds ?? $minimumDuration);

            if ($durationSeconds < $minimumDuration || $durationSeconds > $maximumDuration) {
                return response()->json([
                    'message' => "Duration must be between {$minimumDuration} and {$maximumDuration} seconds for this model.",
                ], 422);
            }
        } elseif (! $supportsAudio) {
            // Never let clients inject unsupported duration fields or alter billing.
            $durationSeconds = null;
        }

        $falInput = $inputBuilder->build($model->endpoint_id, [
            'prompt' => $data['prompt'],
            'lyrics' => $lyrics,
            'instrumental' => $instrumental,
            'vocal_gender' => $vocalGender,
            'auto_enhance' => $autoEnhance,
            'duration_seconds' => $supportsDurationControl ? $durationSeconds : null,
            'default_duration_seconds' => $model->default_duration_seconds ?? null,
            'max_duration' => $model->max_duration ?? null,
            'audio_url' => $audioUrl,
            'edit_mode' => $editMode,
        ]);

        $billing = $pricing->resolve((string) $model->endpoint_id);
        if ($billing === null) {
            return response()->json([
                'message' => 'This model is out of service. Please try another one.',
                'endpoint_id' => $model->endpoint_id,
            ], 503);
        }

        $cost = $costEstimator->estimate([
            'unit' => $billing['unit'],
            'unit_price' => $billing['unit_price'],
            'default_duration_seconds' => $model->default_duration_seconds ?? null,
            'max_duration' => $model->max_duration ?? null,
        ], $autoEnhance, $durationSeconds);

        if ((int) $cost['credits'] <= 0) {
            return response()->json([
                'message' => 'This model is out of service. Please try another one.',
                'endpoint_id' => $model->endpoint_id,
            ], 503);
        }

        $title = trim((string) ($data['title'] ?? ''));
        if ($title === '') {
            $title = mb_strlen($data['prompt']) > 42
                ? mb_substr($data['prompt'], 0, 42).'…'
                : $data['prompt'];
        }

        try {
            /** @var UserMusicCreation $creation */
            $creation = $tokens->reserve(
                $request->user(),
                (int) $cost['credits'],
                'music',
                fn () => UserMusicCreation::create([
                    'user_id' => $request->user()->id,
                    'mode' => $supportsAudio ? 'audio-to-audio' : 'text-to-music',
                    'endpoint_id' => $model->endpoint_id,
                    'model_name' => $model->name,
                    'title' => $title,
                    'prompt' => $data['prompt'],
                    'lyrics' => $lyrics !== '' ? $lyrics : null,
                    'instrumental' => $instrumental,
                    'input_assets' => $audioUrl ? [['url' => $audioUrl, 'kind' => 'audio']] : null,
                    'settings' => [
                        'auto_enhance' => $autoEnhance,
                        'vocal_gender' => $vocalGender,
                        'audio_url' => $audioUrl,
                        'edit_mode' => $editMode ?? ($falInput['edit_mode'] ?? null),
                        'fal_input' => $falInput,
                        'fal_endpoint' => $model->endpoint_id,
                        'fal_cost_usd' => $cost['fal_cost_usd'],
                        'credits' => $cost['credits'],
                        'assumed_seconds' => $cost['assumed_seconds'],
                    ],
                    'duration_seconds' => $cost['assumed_seconds'],
                    'credits_charged' => $cost['credits'],
                    'status' => UserMusicCreation::STATUS_PENDING,
                    'progress_message' => 'Starting…',
                ]),
            );
        } catch (InsufficientTokensException $e) {
            return response()->json([
                'message' => 'You do not have enough tokens for this creation.',
                'required_tokens' => $e->required,
                'available_tokens' => $e->available,
            ], 402);
        }

        try {
            $submit = $fal->submit($model->endpoint_id, $falInput);
        } catch (\Throwable $e) {
            report($e);
            $creation->markFailed('Could not start generation. Please try again.', 'submit_error');
            $tokens->refund($request->user(), $creation, 'music', 'fal_submit_failed');

            return response()->json($this->present($creation), 502);
        }

        $creation->markQueued(
            $submit['request_id'] ?? null,
            $submit['status_url'] ?? null,
            $submit['response_url'] ?? null,
        );

        if (isset($submit['queue_position'])) {
            $creation->forceFill(['queue_position' => (int) $submit['queue_position']])->save();
        }

        return response()->json($this->present($creation), 201);
    }

    public function status(Request $request, UserMusicCreation $creation, FalService $fal): JsonResponse
    {
        abort_unless($creation->isOwnedBy($request->user()), 403);

        if (! $creation->isTerminal() && $creation->fal_request_id) {
            $this->refresh($creation, $fal);
        }

        return response()->json($this->present($creation));
    }

    private function refresh(UserMusicCreation $creation, FalService $fal): void
    {
        try {
            $status = $creation->fal_status_url
                ? $fal->statusByUrl($creation->fal_status_url)
                : null;
        } catch (\Throwable $e) {
            report($e);

            return;
        }

        if (! is_array($status)) {
            return;
        }

        $state = $status['status'] ?? null;

        if ($state === 'IN_QUEUE') {
            $creation->forceFill([
                'status' => UserMusicCreation::STATUS_QUEUED,
                'queue_position' => $status['queue_position'] ?? null,
                'progress_message' => 'In queue',
            ])->save();

            return;
        }

        if ($state === 'IN_PROGRESS') {
            $creation->markInProgress(null, 'Composing…');

            return;
        }

        if ($state !== 'COMPLETED') {
            if (in_array($state, ['FAILED', 'ERROR', 'CANCELLED'], true) || ! empty($status['error'])) {
                $creation->markFailed(
                    $this->friendlyMusicError((string) ($status['error'] ?? 'Generation failed.')),
                    $status['error_type'] ?? 'error',
                );
            }

            return;
        }

        if (! empty($status['error'])) {
            $creation->markFailed(
                $this->friendlyMusicError((string) $status['error']),
                $status['error_type'] ?? 'error',
            );

            return;
        }

        try {
            $result = $creation->fal_response_url
                ? $fal->resultByUrl($creation->fal_response_url)
                : [];
        } catch (\Throwable $e) {
            report($e);
            // fal often marks COMPLETED then returns 422 on the result URL (e.g. audio too long).
            // Without markFailed the UI stays stuck in queue/progress forever.
            $creation->markFailed($this->messageFromFalException($e), 'result_error');

            return;
        }

        $audioUrl = $this->extractAudioUrl($result);
        if ($audioUrl === null) {
            $creation->markFailed('Generation finished without audio.', 'empty_result');

            return;
        }

        $duration = $this->extractDuration($result);
        $coverUrl = $this->extractCoverUrl($result);

        $creation->forceFill([
            'status' => UserMusicCreation::STATUS_COMPLETED,
            'result_audio_url' => $audioUrl,
            'result_preview_url' => $audioUrl,
            'cover_url' => $coverUrl,
            'result_assets' => [
                [
                    'url' => $audioUrl,
                    'content_type' => 'audio/mpeg',
                    'duration' => $duration,
                ],
            ],
            'duration_seconds' => $duration ?? $creation->duration_seconds,
            'progress_message' => 'Completed',
            'queue_position' => null,
            'completed_at' => now(),
            'error_message' => null,
            'error_type' => null,
        ])->save();
    }

    /**
     * @param  array<string, mixed>  $result
     */
    private function extractAudioUrl(array $result): ?string
    {
        foreach (['audio', 'audio_file', 'music', 'output'] as $key) {
            $node = $result[$key] ?? null;
            if (is_array($node) && is_string($node['url'] ?? null) && $node['url'] !== '') {
                return $node['url'];
            }
            if (is_string($node) && str_starts_with($node, 'http')) {
                return $node;
            }
        }

        if (is_string($result['audio_url'] ?? null) && $result['audio_url'] !== '') {
            return $result['audio_url'];
        }
        if (is_string($result['url'] ?? null) && $result['url'] !== '') {
            return $result['url'];
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $result
     */
    private function extractCoverUrl(array $result): ?string
    {
        foreach (['image', 'cover', 'thumbnail'] as $key) {
            $node = $result[$key] ?? null;
            if (is_array($node) && is_string($node['url'] ?? null) && $node['url'] !== '') {
                return $node['url'];
            }
            if (is_string($node) && str_starts_with($node, 'http')) {
                return $node;
            }
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $result
     */
    private function extractDuration(array $result): ?int
    {
        foreach (['audio', 'audio_file', 'music', 'output'] as $key) {
            $node = $result[$key] ?? null;
            if (is_array($node) && isset($node['duration'])) {
                return (int) round((float) $node['duration']);
            }
        }

        if (isset($result['duration'])) {
            return (int) round((float) $result['duration']);
        }

        return null;
    }

    /**
     * Human-readable PHP upload error (UPLOAD_ERR_*).
     */
    private function uploadErrorMessage(int $code): string
    {
        return match ($code) {
            UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE => 'Audio file is too large for the server upload limit. Try a smaller MP3 (under 15MB).',
            UPLOAD_ERR_PARTIAL => 'Audio was only partially uploaded. Please try again (stable connection, smaller file).',
            UPLOAD_ERR_NO_FILE => 'No audio file was received. Please choose an MP3 and try again.',
            UPLOAD_ERR_NO_TMP_DIR => 'Server temp folder is missing (upload_tmp_dir). Ask hosting to fix PHP upload_tmp_dir.',
            UPLOAD_ERR_CANT_WRITE => 'Server could not save the upload to disk. Check disk space and that /tmp (upload_tmp_dir) is writable.',
            UPLOAD_ERR_EXTENSION => 'A PHP extension (or ModSecurity) blocked the audio upload. Try disabling ModSecurity for this domain, or upload a smaller MP3.',
            default => "The audio failed to upload (PHP error code {$code}).",
        };
    }

    /**
     * Accept common audio uploads by extension OR mime.
     * Shared hosts frequently report MP3 as application/octet-stream.
     */
    private function isAllowedAudioUpload(UploadedFile $file): bool
    {
        $ext = strtolower((string) $file->getClientOriginalExtension());
        $allowedExt = ['mp3', 'wav', 'flac', 'ogg', 'oga', 'm4a', 'aac', 'mpeg', 'mpga'];

        if (in_array($ext, $allowedExt, true)) {
            return true;
        }

        // Fallback: filename ends with .mp3 even if getClientOriginalExtension is empty.
        $name = strtolower((string) $file->getClientOriginalName());
        foreach ($allowedExt as $allowed) {
            if (str_ends_with($name, '.'.$allowed)) {
                return true;
            }
        }

        $mime = strtolower((string) ($file->getMimeType() ?: ''));
        $allowedMime = [
            'audio/mpeg',
            'audio/mp3',
            'audio/x-mp3',
            'audio/x-mpeg',
            'audio/mpeg3',
            'audio/wav',
            'audio/x-wav',
            'audio/wave',
            'audio/flac',
            'audio/ogg',
            'audio/mp4',
            'audio/aac',
            'audio/x-aac',
            'audio/webm',
        ];

        return in_array($mime, $allowedMime, true);
    }

    private function messageFromFalException(\Throwable $e): string
    {
        $raw = 'Generation failed. Please try again.';

        if ($e instanceof RequestException && $e->response) {
            $json = $e->response->json();
            if (is_array($json)) {
                $detail = $json['detail'] ?? null;
                if (is_array($detail)) {
                    $messages = [];
                    foreach ($detail as $row) {
                        if (is_array($row) && isset($row['msg']) && is_string($row['msg']) && $row['msg'] !== '') {
                            $messages[] = $row['msg'];
                        } elseif (is_string($row) && $row !== '') {
                            $messages[] = $row;
                        }
                    }
                    if ($messages !== []) {
                        $raw = implode(' ', $messages);
                    }
                }

                if ($raw === 'Generation failed. Please try again.') {
                    foreach (['error', 'message'] as $key) {
                        if (isset($json[$key]) && is_string($json[$key]) && $json[$key] !== '') {
                            $raw = $json[$key];
                            break;
                        }
                    }
                }
            }
        }

        return $this->friendlyMusicError($raw);
    }

    /**
     * Turn provider/safety failures into short, clear user-facing copy so people
     * understand the prompt/lyrics were blocked (not a site outage).
     */
    private function friendlyMusicError(string $raw): string
    {
        $text = trim($raw);
        if ($text === '') {
            return 'Generation failed. Please try again.';
        }

        $lower = mb_strtolower($text);

        // Celebrity / copyright / voice cloning style blocks (e.g. named artists).
        if (
            str_contains($lower, 'content checker')
            || str_contains($lower, 'content policy')
            || str_contains($lower, 'copyright')
            || str_contains($lower, 'trademark')
            || str_contains($lower, 'public figure')
            || str_contains($lower, 'celebrity')
            || str_contains($lower, 'impersonat')
            || str_contains($lower, 'artist name')
            || str_contains($lower, 'voice of')
        ) {
            return 'Blocked: your prompt looks like copyrighted or celebrity content (artist name/voice). Remove famous names and try again.';
        }

        // Profanity / drugs / unsafe lyrics — fal often returns a vague "invalid" reject.
        if (
            str_contains($lower, 'rejected this request as invalid')
            || str_contains($lower, 'flagged')
            || str_contains($lower, 'safety')
            || str_contains($lower, 'moderat')
            || str_contains($lower, 'inappropriate')
            || str_contains($lower, 'nsfw')
            || str_contains($lower, 'prohibited')
            || str_contains($lower, 'unsafe')
            || str_contains($lower, 'violat')
            || str_contains($lower, 'bad word')
            || str_contains($lower, 'profan')
        ) {
            return 'Blocked: your prompt or lyrics contain restricted words (profanity, drugs, violence, etc.). Clean them up and retry.';
        }

        // Keep short provider hints when useful; otherwise a neutral fallback.
        if (mb_strlen($text) > 220) {
            return 'Generation failed. Try a simpler prompt and lyrics, then retry.';
        }

        return $text;
    }

    /**
     * @return array<string, mixed>
     */
    private function present(UserMusicCreation $creation): array
    {
        $duration = $creation->duration_seconds;
        $durationLabel = null;
        if (is_int($duration) && $duration > 0) {
            $durationLabel = sprintf('%d:%02d', intdiv($duration, 60), $duration % 60);
        }

        $error = $creation->error_message;
        if (is_string($error) && $error !== '') {
            $error = $this->friendlyMusicError($error);
        }

        return [
            'id' => $creation->id,
            'status' => $creation->status,
            'queue_position' => $creation->queue_position,
            'progress_message' => $creation->progress_message,
            'prompt' => $creation->prompt,
            'lyrics' => $creation->lyrics,
            'title' => $creation->title,
            'model_name' => $creation->model_name,
            'instrumental' => (bool) $creation->instrumental,
            'audio_url' => $creation->result_audio_url,
            'preview_url' => $creation->result_preview_url,
            'cover_url' => $creation->cover_url,
            'duration_seconds' => $duration,
            'duration' => $durationLabel,
            'error' => $error,
            'credits' => $creation->settings['credits'] ?? $creation->credits_charged,
            'token_balance' => (int) (auth()->user()?->fresh()->tokens ?? 0),
            'fal_cost_usd' => $creation->settings['fal_cost_usd'] ?? null,
            'created_at' => optional($creation->created_at)->toIso8601String(),
        ];
    }
}
