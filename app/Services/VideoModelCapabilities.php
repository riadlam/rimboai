<?php

namespace App\Services;

/**
 * Declares which text-to-video catalog models can accept reference media,
 * and which fal sibling endpoint to call when media is present.
 */
class VideoModelCapabilities
{
    /**
     * @return array{
     *   supports_ref_images: bool,
     *   supports_ref_videos: bool,
     *   supports_ref_audio: bool,
     *   supports_first_frame: bool,
     *   supports_last_frame: bool,
     *   last_frame_required: bool,
     *   max_ref_images: int|null,
     *   max_ref_videos: int|null,
     *   max_ref_audios: int|null,
     *   reference_endpoint_id: string|null,
     *   first_frame_endpoint_id: string|null,
     *   first_frame_param: string|null,
     *   first_last_frame_endpoint_id: string|null,
     *   last_frame_param: string|null
     * }
     */
    public function for(string $endpointId): array
    {
        $id = strtolower(trim($endpointId));

        // Seedance — multimodal R2V + first-frame I2V + optional end frame on I2V
        if (str_contains($id, 'seedance') && str_contains($id, 'reference-to-video')) {
            // Catalog row is already the R2V endpoint — keep submit on this endpoint
            // (don't divert a single image to I2V when the user explicitly picked R2V).
            return $this->caps(
                images: true,
                videos: true,
                audio: true,
                firstFrame: false,
                lastFrame: false,
                lastRequired: false,
                maxImages: 9,
                maxVideos: 3,
                maxAudios: 3,
                reference: $id,
                firstFrameEndpoint: null,
                firstFrameParam: null,
                firstLastEndpoint: null,
                lastFrameParam: null,
            );
        }

        if (str_contains($id, 'seedance') && str_contains($id, 'text-to-video')) {
            $fast = str_contains($id, '/fast');
            $i2v = $fast
                ? 'bytedance/seedance-2.0/fast/image-to-video'
                : 'bytedance/seedance-2.0/image-to-video';

            return $this->caps(
                images: true,
                videos: true,
                audio: true,
                firstFrame: true,
                lastFrame: true,
                lastRequired: false,
                maxImages: 9,
                maxVideos: 3,
                maxAudios: 3,
                reference: $fast
                    ? 'bytedance/seedance-2.0/fast/reference-to-video'
                    : 'bytedance/seedance-2.0/reference-to-video',
                firstFrameEndpoint: $i2v,
                firstFrameParam: 'image_url',
                firstLastEndpoint: $i2v,
                lastFrameParam: 'end_image_url',
            );
        }

        // Veo — multi-image reference-to-video + first-frame I2V + first/last frame
        if ($id === 'fal-ai/veo3.1' || str_starts_with($id, 'fal-ai/veo3.1/')) {
            $tier = 'fal-ai/veo3.1';
            if (str_contains($id, '/fast')) {
                $tier = 'fal-ai/veo3.1/fast';
            } elseif (str_contains($id, '/lite')) {
                return $this->caps(
                    images: false,
                    videos: false,
                    audio: false,
                    firstFrame: true,
                    lastFrame: true,
                    lastRequired: true,
                    maxImages: 1,
                    maxVideos: 0,
                    maxAudios: 0,
                    reference: null,
                    firstFrameEndpoint: 'fal-ai/veo3.1/lite/image-to-video',
                    firstFrameParam: 'image_url',
                    firstLastEndpoint: 'fal-ai/veo3.1/lite/first-last-frame-to-video',
                    lastFrameParam: 'last_frame_url',
                );
            }

            return $this->caps(
                images: true,
                videos: false,
                audio: false,
                firstFrame: true,
                lastFrame: true,
                lastRequired: true,
                maxImages: 3,
                maxVideos: 0,
                maxAudios: 0,
                reference: $tier === 'fal-ai/veo3.1/fast'
                    ? 'fal-ai/veo3.1/fast/reference-to-video'
                    : 'fal-ai/veo3.1/reference-to-video',
                firstFrameEndpoint: $tier.'/image-to-video',
                firstFrameParam: 'image_url',
                firstLastEndpoint: $tier.'/first-last-frame-to-video',
                lastFrameParam: 'last_frame_url',
            );
        }

        if ($id === 'fal-ai/kling-video/o1/reference-to-video') {
            return $this->caps(
                images: true,
                videos: false,
                audio: false,
                firstFrame: true,
                lastFrame: true,
                lastRequired: false,
                maxImages: 7,
                maxVideos: 0,
                maxAudios: 0,
                reference: 'fal-ai/kling-video/o1/reference-to-video',
                firstFrameEndpoint: 'fal-ai/kling-video/o1/image-to-video',
                firstFrameParam: 'start_image_url',
                firstLastEndpoint: 'fal-ai/kling-video/o1/image-to-video',
                lastFrameParam: 'end_image_url',
            );
        }

        // Kling O3 / O1 video-to-video edit — character / face swap from image onto a source clip.
        if (str_contains($id, 'kling-video') && str_contains($id, 'video-to-video/edit')) {
            return $this->caps(
                images: true,
                videos: true,
                audio: false,
                firstFrame: false,
                lastFrame: false,
                lastRequired: false,
                maxImages: 3,
                maxVideos: 1,
                maxAudios: 0,
                reference: $id,
                firstFrameEndpoint: null,
                firstFrameParam: null,
                firstLastEndpoint: null,
                lastFrameParam: null,
            );
        }

        if ($id === 'fal-ai/kling-video/o3/4k/reference-to-video') {
            return $this->caps(
                images: true,
                videos: false,
                audio: false,
                firstFrame: false,
                lastFrame: false,
                lastRequired: false,
                maxImages: 7,
                maxVideos: 0,
                maxAudios: 0,
                reference: 'fal-ai/kling-video/o3/4k/reference-to-video',
                firstFrameEndpoint: null,
                firstFrameParam: null,
                firstLastEndpoint: null,
                lastFrameParam: null,
            );
        }

        if (str_contains($id, 'kling-video/o3/') && str_contains($id, 'text-to-video')) {
            $tier = str_contains($id, '/standard/') ? 'standard' : 'pro';

            return $this->caps(
                images: true,
                videos: false,
                audio: false,
                firstFrame: true,
                lastFrame: false,
                lastRequired: false,
                maxImages: 4,
                maxVideos: 0,
                maxAudios: 0,
                reference: "fal-ai/kling-video/o3/{$tier}/reference-to-video",
                firstFrameEndpoint: "fal-ai/kling-video/o3/{$tier}/image-to-video",
                firstFrameParam: 'image_url',
                firstLastEndpoint: null,
                lastFrameParam: null,
            );
        }

        // Kling v3 — I2V supports optional end_image_url (start → end transition)
        if (str_contains($id, 'kling-video/v3/') && str_contains($id, 'text-to-video')) {
            $tier = str_contains($id, '/standard/') ? 'standard' : 'pro';
            $i2v = "fal-ai/kling-video/v3/{$tier}/image-to-video";

            return $this->caps(
                images: false,
                videos: false,
                audio: false,
                firstFrame: true,
                lastFrame: true,
                lastRequired: false,
                maxImages: 1,
                maxVideos: 0,
                maxAudios: 0,
                reference: null,
                firstFrameEndpoint: $i2v,
                firstFrameParam: 'start_image_url',
                firstLastEndpoint: $i2v,
                lastFrameParam: 'end_image_url',
            );
        }

        // Kling family — first-frame I2V only unless overridden above
        if (str_contains($id, 'kling-video')) {
            $i2v = $this->klingI2vSibling($id);

            return $this->caps(
                images: false,
                videos: false,
                audio: false,
                firstFrame: $i2v !== null,
                lastFrame: false,
                lastRequired: false,
                maxImages: 1,
                maxVideos: 0,
                maxAudios: 0,
                reference: null,
                firstFrameEndpoint: $i2v,
                firstFrameParam: str_contains($id, '/o3/') ? 'image_url' : 'start_image_url',
                firstLastEndpoint: null,
                lastFrameParam: null,
            );
        }

        if (str_contains($id, 'wan/v2.7/text-to-video')) {
            return $this->caps(
                images: true,
                videos: true,
                audio: false,
                firstFrame: true,
                lastFrame: false,
                lastRequired: false,
                maxImages: 5,
                maxVideos: 5,
                maxAudios: 0,
                reference: 'fal-ai/wan/v2.7/reference-to-video',
                firstFrameEndpoint: 'fal-ai/wan/v2.7/image-to-video',
                firstFrameParam: 'image_url',
                firstLastEndpoint: null,
                lastFrameParam: null,
            );
        }

        if ($id === 'fal-ai/pixverse/c1/reference-to-video') {
            return $this->caps(
                images: true,
                videos: false,
                audio: false,
                firstFrame: false,
                lastFrame: false,
                lastRequired: false,
                maxImages: 5,
                maxVideos: 0,
                maxAudios: 0,
                reference: 'fal-ai/pixverse/c1/reference-to-video',
                firstFrameEndpoint: null,
                firstFrameParam: null,
                firstLastEndpoint: null,
                lastFrameParam: null,
            );
        }

        // Sora / Grok — first-frame I2V
        if (str_contains($id, 'sora-2/text-to-video')) {
            return $this->caps(false, false, false, true, false, false, 1, 0, 0, null, 'fal-ai/sora-2/image-to-video', 'image_url', null, null);
        }
        if (str_contains($id, 'grok-imagine-video/text-to-video')) {
            return $this->caps(false, false, false, true, false, false, 1, 0, 0, null, 'xai/grok-imagine-video/image-to-video', 'image_url', null, null);
        }

        return $this->caps(false, false, false, false, false, false, 0, 0, 0, null, null, null, null, null);
    }

