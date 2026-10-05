<?php

namespace App\Http\Controllers;

use App\Exceptions\InsufficientTokensException;
use App\Exceptions\TrendsRemakeException;
use App\Models\TrendTemplate;
use App\Services\ImageReferenceStorage;
use App\Services\TrendsFeedService;
use App\Services\TrendsRemakeService;
use App\Services\TrendTemplateRemakeService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Validation\Rule;

class TrendsController extends Controller
{
    public function useTemplate(Request $request, TrendsFeedService $trends): JsonResponse
    {
        $data = $request->validate([
            'type' => ['required', 'string', Rule::in(['image', 'video', 'music'])],
            'id' => ['required', 'integer', 'min:1'],
        ]);

        $result = $trends->useTemplate($data['type'], (int) $data['id']);
        if (! $result) {
            return response()->json(['message' => 'Template not found or not public.'], 404);
        }

        return response()->json([
            'ok' => true,
            'uses' => $result['uses'],
            'item' => $result['item'],
        ]);
    }

    public function remake(
        Request $request,
        TrendsRemakeService $remake,
        ImageReferenceStorage $imageStorage,
    ): JsonResponse {
        $data = $request->validate([
            'type' => ['required', 'string', Rule::in(['image', 'video', 'music'])],
            'id' => ['required', 'integer', 'min:1'],
            'image_urls' => ['nullable', 'array', 'max:9'],
            'image_urls.*' => ['string', 'max:2048'],
            'video_urls' => ['nullable', 'array', 'max:3'],
            'video_urls.*' => ['string', 'max:2048'],
            'audio_urls' => ['nullable', 'array', 'max:3'],
            'audio_urls.*' => ['string', 'max:2048'],
            'references' => ['nullable', 'array', 'max:8'],
            'references.*' => ['file', 'image', 'mimes:jpeg,jpg,png,webp,gif', 'max:10240'],
        ]);

        $imageUrls = array_values(array_filter($data['image_urls'] ?? [], fn ($u) => is_string($u) && $u !== ''));
        $videoUrls = array_values(array_filter($data['video_urls'] ?? [], fn ($u) => is_string($u) && $u !== ''));
        $audioUrls = array_values(array_filter($data['audio_urls'] ?? [], fn ($u) => is_string($u) && $u !== ''));

        /** @var array<int, UploadedFile>|UploadedFile|null $rawRefs */
        $rawRefs = $request->file('references');
        $uploadedRefs = is_array($rawRefs) ? $rawRefs : ($rawRefs ? [$rawRefs] : []);
        if ($uploadedRefs !== []) {
            try {
                $stored = $imageStorage->storeMany($request->user()->id, $uploadedRefs);
            } catch (\Throwable $e) {
                report($e);

                return response()->json(['message' => __('messages.upload_failed')], 502);
            }
            foreach ($stored as $asset) {
                $url = $asset['fal_url'] ?? $asset['url'] ?? null;
                if (is_string($url) && $url !== '') {
                    $imageUrls[] = $url;
                }
            }
        }

        try {
            $result = $remake->remake($request->user(), $data['type'], (int) $data['id'], [
                'image_urls' => $imageUrls,
                'video_urls' => $videoUrls,
                'audio_urls' => $audioUrls,
            ]);
        } catch (InsufficientTokensException $e) {
            return response()->json([
                'message' => __('messages.not_enough_tokens'),
                'required_tokens' => $e->required,
                'available_tokens' => $e->available,
            ], 402);
        } catch (TrendsRemakeException $e) {
            return response()->json(['message' => $e->getMessage()], $e->status());
        }

        return response()->json([
            'ok' => true,
            'type' => $result['type'],
            'user_remake_count' => $result['user_remake_count'] ?? 0,
            'user_latest' => $result['user_latest'] ?? null,
            ...$result['creation'],
        ], 201);
    }

