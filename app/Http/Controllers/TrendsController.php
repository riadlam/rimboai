<?php

namespace App\Http\Controllers;

use App\Services\TrendsFeedService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
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
