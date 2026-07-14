<?php

namespace App\Services;

class ToolsService
{
    public static function all(): array
    {
        return [
            ['name' => 'Video Upscaler', 'poster' => 'https://pollo.ai/cms/video_upscaler_1_cf89046d3c.jpg', 'video' => 'https://cdn.pollo.ai/prod/public/video/video-upscaler.mp4', 'route' => 'tools.video-upscaler'],
            ['name' => 'Video Enhancer', 'poster' => 'https://pollo.ai/cms/video_enhancer_01_6aada103e1.jpg', 'video' => 'https://cdn.pollo.ai/prod/public/video/pollo-tool-1/video-enhancer.mp4', 'route' => 'tools.video-enhancer'],
            ['name' => 'Lip Sync AI', 'poster' => 'https://pollo.ai/cms/lip_sync_01_s_a39f7003d2.jpg', 'video' => 'https://pollo.ai/cms/lip_sync_303beea674.mp4', 'route' => 'tools.lip-sync'],
            ['name' => 'Face Swap Video', 'poster' => 'https://pollo.ai/cms/face_swap_2_b94fa33515.jpg', 'video' => 'https://cdn.pollo.ai/prod/public/video/pollo-tool-1/face-swap-2.mp4', 'route' => 'tools.face-swap-video'],
            ['name' => 'Video Background Remover', 'poster' => 'https://pollo.ai/cms/video_background_remover_01_4228059d2f.jpg', 'video' => 'https://pollo.ai/cms/video_background_remover_01_e2f88fe200.mov', 'route' => 'tools.video-background-remover'],
            ['name' => 'Video Subtitle Remover', 'poster' => 'https://pollo.ai/cms/remove_subtitle_from_video_cover_ea229850e9.png', 'video' => 'https://pollo.ai/cms/remove_subtitle_from_video_cover_5f49cc2925.mp4', 'route' => 'tools.remove-subtitles-from-video'],
            ['name' => 'AI Video Extender', 'poster' => 'https://videocdn.pollo.ai/web-cdn/pollo/production/cm28d65vx0003mu8h9wmylk9s/image/1762937547656-092a753a-2f0b-4288-bd4a-eec360323474.jpg', 'video' => 'https://videocdn.pollo.ai/web-cdn/pollo/production/cm28d65vx0003mu8h9wmylk9s/video/1762937553049-6d617e98-8305-4e72-b141-48726776ef10.mp4', 'route' => 'tools.ai-video-extender', 'badge' => 'New'],
            ['name' => 'AI Video Editor', 'poster' => 'https://videocdn.pollo.ai/web-cdn/pollo/production/cmc8jcngp057iiw4hfxm2ke9x/image/1778242544397-2110370d-1546-4b59-abc1-0466e6b3e7f0.jpg', 'video' => 'https://videocdn.pollo.ai/web-cdn/pollo/production/cmc8jcngp057iiw4hfxm2ke9x/video/1778242574539-a094cfea-f8ea-48b3-8c9b-9870205a1697.mp4', 'route' => 'tools.ai-video-editor'],
            ['name' => 'Video To Video', 'poster' => 'https://videocdn.pollo.ai/web-cdn/pollo/production/cmc8jcngp057iiw4hfxm2ke9x/image/1778225048595-cf825063-8ff2-42d8-9de6-627c7b21c2a3.jpeg', 'video' => 'https://videocdn.pollo.ai/web-cdn/pollo/production/cmc8jcngp057iiw4hfxm2ke9x/video/1778224973022-55eaf519-caf6-477b-be35-abdc4be46c44.mp4', 'route' => 'tools.video-to-video'],
            ['name' => 'Image to Animation AI', 'poster' => 'https://pollo.ai/cms/animate_a_picture_landing_page_01_1_452346f8f5.jpg', 'video' => 'https://cdn.pollo.ai/prod/public/video/animate-a-picture/animate-a-picture-1.mp4', 'route' => 'tools.animate-a-picture'],
            ['name' => 'AI Sound Effect Generator', 'poster' => 'https://videocdn.pollo.ai/web-cdn/pollo/production/cmc8jcngp057iiw4hfxm2ke9x/image/1778223426099-e5d64dd1-c338-45f6-a67e-7f8e34451b8d.jpeg', 'video' => 'https://videocdn.pollo.ai/web-cdn/pollo/production/cmc8jcngp057iiw4hfxm2ke9x/video/1778223438226-02ac9e1a-1f22-4a7f-b081-eb5e7ebca71c.mp4', 'route' => 'tools.ai-sound-effect-generator'],
            ['name' => 'Denoise Video', 'poster' => 'https://pollo.ai/cms/denoise_video_01_4b8bb95229.jpg', 'video' => 'https://cdn.pollo.ai/prod/public/video/pollo-tool-1/denoise-video.mp4', 'route' => 'tools.denoise-video'],
            ['name' => 'AI Dance Generator', 'poster' => 'https://pollo.ai/cms/dance_image_7fcbd5c659.jpg', 'video' => 'https://pollo.ai/cms/dance_video_7466d9f26e.mp4', 'route' => 'tools.ai-dance-generator'],
            ['name' => 'Video to Anime', 'poster' => 'https://pollo.ai/cms/video_to_anime_converter_01_2_9b5741838a.jpg', 'video' => 'https://cdn.pollo.ai/prod/public/video/pollo-tool-1/video-to-anime.mp4', 'route' => 'tools.video-to-anime-ai'],
            ['name' => 'AI Video Filters', 'poster' => 'https://pollo.ai/cms/ai_video_filters_01_c3bfa28158.jpg', 'video' => 'https://cdn.pollo.ai/prod/public/video/pollo-tool-1/video-filters.mp4', 'route' => 'tools.ai-video-filters'],
            ['name' => 'Anime Video Enhancer', 'poster' => 'https://pollo.ai/cms/anime_video_enhancer_01_1d51d236ed.jpg', 'video' => 'https://cdn.pollo.ai/prod/public/video/pollo-tool-1/anime-video-enhancer.mp4', 'route' => 'tools.anime-video-enhancer'],
            ['name' => 'Motion Control', 'poster' => 'https://videocdn.pollo.ai/web-cdn/pollo/test/cm49hysvo0007ojfqoopf6ev5/image/1773909211531-186ecd5d-e5c3-4ddf-9162-bd9b039c4519.jpeg', 'video' => 'https://videocdn.pollo.ai/web-cdn/pollo/test/cm49hysvo0007ojfqoopf6ev5/video/1773909213843-47da5e3b-8b97-4b8b-857c-71c47115cf77.mp4', 'route' => 'tools.motion-control'],
        ];
    }
}