    public function remakeTemplate(
        Request $request,
        string $slug,
        TrendTemplateRemakeService $remake,
        ImageReferenceStorage $imageStorage,
    ): JsonResponse {
        $template = TrendTemplate::query()->published()->where('slug', $slug)->first();
        if (! $template) {
            return response()->json(['message' => 'Template not found or not published.'], 404);
        }

        $clientSlots = $template->clientSlots();
        $slotKeys = array_map(fn (array $s) => $s['key'], $clientSlots);

        $data = $request->validate([
            'slot_urls' => ['nullable', 'array', 'max:8'],
            'slot_urls.*' => ['nullable', 'string', 'max:2048'],
            'image_urls' => ['nullable', 'array', 'max:8'],
            'image_urls.*' => ['string', 'max:2048'],
            'prompt' => ['nullable', 'string', 'max:100000'],
            'references' => ['nullable', 'array', 'max:8'],
            'references.*' => ['file', 'image', 'mimes:jpeg,jpg,png,webp,gif', 'max:10240'],
        ]);

        $slotPhotos = [];
        $named = is_array($data['slot_urls'] ?? null) ? $data['slot_urls'] : [];
        foreach ($named as $key => $url) {
            if (is_string($key) && is_string($url) && $url !== '' && in_array($key, $slotKeys, true)) {
                $slotPhotos[$key] = $url;
            }
        }

        // Ordered image_urls[] fallback when client uploads in slot order.
        if ($slotPhotos === [] && ! empty($data['image_urls'])) {
            $urls = array_values(array_filter($data['image_urls'], fn ($u) => is_string($u) && $u !== ''));
            foreach ($clientSlots as $i => $slot) {
                if (isset($urls[$i])) {
                    $slotPhotos[$slot['key']] = $urls[$i];
                }
            }
        }

        /** @var array<int, UploadedFile>|UploadedFile|null $rawRefs */
        $rawRefs = $request->file('references');
        $uploadedRefs = is_array($rawRefs) ? $rawRefs : ($rawRefs ? [$rawRefs] : []);
        if ($uploadedRefs !== [] && $slotPhotos === []) {
            try {
                $stored = $imageStorage->storeMany($request->user()->id, $uploadedRefs);
            } catch (\Throwable $e) {
                report($e);

                return response()->json(['message' => __('messages.upload_failed')], 502);
            }
            $i = 0;
            foreach ($clientSlots as $slot) {
                $asset = $stored[$i] ?? null;
                $i++;
                if (! is_array($asset)) {
                    continue;
                }
                $url = $asset['fal_url'] ?? $asset['url'] ?? null;
                if (is_string($url) && $url !== '') {
                    $slotPhotos[$slot['key']] = $url;
                }
            }
        }

        // Also accept slot-keyed file fields: files[body_right], etc.
        foreach ($clientSlots as $slot) {
            $file = $request->file('files.'.$slot['key']) ?? $request->file($slot['key']);
            if (! $file instanceof UploadedFile) {
                continue;
            }
            try {
                $stored = $imageStorage->storeMany($request->user()->id, [$file]);
            } catch (\Throwable $e) {
                report($e);

                return response()->json(['message' => __('messages.upload_failed')], 502);
            }
            $url = $stored[0]['fal_url'] ?? $stored[0]['url'] ?? null;
            if (is_string($url) && $url !== '') {
                $slotPhotos[$slot['key']] = $url;
            }
        }

        try {
            $result = $remake->remake(
                $request->user(),
                $template,
                $slotPhotos,
                is_string($data['prompt'] ?? null) ? $data['prompt'] : null,
            );
        } catch (InsufficientTokensException $e) {
            return response()->json([
                'message' => __('messages.not_enough_tokens'),
                'required_tokens' => $e->required,
                'available_tokens' => $e->available,
            ], 402);
        } catch (TrendsRemakeException $e) {
            return response()->json(['message' => $e->getMessage()], $e->status());
        }

        return response()->json([
            'ok' => true,
            'type' => $result['type'],
            'user_remake_count' => $result['user_remake_count'] ?? 0,
            'user_latest' => $result['user_latest'] ?? null,
            ...$result['creation'],
        ], 201);
    }

    public function setVisibility(Request $request, TrendsFeedService $trends): JsonResponse
    {
        $data = $request->validate([
            'type' => ['required', 'string', Rule::in(['image', 'video', 'music'])],
            'id' => ['required', 'integer', 'min:1'],
            'is_public' => ['sometimes', 'boolean'],
            'is_featured' => ['sometimes', 'boolean'],
        ]);

        if (! array_key_exists('is_public', $data) && ! array_key_exists('is_featured', $data)) {
            return response()->json(['message' => 'Provide is_public and/or is_featured.'], 422);
        }

        $result = $trends->setVisibility(
            $request->user(),
            $data['type'],
            (int) $data['id'],
            array_key_exists('is_public', $data) ? (bool) $data['is_public'] : null,
            array_key_exists('is_featured', $data) ? (bool) $data['is_featured'] : null,
        );

        if (! $result) {
            return response()->json(['message' => 'Creation not found or not ready to publish.'], 404);
        }

        return response()->json(['ok' => true, ...$result]);
    }
}