    /**
     * Whether this catalog model can run with the given media mix.
     *
     * @param  array{images?: int, videos?: int, audios?: int}  $counts
     */
    public function supportsMediaMix(string $endpointId, array $counts, ?string $frameMode = null): bool
    {
        $images = (int) ($counts['images'] ?? 0);
        $videos = (int) ($counts['videos'] ?? 0);
        $audios = (int) ($counts['audios'] ?? 0);
        $total = $images + $videos + $audios;

        if ($total === 0) {
            return true;
        }

        $caps = $this->for($endpointId);

        if ($frameMode === 'first_last') {
            if (! $caps['supports_last_frame'] || ! $caps['first_last_frame_endpoint_id']) {
                return false;
            }
            if ($videos > 0 || $audios > 0) {
                return false;
            }
            if ($images < 1 || $images > 2) {
                return false;
            }
            if ($caps['last_frame_required'] && $images < 2) {
                return false;
            }

            return true;
        }

        // Seedance rule: audio requires at least one image or video.
        if ($audios > 0 && ($images + $videos) === 0) {
            return false;
        }

        // Kling V2V edit (face/character swap): needs a source video + at least one face/element image.
        if (str_contains(strtolower($endpointId), 'video-to-video/edit')) {
            if ($videos < 1 || $images < 1) {
                return false;
            }
        }

        if ($videos > 0 && ! $caps['supports_ref_videos']) {
            return false;
        }
        if ($audios > 0 && ! $caps['supports_ref_audio']) {
            return false;
        }
        if ($images > 1 && ! $caps['supports_ref_images']) {
            return false;
        }
        if ($images === 1 && $videos === 0 && $audios === 0) {
            if (! ($caps['supports_first_frame'] || $caps['supports_ref_images'])) {
                return false;
            }
        } elseif ($images > 0 && ! $caps['supports_ref_images'] && ! ($images === 1 && $caps['supports_first_frame'] && $videos === 0 && $audios === 0)) {
            return false;
        }

        // Per-model reference ceilings (e.g. Veo R2V max 3 images).
        if ($images > 0 && $caps['max_ref_images'] !== null && $images > $caps['max_ref_images']) {
            return false;
        }
        if ($videos > 0 && $caps['max_ref_videos'] !== null && $videos > $caps['max_ref_videos']) {
            return false;
        }
        if ($audios > 0 && $caps['max_ref_audios'] !== null && $audios > $caps['max_ref_audios']) {
            return false;
        }

        return true;
    }

