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
        $data = $request->validate([
            'endpoint_id' => ['required', 'string', 'max:191'],
            'prompt' => ['required', 'string', 'min:1', 'max:5000'],
            'title' => ['nullable', 'string', 'max:120'],
            'lyrics' => ['nullable', 'string', 'max:5000'],
            'instrumental' => ['nullable', 'boolean'],
            'auto_enhance' => ['nullable', 'boolean'],
            'vocal_gender' => ['nullable', 'string', 'in:male,female'],
            'audio_url' => ['nullable', 'string', 'max:2048'],
            'edit_mode' => ['nullable', 'string', 'in:remix,lyrics'],
            'duration_seconds' => ['nullable', 'numeric', 'min:1', 'max:3600'],
            // Prefer extension check: shared hosts often mis-detect MP3 MIME as octet-stream.
            'audio' => ['nullable', 'file', 'max:51200', 'extensions:mp3,wav,flac,ogg,m4a,aac,mpeg,mpga'],
        ]);

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
        $vocalGender = ! $instrumental ? ($data['vocal_gender'] ?? 'female') : null;

        $audioUrl = null;
        if ($supportsAudio) {
            $audioUrl = trim((string) ($data['audio_url'] ?? ''));
            /** @var UploadedFile|null $audioFile */
            $audioFile = $request->file('audio');
            if ($audioFile instanceof UploadedFile) {
                if (! $audioFile->isValid()) {
                    return response()->json([
                        'message' => 'Audio upload failed on the server (file too large or incomplete). Try a smaller MP3, or raise PHP upload_max_filesize / post_max_size.',
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

        $falInput = $inputBuilder->build($model->endpoint_id, [
            'prompt' => $data['prompt'],
            'lyrics' => $lyrics,
            'instrumental' => $instrumental,
            'vocal_gender' => $vocalGender,
            'auto_enhance' => $autoEnhance,
            'default_duration_seconds' => $model->default_duration_seconds ?? null,
            'max_duration' => $model->max_duration ?? null,
            'audio_url' => $audioUrl,
            'edit_mode' => $editMode,
        ]);

        $durationSeconds = isset($data['duration_seconds']) && is_numeric($data['duration_seconds'])
            ? (int) ceil((float) $data['duration_seconds'])
            : null;

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
                    (string) ($status['error'] ?? 'Generation failed.'),
                    $status['error_type'] ?? 'error',
                );
            }

            return;
        }

        if (! empty($status['error'])) {
            $creation->markFailed((string) $status['error'], $status['error_type'] ?? 'error');

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

    private function messageFromFalException(\Throwable $e): string
    {
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
                        return implode(' ', $messages);
                    }
                }

                foreach (['error', 'message'] as $key) {
                    if (isset($json[$key]) && is_string($json[$key]) && $json[$key] !== '') {
                        return $json[$key];
                    }
                }
            }
        }

        return 'Generation failed. Please try again.';
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
            'error' => $creation->error_message,
            'credits' => $creation->settings['credits'] ?? $creation->credits_charged,
            'token_balance' => (int) (auth()->user()?->fresh()->tokens ?? 0),
            'fal_cost_usd' => $creation->settings['fal_cost_usd'] ?? null,
            'created_at' => optional($creation->created_at)->toIso8601String(),
        ];
    }
}
