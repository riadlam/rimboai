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
     *   reference_endpoint_id: string|null,
     *   first_frame_endpoint_id: string|null,
     *   first_frame_param: string|null
     * }
     */
    public function for(string $endpointId): array
    {
        $id = strtolower(trim($endpointId));

        // Seedance — true multimodal reference-to-video (+ first-frame I2V)
        if (str_contains($id, 'seedance') && str_contains($id, 'text-to-video')) {
            $fast = str_contains($id, '/fast');

            return $this->caps(
                images: true,
                videos: true,
                audio: true,
                firstFrame: true,
                reference: $fast
                    ? 'bytedance/seedance-2.0/fast/reference-to-video'
                    : 'bytedance/seedance-2.0/reference-to-video',
                firstFrameEndpoint: $fast
                    ? 'bytedance/seedance-2.0/fast/image-to-video'
                    : 'bytedance/seedance-2.0/image-to-video',
                firstFrameParam: 'image_url',
            );
        }

        // Veo — multi-image reference-to-video + first-frame I2V
        if ($id === 'fal-ai/veo3.1' || str_starts_with($id, 'fal-ai/veo3.1/')) {
            $tier = 'fal-ai/veo3.1';
            if (str_contains($id, '/fast')) {
                $tier = 'fal-ai/veo3.1/fast';
            } elseif (str_contains($id, '/lite')) {
                // Lite: first-frame only (no reference-to-video in catalog)
                return $this->caps(
                    images: false,
                    videos: false,
                    audio: false,
                    firstFrame: true,
                    reference: null,
                    firstFrameEndpoint: 'fal-ai/veo3.1/lite/image-to-video',
                    firstFrameParam: 'image_url',
                );
            }

            return $this->caps(
                images: true,
                videos: false,
                audio: false,
                firstFrame: true,
                reference: $tier === 'fal-ai/veo3.1/fast'
                    ? 'fal-ai/veo3.1/fast/reference-to-video'
                    : 'fal-ai/veo3.1/reference-to-video',
                firstFrameEndpoint: $tier.'/image-to-video',
                firstFrameParam: 'image_url',
            );
        }

        // Kling family — first-frame I2V only
        if (str_contains($id, 'kling-video')) {
            $i2v = $this->klingI2vSibling($id);

            return $this->caps(
                images: false,
                videos: false,
                audio: false,
                firstFrame: $i2v !== null,
                reference: null,
                firstFrameEndpoint: $i2v,
                firstFrameParam: str_contains($id, '/o3/') ? 'image_url' : 'start_image_url',
            );
        }

        // Sora / Wan / Grok — first-frame I2V
        if (str_contains($id, 'sora-2/text-to-video')) {
            return $this->caps(false, false, false, true, null, 'fal-ai/sora-2/image-to-video', 'image_url');
        }
        if (str_contains($id, 'wan/') && str_contains($id, 'text-to-video')) {
            return $this->caps(false, false, false, true, null, 'fal-ai/wan/v2.7/image-to-video', 'image_url');
        }
        if (str_contains($id, 'grok-imagine-video/text-to-video')) {
            return $this->caps(false, false, false, true, null, 'xai/grok-imagine-video/image-to-video', 'image_url');
        }

        return $this->caps(false, false, false, false, null, null, null);
    }

    /**
     * Whether this catalog model can run with the given media mix.
     *
     * @param  array{images?: int, videos?: int, audios?: int}  $counts
     */
    public function supportsMediaMix(string $endpointId, array $counts): bool
    {
        $images = (int) ($counts['images'] ?? 0);
        $videos = (int) ($counts['videos'] ?? 0);
        $audios = (int) ($counts['audios'] ?? 0);
        $total = $images + $videos + $audios;

        if ($total === 0) {
            return true;
        }

        // Seedance rule: audio requires at least one image or video.
        if ($audios > 0 && ($images + $videos) === 0) {
            return false;
        }

        $caps = $this->for($endpointId);

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
            return $caps['supports_first_frame'] || $caps['supports_ref_images'];
        }
        if ($images > 0 && ! $caps['supports_ref_images'] && ! ($images === 1 && $caps['supports_first_frame'] && $videos === 0 && $audios === 0)) {
            return false;
        }

        return true;
    }

    /**
     * Resolve which fal endpoint + mode to use for this media mix.
     *
     * @param  array{images?: int, videos?: int, audios?: int}  $counts
     * @return array{endpoint_id: string, mode: 'text-to-video'|'image-to-video'|'reference-to-video', first_frame_param: string|null}|null
     */
    public function resolveRoute(string $endpointId, array $counts): ?array
    {
        $images = (int) ($counts['images'] ?? 0);
        $videos = (int) ($counts['videos'] ?? 0);
        $audios = (int) ($counts['audios'] ?? 0);
        $total = $images + $videos + $audios;

        if ($total === 0) {
            return [
                'endpoint_id' => $endpointId,
                'mode' => 'text-to-video',
                'first_frame_param' => null,
            ];
        }

        if (! $this->supportsMediaMix($endpointId, $counts)) {
            return null;
        }

        $caps = $this->for($endpointId);

        // Single image, no other media → first-frame I2V when available
        if ($images === 1 && $videos === 0 && $audios === 0 && $caps['supports_first_frame'] && $caps['first_frame_endpoint_id']) {
            return [
                'endpoint_id' => $caps['first_frame_endpoint_id'],
                'mode' => 'image-to-video',
                'first_frame_param' => $caps['first_frame_param'],
            ];
        }

        // Multi-image / video / audio → reference-to-video
        if ($caps['reference_endpoint_id']) {
            return [
                'endpoint_id' => $caps['reference_endpoint_id'],
                'mode' => 'reference-to-video',
                'first_frame_param' => null,
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
     *   reference_endpoint_id: string|null,
     *   first_frame_endpoint_id: string|null,
     *   first_frame_param: string|null
     * }
     */
    private function caps(
        bool $images,
        bool $videos,
        bool $audio,
        bool $firstFrame,
        ?string $reference,
        ?string $firstFrameEndpoint,
        ?string $firstFrameParam,
    ): array {
        return [
            'supports_ref_images' => $images,
            'supports_ref_videos' => $videos,
            'supports_ref_audio' => $audio,
            'supports_first_frame' => $firstFrame,
            'reference_endpoint_id' => $reference,
            'first_frame_endpoint_id' => $firstFrameEndpoint,
            'first_frame_param' => $firstFrameParam,
        ];
    }
}