    /**
     * Resolve which fal endpoint + mode to use for this media mix.
     *
     * @param  array{images?: int, videos?: int, audios?: int}  $counts
     * @return array{
     *   endpoint_id: string,
     *   mode: 'text-to-video'|'image-to-video'|'reference-to-video'|'first-last-frame-to-video',
     *   first_frame_param: string|null,
     *   last_frame_param: string|null
     * }|null
     */
    public function resolveRoute(string $endpointId, array $counts, ?string $frameMode = null): ?array
    {
        $images = (int) ($counts['images'] ?? 0);
        $videos = (int) ($counts['videos'] ?? 0);
        $audios = (int) ($counts['audios'] ?? 0);
        $total = $images + $videos + $audios;

        if ($total === 0) {
            // Explicit R2V catalog models still submit to themselves (prompt-only is allowed).
            $mode = str_contains(strtolower($endpointId), 'reference-to-video')
                ? 'reference-to-video'
                : 'text-to-video';

            return [
                'endpoint_id' => $endpointId,
                'mode' => $mode,
                'first_frame_param' => null,
                'last_frame_param' => null,
            ];
        }

        if (! $this->supportsMediaMix($endpointId, $counts, $frameMode)) {
            return null;
        }

        $caps = $this->for($endpointId);

        if ($frameMode === 'first_last' && $caps['supports_last_frame'] && $caps['first_last_frame_endpoint_id']) {
            if ($images >= 2) {
                $flfEndpoint = $caps['first_last_frame_endpoint_id'];
                $firstParam = str_contains($flfEndpoint, 'veo')
                    ? 'first_frame_url'
                    : ($caps['first_frame_param'] ?: 'first_frame_url');

                return [
                    'endpoint_id' => $flfEndpoint,
                    'mode' => 'first-last-frame-to-video',
                    'first_frame_param' => $firstParam,
                    'last_frame_param' => $caps['last_frame_param'] ?: 'last_frame_url',
                ];
            }

            // Only first frame provided — fall back to I2V when available.
            if ($images === 1 && $caps['first_frame_endpoint_id'] && ! $caps['last_frame_required']) {
                return [
                    'endpoint_id' => $caps['first_frame_endpoint_id'],
                    'mode' => 'image-to-video',
                    'first_frame_param' => $caps['first_frame_param'],
                    'last_frame_param' => null,
                ];
            }

            return null;
        }

        // Single image, no other media → first-frame I2V when available
        if ($images === 1 && $videos === 0 && $audios === 0 && $caps['supports_first_frame'] && $caps['first_frame_endpoint_id']) {
            return [
                'endpoint_id' => $caps['first_frame_endpoint_id'],
                'mode' => 'image-to-video',
                'first_frame_param' => $caps['first_frame_param'],
                'last_frame_param' => null,
            ];
        }

        // Multi-image / video / audio → reference-to-video
        if ($caps['reference_endpoint_id']) {
            return [
                'endpoint_id' => $caps['reference_endpoint_id'],
                'mode' => 'reference-to-video',
                'first_frame_param' => null,
                'last_frame_param' => null,
            ];
        }

        return null;
    }

