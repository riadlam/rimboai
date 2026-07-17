<?php

namespace App\Http\Controllers;

use App\Exceptions\InsufficientTokensException;
use App\Models\UserVideoCreation;
use App\Services\Credits\ToolGenerationCostEstimator;
use App\Services\FalService;
use App\Services\FalToolInputBuilder;
use App\Services\FalWalletCostTracker;
use App\Services\FalWebhookProcessor;
use App\Services\LabCreationPresenter;
use App\Services\MediaReferenceStorage;
use App\Services\Tokens\TokenService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

class ToolGenerationController extends Controller
{
    public function store(
        Request $request,
        FalService $fal,
        FalToolInputBuilder $inputBuilder,
        ToolGenerationCostEstimator $costEstimator,
        MediaReferenceStorage $mediaStorage,
        TokenService $tokens,
        FalWalletCostTracker $walletCost,
        FalWebhookProcessor $processor,
    ): JsonResponse {
        if (! $fal->configured()) {
            return response()->json(['message' => 'Video service is not configured.'], 503);
        }

        try {
            $data = $request->validate([
                'model_id' => ['required', 'integer'],
                'tool_slug' => ['required', 'string', 'max:64'],
                'duration_seconds' => ['nullable', 'numeric', 'min:0.5', 'max:600'],
                'settings' => ['nullable', 'array'],
                'settings.scale' => ['nullable', 'string', 'max:16'],
                'settings.resolution' => ['nullable', 'string', 'max:16'],
                'settings.sync_mode' => ['nullable', 'string', 'max:32'],
                'settings.refine_edges' => ['nullable', 'boolean'],
                'settings.subject_is_person' => ['nullable', 'boolean'],
                'settings.output_codec' => ['nullable', 'string', 'max:16'],
                'settings.duration' => ['nullable'],
                'settings.mode' => ['nullable', 'string', 'max:16'],
                'settings.prompt' => ['nullable', 'string', 'max:2000'],
                'settings.noise' => ['nullable', 'numeric', 'min:0', 'max:1'],
                'video_url' => ['nullable', 'string', 'max:2048'],
                'image_url' => ['nullable', 'string', 'max:2048'],
                'audio_url' => ['nullable', 'string', 'max:2048'],
                'video' => ['nullable', 'file', 'max:51200'],
                'image' => ['nullable', 'file', 'max:30720'],
                'audio' => ['nullable', 'file', 'max:15360'],
            ]);
        } catch (ValidationException $e) {
            $first = collect($e->errors())->flatten()->first();

            return response()->json([
                'message' => is_string($first) && $first !== '' ? $first : 'Invalid tool request.',
                'errors' => $e->errors(),
            ], 422);
        }

        $model = DB::table('video_tools_models')
            ->where('id', (int) $data['model_id'])
            ->where('tool_slug', $data['tool_slug'])
            ->where('status', 'active')
            ->first();

        if (! $model || empty($model->endpoint_id)) {
            return response()->json(['message' => __('messages.model_unavailable')], 422);
        }

        $settings = is_array($data['settings'] ?? null) ? $data['settings'] : [];
        $inputAssets = [];
        $urls = ['video' => null, 'image' => null, 'audio' => null];

        try {
            foreach (['video', 'image', 'audio'] as $kind) {
                $file = $request->file($kind);
                if ($file instanceof UploadedFile) {
                    if (! $file->isValid()) {
                        return response()->json([
                            'message' => "The {$kind} upload failed. Try a smaller file.",
                        ], 422);
                    }
                    $stored = $mediaStorage->storeMany($request->user()->id, [$file], $kind);
                    $asset = $stored[0] ?? null;
                    if ($asset) {
                        $inputAssets[] = $asset;
                        $urls[$kind] = $asset['fal_url'] ?? $asset['url'] ?? null;
                    }
                }
            }
        } catch (\Throwable $e) {
            report($e);

            return response()->json(['message' => __('messages.upload_failed')], 502);
        }

        foreach (['video', 'image', 'audio'] as $kind) {
            $key = "{$kind}_url";
            if (! empty($data[$key]) && is_string($data[$key]) && $this->isAllowedFalMediaUrl($data[$key])) {
                $urls[$kind] = $data[$key];
                $inputAssets[] = [
                    'url' => $data[$key],
                    'fal_url' => $data[$key],
                    'type' => $kind,
                    'role' => 'reference',
                ];
            }
        }

        $durationSeconds = isset($data['duration_seconds'])
            ? (float) $data['duration_seconds']
            : (float) ($model->ref_duration_seconds ?? 5);

        if ($data['tool_slug'] === 'ai-video-extender') {
            $durationSeconds = (float) ($settings['duration'] ?? 5);
        }

        $settings['_duration_seconds'] = $durationSeconds;

        $defaults = $this->decodeJson($model->defaults) ?? [];

        try {
            $built = $inputBuilder->build(
                (string) $data['tool_slug'],
                (string) $model->endpoint_id,
                $defaults,
                $settings,
                $urls,
            );
        } catch (\InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        } catch (\Throwable $e) {
            report($e);

            return response()->json(['message' => 'Could not build tool request.'], 422);
        }

        $billDuration = $built['duration_seconds'];
        if ($data['tool_slug'] !== 'ai-video-extender') {
            $billDuration = $durationSeconds;
        }

        $cost = $costEstimator->estimate([
            'unit' => $model->unit,
            'unit_price' => $model->unit_price,
            'duration_seconds' => $billDuration,
            'max_duration' => $model->max_duration,
            'resolution' => $built['resolution'] ?? ($settings['resolution'] ?? '1080p'),
            'fps' => str_contains((string) $model->unit, 'frames') ? 30 : 24,
        ]);

        if ((int) $cost['credits'] <= 0) {
            return response()->json(['message' => __('messages.model_unavailable')], 503);
        }

        $prompt = $built['prompt']
            ?? (is_string($defaults['prompt'] ?? null) ? $defaults['prompt'] : null)
            ?? $model->tool_name
            ?? $data['tool_slug'];

        try {
            /** @var UserVideoCreation $creation */
            $creation = $tokens->reserve(
                $request->user(),
                (int) $cost['credits'],
                'video',
                fn () => UserVideoCreation::create([
                    'user_id' => $request->user()->id,
                    'mode' => 'tool:'.$data['tool_slug'],
                    'endpoint_id' => $model->endpoint_id,
                    'model_name' => $model->name,
                    'prompt' => is_string($prompt) && $prompt !== '' ? $prompt : (string) $data['tool_slug'],
                    'input_assets' => $inputAssets ?: null,
                    'settings' => [
                        'tool_slug' => $data['tool_slug'],
                        'model_id' => (int) $model->id,
                        'client_settings' => $this->sanitizeClientSettings($settings),
                        'fal_input' => $built['input'],
                        'fal_endpoint' => $model->endpoint_id,
                        'billing_unit' => $cost['unit'],
                        'billing_unit_price' => $cost['unit_price'],
                        'fal_cost_usd' => $cost['fal_cost_usd'],
                        'credits' => $cost['credits'],
                        'cost_breakdown' => $cost['breakdown'],
                        'is_tool' => true,
                    ],
                    'duration_value' => (string) round($billDuration),
                    'duration_seconds' => (int) round($billDuration),
                    'aspect_ratio' => null,
                    'resolution' => $built['resolution'],
                    'with_audio' => false,
                    'credits_charged' => $cost['credits'],
                    'status' => UserVideoCreation::STATUS_PENDING,
                ]),
            );
        } catch (InsufficientTokensException $e) {
            return response()->json([
                'message' => __('messages.not_enough_tokens'),
                'required_tokens' => $e->required,
                'available_tokens' => $e->available,
            ], 402);
        }

        try {
            $walletCost->recordBalanceBefore($creation);
            $submit = $fal->submit((string) $model->endpoint_id, $built['input']);
        } catch (\Throwable $e) {
            report($e);
            Log::error('tool.fal.submit_failed', [
                'tool_slug' => $data['tool_slug'],
                'endpoint_id' => $model->endpoint_id,
                'message' => $e->getMessage(),
            ]);
            $creation->markFailed(__('messages.could_not_start'), 'submit_error');
            $tokens->refund($request->user(), $creation, 'video', 'fal_submit_failed');

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

        $creation->refresh();
        $processor->broadcastSnapshot('video', $creation);

        return response()->json($this->present($creation), 201);
    }

    public function status(
        Request $request,
        UserVideoCreation $creation,
        FalWebhookProcessor $processor,
        FalWalletCostTracker $walletCost,
    ): JsonResponse {
        abort_unless($creation->isOwnedBy($request->user()), 403);

        $settings = is_array($creation->settings) ? $creation->settings : [];
        abort_unless(! empty($settings['is_tool']), 404);

        if (! $creation->isTerminal() && $creation->fal_request_id) {
            $processor->syncFromFal('video', $creation);
            $creation->refresh();
        } elseif (
            $creation->status === UserVideoCreation::STATUS_COMPLETED
            && $creation->fal_request_id
            && ! $walletCost->isFullyReconciled($creation)
        ) {
            $walletCost->maybeFillCostUsd($creation);
        }

        return response()->json($this->present($creation));
    }

    /**
     * @param  array<string, mixed>  $settings
     * @return array<string, mixed>
     */
    private function sanitizeClientSettings(array $settings): array
    {
        unset($settings['_duration_seconds']);

        return $settings;
    }

    private function isAllowedFalMediaUrl(string $url): bool
    {
        $parts = parse_url($url);
        if (! is_array($parts) || ($parts['scheme'] ?? '') !== 'https') {
            return false;
        }
        $host = strtolower((string) ($parts['host'] ?? ''));
        if ($host === '') {
            return false;
        }

        return $host === 'fal.media'
            || str_ends_with($host, '.fal.media')
            || $host === 'v3.fal.media'
            || $host === 'v3b.fal.media';
    }

    /**
     * @return ($value is null ? null : array<mixed>)
     */
    private function decodeJson(mixed $value): ?array
    {
        if ($value === null || $value === '') {
            return null;
        }
        if (is_array($value)) {
            return $value;
        }
        if (is_string($value)) {
            $decoded = json_decode($value, true);

            return is_array($decoded) ? $decoded : null;
        }

        return null;
    }

    /**
     * @return array<string, mixed>
     */
    private function present(UserVideoCreation $creation): array
    {
        $settings = is_array($creation->settings) ? $creation->settings : [];

        return [
            'id' => $creation->id,
            'status' => $creation->status,
            'queue_position' => $creation->queue_position,
            'progress_message' => $creation->progress_message,
            'progress_percent' => LabCreationPresenter::progressPercent($creation),
            'prompt' => $creation->prompt,
            'model_name' => null,
            'video_url' => $creation->result_video_url,
            'thumbnail_url' => $creation->thumbnail_url,
            'preview_url' => $creation->result_preview_url,
            'error' => $creation->error_message,
            'credits' => $settings['credits'] ?? $creation->credits_charged,
            'token_balance' => null,
            'tool_slug' => $settings['tool_slug'] ?? null,
            'created_at' => optional($creation->created_at)?->toIso8601String(),
        ];
    }
}
