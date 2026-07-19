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
    /**
     * List this user's creations for a single tool (lab library shape).
     */
    public function index(Request $request, FalWebhookProcessor $processor): JsonResponse
    {
        $data = $request->validate([
            'tool_slug' => ['required', 'string', 'max:64'],
        ]);

        $userId = (int) $request->user()->id;
        $mode = 'tool:'.$data['tool_slug'];

        // Catch up active jobs if webhooks were missed.
        $active = UserVideoCreation::query()
            ->where('user_id', $userId)
            ->notDiscarded()
            ->where('mode', $mode)
            ->whereNotNull('fal_request_id')
            ->whereIn('status', [
                UserVideoCreation::STATUS_PENDING,
                UserVideoCreation::STATUS_QUEUED,
                UserVideoCreation::STATUS_IN_PROGRESS,
            ])
            ->orderByDesc('id')
            ->limit(5)
            ->get();

        foreach ($active as $creation) {
            try {
                $processor->syncFromFal('video', $creation);
            } catch (\Throwable $e) {
                report($e);
            }
        }

        $creations = UserVideoCreation::query()
            ->where('user_id', $userId)
            ->notDiscarded()
            ->where('mode', $mode)
            ->orderByDesc('created_at')
            ->limit(100)
            ->get();

        $images = [];
        foreach ($creations as $creation) {
            $item = $this->presentLibraryItem($creation);
            if ($item !== null) {
                $images[] = $item;
            }
        }

        return response()->json([
            'tool_slug' => $data['tool_slug'],
            'images' => $images,
        ]);
    }

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
                // 1️⃣ Upscaler
                'settings.scale' => ['nullable', 'string', 'max:16'],
                // 2️⃣ Enhancer / 8️⃣ V2V / 9️⃣ Denoise
                'settings.strength' => ['nullable', 'numeric', 'min:0', 'max:1'],
                'settings.guidance_scale' => ['nullable', 'numeric', 'min:1', 'max:30'],
                // 3️⃣ Lip Sync
                'settings.sync_mode' => ['nullable', 'string', 'max:32'],
                // 5️⃣ Background Remover
                'settings.background' => ['nullable', 'string', 'max:16'],
                'settings.preserve_audio' => ['nullable', 'boolean'],
                // 6️⃣ Subtitle Remover
                'settings.mask_prompt' => ['nullable', 'string', 'max:2000'],
                'settings.clean_prompt' => ['nullable', 'string', 'max:2000'],
                // 7️⃣ Extender / image→video / motion
                'settings.duration' => ['nullable'],
                'settings.direction' => ['nullable', 'string', 'max:16'],
                'settings.camera_movement' => ['nullable', 'string', 'max:32'],
                'settings.filter' => ['nullable', 'string', 'max:32'],
                // Shared / prompt-driven
                'settings.prompt' => ['nullable', 'string', 'max:2000'],
                'settings.negative_prompt' => ['nullable', 'string', 'max:500'],
                'settings.resolution' => ['nullable', 'string', 'max:16'],
                'settings.aspect_ratio' => ['nullable', 'string', 'max:16'],
                // Legacy keys (kept for backward compatibility)
                'settings.refine_edges' => ['nullable', 'boolean'],
                'settings.subject_is_person' => ['nullable', 'boolean'],
                'settings.output_codec' => ['nullable', 'string', 'max:16'],
                'settings.mode' => ['nullable', 'string', 'max:16'],
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
            : 0.0;

        if ($data['tool_slug'] === 'ai-video-extender') {
            $durationSeconds = (float) ($settings['duration'] ?? 5);
        }

        $defaults = $this->decodeJson($model->defaults) ?? [];
        $enums = $this->decodeJson($model->enums);
        $durationEnums = \App\Services\Tools\ToolWorkspaceBuilder::durationEnumsFor(
            (string) $data['tool_slug'],
            $enums,
            $defaults,
            $model->max_duration !== null ? (int) $model->max_duration : null,
        );

        // Snap UP to the model's supported duration tier before charging.
        $durationSeconds = \App\Services\Credits\ToolGenerationCostEstimator::snapBillableDuration(
            $durationSeconds,
            $durationEnums,
            $model->max_duration !== null ? (int) $model->max_duration : null,
        );

        if ($durationSeconds <= 0) {
            return response()->json(['message' => 'Upload a video (or pick a duration) so credits can be calculated.'], 422);
        }

        $settings['_duration_seconds'] = $durationSeconds;

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

        $billDuration = $durationSeconds;

        $cost = $costEstimator->estimate([
            'unit' => $model->unit,
            'unit_price' => $model->unit_price,
            'unit_price_by_resolution' => \App\Services\Tools\ToolWorkspaceBuilder::unitPriceByResolution(
                (string) $model->endpoint_id,
                (string) ($model->unit ?? 'seconds'),
            ),
            'duration_seconds' => $billDuration,
            'duration_enums' => $durationEnums,
            'max_duration' => $model->max_duration,
            'resolution' => $settings['resolution']
                ?? $built['resolution']
                ?? ($defaults['resolution'] ?? '720p'),
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
            $creation->refresh();
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
        }

        // Always attempt Fal wallet reconcile on terminal tools (same columns as labs:
        // cost_usd, fal_wallet_balance_after, deducted_amount_from_main_wallet).
        // Use recordAfterCompletion so delayed reconcile jobs are scheduled when
        // billing-events lag — maybeFillCostUsd alone does not schedule retries.
        if (
            $creation->fal_request_id
            && in_array($creation->status, [
                UserVideoCreation::STATUS_COMPLETED,
                UserVideoCreation::STATUS_FAILED,
            ], true)
            && ! $walletCost->isFullyReconciled($creation)
        ) {
            try {
                if ($creation->status === UserVideoCreation::STATUS_COMPLETED) {
                    $walletCost->recordAfterCompletion($creation);
                } else {
                    $walletCost->recordAfterFailure($creation);
                }
            } catch (\Throwable $e) {
                report($e);
            }
            $creation->refresh();
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
     * Lab-library card shape (same as LabCreationsController::imageItem for videos).
     *
     * @return array<string, mixed>|null
     */
    private function presentLibraryItem(UserVideoCreation $creation): ?array
    {
        $settings = is_array($creation->settings) ? $creation->settings : [];
        $batchId = "tool-creation-{$creation->id}";
        $createdMs = $creation->created_at?->getTimestampMs() ?? now()->getTimestampMs();
        $toolSlug = (string) ($settings['tool_slug'] ?? str_replace('tool:', '', (string) $creation->mode));

        $base = [
            'id' => "tool-{$creation->id}",
            'creation_id' => $creation->id,
            'batch_id' => $batchId,
            'batch_index' => 0,
            'prompt' => $creation->prompt ?: $toolSlug,
            'favorite' => (bool) $creation->is_favorite,
            'is_public' => (bool) $creation->is_public,
            'is_featured' => (bool) ($creation->is_featured ?? false),
            'created_at' => $creation->created_at?->toIso8601String(),
            'method' => 'text-to-video',
            'model' => null,
            'aspect' => $creation->aspect_ratio ?? '16:9',
            'resolution' => $creation->resolution,
            'duration' => $creation->duration_value ?? $creation->duration_seconds,
            'audio' => null,
            'input_assets' => [],
            'tool_slug' => $toolSlug,
        ];

        if ($creation->status === UserVideoCreation::STATUS_COMPLETED) {
            $src = $creation->thumbnail_url
                ?: $creation->result_preview_url
                ?: $creation->result_video_url
                ?: '';
            if ($src === '') {
                return null;
            }

            return array_merge($base, [
                'src' => $src,
                'video_url' => $creation->result_video_url,
                'status' => 'completed',
                'progress' => null,
                'queue_position' => null,
                'progress_percent' => 100,
                'error' => null,
            ]);
        }

        if ($creation->isTerminal()) {
            return array_merge($base, [
                'src' => '',
                'video_url' => null,
                'status' => $creation->status,
                'progress' => null,
                'queue_position' => null,
                'progress_percent' => null,
                'error' => $creation->error_message,
            ]);
        }

        return array_merge($base, [
            'src' => '',
            'video_url' => null,
            'started_at' => $createdMs,
            'status' => $creation->status,
            'progress' => $creation->progress_message,
            'queue_position' => $creation->queue_position,
            'progress_percent' => LabCreationPresenter::progressPercent($creation),
            'error' => null,
        ]);
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
            // Actual Fal billing (DB columns) — same as lab video creations.
            'cost_usd' => $creation->cost_usd !== null ? (float) $creation->cost_usd : null,
            'fal_wallet_balance_before' => $creation->fal_wallet_balance_before !== null
                ? (float) $creation->fal_wallet_balance_before
                : null,
            'fal_wallet_balance_after' => $creation->fal_wallet_balance_after !== null
                ? (float) $creation->fal_wallet_balance_after
                : null,
            'deducted_amount_from_main_wallet' => $creation->deducted_amount_from_main_wallet !== null
                ? (float) $creation->deducted_amount_from_main_wallet
                : null,
            'created_at' => optional($creation->created_at)?->toIso8601String(),
        ];
    }
}