    private function klingI2vSibling(string $id): ?string
    {
        $map = [
            'fal-ai/kling-video/v3/pro/text-to-video' => 'fal-ai/kling-video/v3/pro/image-to-video',
            'fal-ai/kling-video/o3/pro/text-to-video' => 'fal-ai/kling-video/o3/pro/image-to-video',
            'fal-ai/kling-video/v2.6/pro/text-to-video' => 'fal-ai/kling-video/v2.6/pro/image-to-video',
            'fal-ai/kling-video/v2.5-turbo/pro/text-to-video' => 'fal-ai/kling-video/v2.5-turbo/pro/image-to-video',
        ];

        return $map[$id] ?? (str_contains($id, 'text-to-video')
            ? str_replace('text-to-video', 'image-to-video', $id)
            : null);
    }

    /**
     * @return array{
     *   supports_ref_images: bool,
     *   supports_ref_videos: bool,
     *   supports_ref_audio: bool,
     *   supports_first_frame: bool,
     *   supports_last_frame: bool,
     *   last_frame_required: bool,
     *   max_ref_images: int|null,
     *   max_ref_videos: int|null,
     *   max_ref_audios: int|null,
     *   reference_endpoint_id: string|null,
     *   first_frame_endpoint_id: string|null,
     *   first_frame_param: string|null,
     *   first_last_frame_endpoint_id: string|null,
     *   last_frame_param: string|null
     * }
     */
    private function caps(
        bool $images,
        bool $videos,
        bool $audio,
        bool $firstFrame,
        bool $lastFrame,
        bool $lastRequired,
        ?int $maxImages,
        ?int $maxVideos,
        ?int $maxAudios,
        ?string $reference,
        ?string $firstFrameEndpoint,
        ?string $firstFrameParam,
        ?string $firstLastEndpoint,
        ?string $lastFrameParam,
    ): array {
        return [
            'supports_ref_images' => $images,
            'supports_ref_videos' => $videos,
            'supports_ref_audio' => $audio,
            'supports_first_frame' => $firstFrame,
            'supports_last_frame' => $lastFrame,
            'last_frame_required' => $lastRequired,
            'max_ref_images' => $maxImages,
            'max_ref_videos' => $maxVideos,
            'max_ref_audios' => $maxAudios,
            'reference_endpoint_id' => $reference,
            'first_frame_endpoint_id' => $firstFrameEndpoint,
            'first_frame_param' => $firstFrameParam,
            'first_last_frame_endpoint_id' => $firstLastEndpoint,
            'last_frame_param' => $lastFrameParam,
        ];
    }
}
