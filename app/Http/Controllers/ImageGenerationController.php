<?php

namespace App\Http\Controllers;

use App\Exceptions\InsufficientTokensException;
use App\Models\UserImageCreation;
use App\Services\AssetPromptReferences;
use App\Services\Credits\ImageGenerationCostEstimator;
use App\Services\FalImageInputBuilder;
use App\Services\FalPricingService;
use App\Services\FalService;
use App\Services\FalWalletCostTracker;
use App\Services\ImageReferenceStorage;
use App\Services\Tokens\TokenService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class ImageGenerationController extends Controller
{
    /**
     * Start a text-to-image generation on the fal queue.
     */
    public function store(
        Request $request,
        FalService $fal,
        FalImageInputBuilder $inputBuilder,
        ImageReferenceStorage $referenceStorage,
        ImageGenerationCostEstimator $costEstimator,
        TokenService $tokens,
        FalPricingService $pricing,
        AssetPromptReferences $promptReferences,
        FalWalletCostTracker $walletCost,
    ): JsonResponse {
        $data = $request->validate([
            'prompt' => ['required', 'string', 'min:2', 'max:2000'],
            'endpoint_id' => ['nullable', 'string', 'max:191'],
            'aspect' => ['nullable', 'string', Rule::in(['1:1', '16:9', '9:16', '4:3', '3:4'])],
            'resolution' => ['nullable', 'string', Rule::in(['1K', '2K', '4K'])],
            'quantity' => ['nullable', 'integer', 'min:1', 'max:4'],
            'mode' => ['nullable', 'string', Rule::in(['create', 'variations'])],
            'references' => ['nullable', 'array', 'max:8'],
            'references.*' => ['file', 'image', 'mimes:jpeg,jpg,png,webp,gif', 'max:10240'],
        ]);

        if (! $fal->configured()) {
            return response()->json(['message' => 'Image service is not configured.'], 503);
        }

        /** @var array<int, UploadedFile>|UploadedFile|null $rawRefs */
        $rawRefs = $request->file('references');
        $uploadedRefs = is_array($rawRefs) ? $rawRefs : ($rawRefs ? [$rawRefs] : []);

        try {
            $inputAssets = $referenceStorage->storeMany($request->user()->id, $uploadedRefs);
        } catch (\Throwable $e) {
            report($e);

            return response()->json(['message' => __('messages.upload_failed')], 502);
        }

        $referenceUrls = array_values(array_filter(array_map(
            fn (array $asset) => $asset['fal_url'] ?? $asset['url'] ?? null,
            $inputAssets,
        )));

        if ($uploadedRefs !== [] && $inputAssets === []) {
            return response()->json(['message' => 'Could not store reference images.'], 422);
        }

        // Whitelist: only allow endpoints that exist in our active catalog.
        // This prevents callers from invoking arbitrary/expensive fal models.
        $model = null;
        if (! empty($data['endpoint_id'])) {
            $model = DB::table('text_to_image_models')
                ->where('endpoint_id', $data['endpoint_id'])
                ->where('status', 'active')
                ->first();
        }

        if (! $model) {
            $model = DB::table('text_to_image_models')
                ->where('status', 'active')
                ->orderBy('sort')
                ->first();
        }

        if (! $model) {
            return response()->json(['message' => __('messages.model_unavailable')], 422);
        }

        $mode = $data['mode'] ?? 'create';

        if ($mode === 'variations' && $referenceUrls === []) {
            return response()->json([
                'message' => 'Variations mode requires at least one source image.',
            ], 422);
        }

        if (! $inputBuilder->supportsReferences($model->endpoint_id, $referenceUrls)) {
            $message = $mode === 'variations'
                ? 'This model does not support variations. Please choose another model.'
                : 'The selected model does not support visual references. Remove references or pick another model.';

            return response()->json(['message' => $message], 422);
        }

        if ($mode === 'variations' && ! $inputBuilder->supportsVariations($model->endpoint_id)) {
            return response()->json([
                'message' => 'This model does not support variations. Please choose another model.',
            ], 422);
        }

        $aspect = $data['aspect'] ?? '1:1';
        $resolution = $data['resolution'] ?? '1K';
        $quantity = (int) ($data['quantity'] ?? 1);
        $hasReferences = $referenceUrls !== [];
        $creationMode = $mode === 'variations' || $hasReferences ? 'image-to-image' : 'text-to-image';

        $providerPrompt = $promptReferences->resolve($data['prompt'], [
            'image' => count($referenceUrls),
        ]);

        $falInput = $inputBuilder->build($model->endpoint_id, [
            'prompt' => $providerPrompt,
            'aspect' => $aspect,
            'resolution' => $resolution,
            'quantity' => $quantity,
            'reference_urls' => $referenceUrls,
        ]);

        $submitEndpoint = $inputBuilder->resolveEndpoint($model->endpoint_id, $referenceUrls);
        $billing = $pricing->resolve($submitEndpoint);
        if ($billing === null) {
            return response()->json([
                'message' => __('messages.model_unavailable'),
                'endpoint_id' => $submitEndpoint,
            ], 503);
        }

        $cost = $costEstimator->estimate([
            'endpoint_id' => $submitEndpoint,
            'unit' => $billing['unit'],
            'unit_price' => $billing['unit_price'],
            'aspect' => $aspect,
            'resolution' => $resolution,
            'quantity' => $quantity,
            'reference_count' => count($referenceUrls),
        ]);

        if ((int) $cost['credits'] <= 0) {
            return response()->json([
                'message' => __('messages.model_unavailable'),
                'endpoint_id' => $submitEndpoint,
            ], 503);
        }

        try {
            /** @var UserImageCreation $creation */
            $creation = $tokens->reserve(
                $request->user(),
                (int) $cost['credits'],
                'image',
                fn () => UserImageCreation::create([
                    'user_id' => $request->user()->id,
                    'mode' => $creationMode,
                    'endpoint_id' => $submitEndpoint,
                    'model_name' => $model->name,
                    'prompt' => $data['prompt'],
                    'input_assets' => $inputAssets ?: null,
                    'settings' => [
                        'aspect' => $aspect,
                        'resolution' => $resolution,
                        'quantity' => $quantity,
                        'mode' => $mode,
                        'fal_input' => $falInput,
                        'catalog_endpoint' => $model->endpoint_id,
                        'fal_endpoint' => $submitEndpoint,
                        'billing_endpoint' => $billing['endpoint_id'],
                        'billing_source' => $billing['source'],
                        'billing_unit' => $billing['unit'],
                        'billing_unit_price' => $billing['unit_price'],
                        'fal_cost_usd' => $cost['fal_cost_usd'],
                        'credits' => $cost['credits'],
                        'cost_breakdown' => $cost['breakdown'],
                    ],
                    'credits_charged' => $cost['credits'],
                    'status' => UserImageCreation::STATUS_PENDING,
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
            $submit = $fal->submit($submitEndpoint, $falInput);
        } catch (\Throwable $e) {
            report($e);
            $creation->markFailed(__('messages.could_not_start'), 'submit_error');
            $tokens->refund($request->user(), $creation, 'image', 'fal_submit_failed');

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

    /**
     * Return the current status of a creation, refreshing from fal if needed.
     */
    public function status(Request $request, UserImageCreation $creation, FalService $fal, FalWalletCostTracker $walletCost): JsonResponse
    {
        abort_unless($creation->isOwnedBy($request->user()), 403);

        if (! $creation->isTerminal() && $creation->fal_request_id) {
            $this->refresh($creation, $fal, $walletCost);
        } elseif (
            $creation->status === UserImageCreation::STATUS_COMPLETED
            && $creation->cost_usd === null
            && $creation->fal_request_id
        ) {
            $walletCost->maybeFillCostUsd($creation);
        }

        return response()->json($this->present($creation));
    }

    private function refresh(UserImageCreation $creation, FalService $fal, FalWalletCostTracker $walletCost): void
    {
        try {
            $status = $creation->fal_status_url
                ? $fal->statusByUrl($creation->fal_status_url)
                : null;
        } catch (\Throwable $e) {
            report($e);

            return; // Transient — client will poll again.
        }

        if (! is_array($status)) {
            return;
        }

        $state = $status['status'] ?? null;

        if ($state === 'IN_QUEUE') {
            $creation->forceFill([
                'status' => UserImageCreation::STATUS_QUEUED,
                'queue_position' => $status['queue_position'] ?? null,
                'progress_message' => 'In queue',
            ])->save();

            return;
        }

        if ($state === 'IN_PROGRESS') {
            $creation->markInProgress(null, __('messages.generating'));

            return;
        }

        if ($state !== 'COMPLETED') {
            return;
        }

        // COMPLETED may still carry an error.
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

        $images = $this->extractImages($result);

        if ($images === []) {
            $creation->markFailed(__('messages.no_image'), 'empty_result');

            return;
        }

        $creation->forceFill([
            'status' => UserImageCreation::STATUS_COMPLETED,
            'result_assets' => $images,
            'result_preview_url' => $images[0]['url'] ?? null,
            'progress_message' => 'Completed',
            'queue_position' => null,
            'completed_at' => now(),
            'error_message' => null,
            'error_type' => null,
        ])->save();

        $walletCost->recordAfterCompletion($creation);
    }

    /**
     * @param  array<string, mixed>  $result
     * @return array<int, array<string, mixed>>
     */
    private function extractImages(array $result): array
    {
        $raw = $result['images'] ?? [];

        if (! is_array($raw)) {
            return [];
        }

        $images = [];
        foreach ($raw as $img) {
            if (! is_array($img) || empty($img['url']) || ! is_string($img['url'])) {
                continue;
            }

            $images[] = [
                'url' => $img['url'],
                'content_type' => $img['content_type'] ?? null,
                'width' => $img['width'] ?? null,
                'height' => $img['height'] ?? null,
            ];
        }

        return $images;
    }

    /**
     * Shape returned to the client — never includes keys or raw fal URLs.
     *
     * @return array<string, mixed>
     */
    private function present(UserImageCreation $creation): array
    {
        return [
            'id' => $creation->id,
            'status' => $creation->status,
            'queue_position' => $creation->queue_position,
            'progress_message' => $creation->progress_message,
            'prompt' => $creation->prompt,
            'model_name' => $creation->model_name,
            'images' => collect($creation->result_assets ?? [])
                ->pluck('url')
                ->filter()
                ->values()
                ->all(),
            'preview_url' => $creation->result_preview_url,
            'error' => $creation->error_message,
            'credits' => $creation->settings['credits'] ?? null,
            'token_balance' => (int) (auth()->user()?->fresh()->tokens ?? 0),
            'fal_cost_usd' => $creation->settings['fal_cost_usd'] ?? null,
            'created_at' => optional($creation->created_at)->toIso8601String(),
        ];
    }
}
