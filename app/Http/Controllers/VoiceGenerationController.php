<?php

namespace App\Http\Controllers;

use App\Exceptions\InsufficientTokensException;
use App\Models\UserVoiceCreation;
use App\Services\Credits\VoiceGenerationCostEstimator;
use App\Services\FalPricingService;
use App\Services\FalService;
use App\Services\FalVoiceInputBuilder;
use App\Services\Tokens\TokenService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class VoiceGenerationController extends Controller
{
    public function store(
        Request $request,
        FalService $fal,
        FalVoiceInputBuilder $inputBuilder,
        VoiceGenerationCostEstimator $costEstimator,
        TokenService $tokens,
        FalPricingService $pricing,
    ): JsonResponse {
        $data = $request->validate([
            'text' => ['required', 'string', 'min:1', 'max:70000'],
            'endpoint_id' => ['required', 'string', 'max:191'],
            'voice' => ['required', 'string', 'max:191'],
            'voice_name' => ['nullable', 'string', 'max:191'],
            'stability' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'clarity' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'style' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'speed' => ['nullable', 'numeric', 'min:50', 'max:150'],
        ]);

        if (! $fal->configured()) {
            return response()->json(['message' => 'Voice service is not configured.'], 503);
        }

        $model = DB::table('text_to_voice_models')
            ->where('endpoint_id', $data['endpoint_id'])
            ->where('status', 'active')
            ->first();

        if (! $model) {
            return response()->json(['message' => 'The selected voice model is not available.'], 422);
        }

        $voiceKey = trim($data['voice']);
        $voiceRow = DB::table('text_to_voice_voices')
            ->where('text_to_voice_model_id', $model->id)
            ->where('voice_key', $voiceKey)
            ->first();

        if (! $voiceRow) {
            // Still allow if catalog enums list includes it
            $enums = is_string($model->enums ?? null) ? json_decode($model->enums, true) : ($model->enums ?? null);
            $allowed = is_array($enums) && in_array($voiceKey, $enums, true);
            if (! $allowed) {
                return response()->json(['message' => 'The selected voice is not available for this model.'], 422);
            }
        }

        $voiceName = $data['voice_name']
            ?? ($voiceRow->name ?? null)
            ?? $voiceKey;

        $falInput = $inputBuilder->build($model->endpoint_id, [
            'text' => $data['text'],
            'voice' => $voiceKey,
            'stability' => $data['stability'] ?? null,
            'clarity' => $data['clarity'] ?? null,
            'style' => $data['style'] ?? null,
            'speed' => $data['speed'] ?? null,
        ]);

        $billing = $pricing->resolve((string) $model->endpoint_id);
        if ($billing === null) {
            return response()->json([
                'message' => 'This model is out of service. Please try another one.',
                'endpoint_id' => $model->endpoint_id,
            ], 503);
        }

        $cost = $costEstimator->estimate([
            'endpoint_id' => $billing['endpoint_id'],
            'unit' => $billing['unit'],
            'unit_price' => $billing['unit_price'],
            'character_count' => mb_strlen($data['text']),
        ]);

        if ((int) $cost['credits'] <= 0) {
            return response()->json([
                'message' => 'This model is out of service. Please try another one.',
                'endpoint_id' => $model->endpoint_id,
            ], 503);
        }

        try {
            /** @var UserVoiceCreation $creation */
            $creation = $tokens->reserve(
                $request->user(),
                (int) $cost['credits'],
                'voice',
                fn () => UserVoiceCreation::create([
                    'user_id' => $request->user()->id,
                    'mode' => 'text-to-voice',
                    'endpoint_id' => $model->endpoint_id,
                    'model_name' => $model->name,
                    'prompt' => $data['text'],
                    'voice_id' => $voiceKey,
                    'voice_name' => $voiceName,
                    'use_custom_voice' => false,
                    'settings' => [
                        'stability' => isset($data['stability']) ? (float) $data['stability'] : null,
                        'clarity' => isset($data['clarity']) ? (float) $data['clarity'] : null,
                        'style' => isset($data['style']) ? (float) $data['style'] : null,
                        'speed' => isset($data['speed']) ? (float) $data['speed'] : null,
                        'controls' => $inputBuilder->controlCapabilities($model->endpoint_id),
                        'fal_input' => $falInput,
                        'fal_endpoint' => $model->endpoint_id,
                        'fal_cost_usd' => $cost['fal_cost_usd'],
                        'credits' => $cost['credits'],
                        'cost_breakdown' => $cost['breakdown'],
                    ],
                    'credits_charged' => $cost['credits'],
                    'status' => UserVoiceCreation::STATUS_PENDING,
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
            $tokens->refund($request->user(), $creation, 'voice', 'fal_submit_failed');

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

    public function status(Request $request, UserVoiceCreation $creation, FalService $fal): JsonResponse
    {
        abort_unless($creation->isOwnedBy($request->user()), 403);

        if (! $creation->isTerminal() && $creation->fal_request_id) {
            $this->refresh($creation, $fal);
        }

        return response()->json($this->present($creation));
    }

    private function refresh(UserVoiceCreation $creation, FalService $fal): void
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
                'status' => UserVoiceCreation::STATUS_QUEUED,
                'queue_position' => $status['queue_position'] ?? null,
                'progress_message' => 'In queue',
            ])->save();

            return;
        }

        if ($state === 'IN_PROGRESS') {
            $creation->markInProgress(null, 'Generating…');

            return;
        }

        if ($state !== 'COMPLETED') {
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

            return;
        }

        $audioUrl = $this->extractAudioUrl($result);
        if ($audioUrl === null) {
            $creation->markFailed('Generation finished without audio.', 'empty_result');

            return;
        }

        $duration = null;
        $audio = $result['audio'] ?? null;
        if (is_array($audio) && isset($audio['duration'])) {
            $duration = (int) round((float) $audio['duration']);
        }

        $creation->forceFill([
            'status' => UserVoiceCreation::STATUS_COMPLETED,
            'result_audio_url' => $audioUrl,
            'result_preview_url' => $audioUrl,
            'result_assets' => [
                [
                    'url' => $audioUrl,
                    'content_type' => is_array($audio) ? ($audio['content_type'] ?? 'audio/mpeg') : 'audio/mpeg',
                    'duration' => $duration,
                ],
            ],
            'duration_seconds' => $duration,
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
        $audio = $result['audio'] ?? null;
        if (is_array($audio) && is_string($audio['url'] ?? null) && $audio['url'] !== '') {
            return $audio['url'];
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
     * @return array<string, mixed>
     */
    private function present(UserVoiceCreation $creation): array
    {
        return [
            'id' => $creation->id,
            'status' => $creation->status,
            'queue_position' => $creation->queue_position,
            'progress_message' => $creation->progress_message,
            'prompt' => $creation->prompt,
            'model_name' => $creation->model_name,
            'voice' => $creation->voice_name,
            'voice_id' => $creation->voice_id,
            'audio_url' => $creation->result_audio_url,
            'preview_url' => $creation->result_preview_url,
            'duration_seconds' => $creation->duration_seconds,
            'error' => $creation->error_message,
            'credits' => $creation->settings['credits'] ?? $creation->credits_charged,
            'token_balance' => (int) (auth()->user()?->fresh()->tokens ?? 0),
            'fal_cost_usd' => $creation->settings['fal_cost_usd'] ?? null,
            'created_at' => optional($creation->created_at)->toIso8601String(),
        ];
    }
}
