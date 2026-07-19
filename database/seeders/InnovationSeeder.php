<?php

namespace Database\Seeders;

use App\Models\Innovation;
use App\Models\InnovationCategory;
use Illuminate\Database\Seeder;

class InnovationSeeder extends Seeder
{
    public function run(): void
    {
        $categories = [
            ['slug' => 'profile-avatar', 'name' => 'Profile & Avatar', 'icon' => 'user', 'gradient' => 'from-purple-900/80 via-pink-900/60 to-violet-900/80', 'sort' => 10],
            ['slug' => 'social-media', 'name' => 'Social Media', 'icon' => 'share2', 'gradient' => 'from-blue-900/80 via-cyan-900/60 to-teal-900/80', 'sort' => 20],
            ['slug' => 'infographic', 'name' => 'Infographic', 'icon' => 'chart-column', 'gradient' => 'from-emerald-900/80 via-green-900/60 to-lime-900/80', 'sort' => 30],
            ['slug' => 'youtube', 'name' => 'YouTube', 'icon' => 'youtube', 'gradient' => 'from-red-900/80 via-rose-900/60 to-pink-900/80', 'sort' => 40],
            ['slug' => 'comic', 'name' => 'Comic & Storyboard', 'icon' => 'book-open', 'gradient' => 'from-amber-900/80 via-orange-900/60 to-yellow-900/80', 'sort' => 50],
            ['slug' => 'product-marketing', 'name' => 'Product Marketing', 'icon' => 'megaphone', 'gradient' => 'from-indigo-900/80 via-blue-900/60 to-sky-900/80', 'sort' => 60],
            ['slug' => 'e-commerce', 'name' => 'E-commerce', 'icon' => 'shopping-bag', 'gradient' => 'from-fuchsia-900/80 via-purple-900/60 to-pink-900/80', 'sort' => 70],
            ['slug' => 'game-asset', 'name' => 'Game Asset', 'icon' => 'gamepad2', 'gradient' => 'from-orange-900/80 via-red-900/60 to-rose-900/80', 'sort' => 80],
            ['slug' => 'poster', 'name' => 'Poster & Flyer', 'icon' => 'file-image', 'gradient' => 'from-cyan-900/80 via-teal-900/60 to-emerald-900/80', 'sort' => 90],
            ['slug' => 'app-web-design', 'name' => 'App & Web Design', 'icon' => 'smartphone', 'gradient' => 'from-sky-900/80 via-blue-900/60 to-indigo-900/80', 'sort' => 100],
            ['slug' => 'music', 'name' => 'Music', 'icon' => 'music', 'gradient' => 'from-violet-900/80 via-fuchsia-900/60 to-purple-900/80', 'sort' => 110],
            ['slug' => 'other', 'name' => 'Other', 'icon' => 'sparkles', 'gradient' => 'from-zinc-800/80 via-zinc-700/60 to-zinc-800/80', 'sort' => 120],
        ];

        $categoryIds = [];
        foreach ($categories as $row) {
            $cat = InnovationCategory::query()->updateOrCreate(
                ['slug' => $row['slug']],
                array_merge($row, ['status' => 'active']),
            );
            $categoryIds[$row['slug']] = $cat->id;
        }

        $posts = $this->posts();
        $sort = 0;
        foreach ($posts as $post) {
            $sort += 10;
            $categorySlug = $post['category'];
            $mediaType = $post['media_type'];
            $title = $post['title'];
            $labType = match ($mediaType) {
                'video' => 'text-to-video',
                'music' => 'text-to-music',
                default => 'text-to-image',
            };
            $model = $post['model'] ?? match ($mediaType) {
                'video' => 'Kling 2.5 Pro',
                'music' => 'MiniMax Music 2.6',
                default => 'Nano Banana Pro',
            };
            $prompt = $post['prompt'] ?? $this->defaultPrompt($title, $mediaType, $categorySlug);

            Innovation::query()->updateOrCreate(
                ['slug' => $post['slug']],
                [
                    'innovation_category_id' => $categoryIds[$categorySlug] ?? $categoryIds['other'],
                    'title' => $title,
                    'prompt' => $prompt,
                    'media_type' => $mediaType,
                    'image_url' => $post['image_url'],
                    'image_urls' => $post['image_urls'] ?? (
                        is_string($post['image_url'] ?? null) ? [$post['image_url']] : null
                    ),
                    'video_url' => $post['video_url'] ?? null,
                    'audio_url' => $post['audio_url'] ?? null,
                    'model_name' => $model,
                    'endpoint_id' => $post['endpoint_id'] ?? null,
                    'lab_type' => $labType,
                    'aspect_ratio' => $post['aspect_ratio'] ?? match ($mediaType) {
                        'video' => '16:9',
                        'music' => null,
                        default => '1:1',
                    },
                    'resolution' => $post['resolution'] ?? match ($mediaType) {
                        'video' => '720p',
                        'music' => null,
                        default => '1K',
                    },
                    'duration' => $post['duration'] ?? ($mediaType === 'video' ? '5' : null),
                    'quantity' => $post['quantity'] ?? 1,
                    'generate_audio' => $post['generate_audio'] ?? ($mediaType === 'video' ? true : null),
                    'image_mode' => $post['image_mode'] ?? ($mediaType === 'image' ? 'create' : null),
                    'style_prompt' => $post['style_prompt'] ?? null,
                    'settings' => $post['settings'] ?? null,
                    'sort' => $sort,
                    'status' => 'active',
                    'is_featured' => (bool) ($post['is_featured'] ?? false),
                ],
            );
        }
    }

    private function defaultPrompt(string $title, string $mediaType, string $categorySlug): string
    {
        $kind = match ($mediaType) {
            'video' => 'cinematic video',
            'music' => 'music track with clear structure',
            default => 'detailed image',
        };

        return "Create a high-quality {$kind} inspired by: \"{$title}\".\n\nStyle: professional, sharp detail, balanced composition, rich lighting.\nCategory: {$categorySlug}.\nAvoid blurry artifacts, watermark, and text typos.";
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function posts(): array
    {
        return [
            [
                'slug' => 'social-media-5552',
                'title' => 'Tip for Using Nano Banana Pro: Adding Examples for Better Understanding',
                'category' => 'social-media',
                'media_type' => 'image',
                'image_url' => 'https://cms-assets.youmind.com/media/1765440114630_fl20t1_G7nc1_0aEAAcsiI.jpg',
                'is_featured' => true,
            ],
            [
                'slug' => 'poster-422',
                'title' => 'Medieval winter scene in old book set in Pereira, Colombia',
                'category' => 'poster',
                'media_type' => 'image',
                'image_url' => 'https://cms-assets.youmind.com/media/1764909120441_jrsdvl_G7HLhMqW8AAFALR.jpg',
                'prompt' => "Country: Colombia\nCity: Pereira\nDesign a medieval winter scene inside an old book",
                'model' => 'Nano Banana Pro',
                'aspect_ratio' => '3:4',
                'resolution' => '2K',
                'quantity' => 1,
                'image_mode' => 'create',
            ],
            [
                'slug' => 'profile-avatar-991',
                'title' => 'LINE-style Q-version emoji portrait grid',
                'category' => 'profile-avatar',
                'media_type' => 'image',
                'image_url' => 'https://cms-assets.youmind.com/media/1764209293843_81g6sf_G6i09XKXsAAzHUd.jpg',
                'aspect_ratio' => '1:1',
                'resolution' => '1K',
                'quantity' => 4,
            ],
            [
                'slug' => 'social-media-5911',
                'title' => 'Seasonal forest collage character prompt for Nano Banana Pro',
                'category' => 'social-media',
                'media_type' => 'image',
                'image_url' => 'https://cms-assets.youmind.com/media/1764577230231_j4g6fj_G7DE_85bIAAk3BJ.jpg',
                'aspect_ratio' => '4:3',
                'resolution' => '2K',
            ],
            [
                'slug' => 'poster-68',
                'title' => 'Music Star Poster Generation Template',
                'category' => 'poster',
                'media_type' => 'image',
                'image_url' => 'https://cms-assets.youmind.com/media/1769841155616_c6ufpt_G_7ydBzW0AAWu4x.png',
                'aspect_ratio' => '3:4',
                'resolution' => '2K',
            ],
            [
                'slug' => 'social-media-5607',
                'title' => 'Anime-style Graphic Recording from Web Search Results',
                'category' => 'social-media',
                'media_type' => 'image',
                'image_url' => 'https://cms-assets.youmind.com/media/1765440124440_xbx7rd_G7sOEp5aUAAKLvU.jpg',
            ],
            [
                'slug' => 'social-media-5458',
                'title' => 'Future Self Visualization Prompt (4 Grids)',
                'category' => 'social-media',
                'media_type' => 'image',
                'image_url' => 'https://cms-assets.youmind.com/media/1765509812594_d5ey9z_G70BnD9bsAAygr5.jpg',
            ],
            [
                'slug' => 'poster-360',
                'title' => '8K Cinematic Winter Adventure Photo on a Vintage Train',
                'category' => 'poster',
                'media_type' => 'image',
                'image_url' => 'https://cms-assets.youmind.com/media/1765440087611_ghti6o_G7sy_YHbIAAPZbX.jpg',
            ],
            [
                'slug' => 'poster-238',
                'title' => 'Hand-Embroidered Silk Album Cover Prompt (Music/Lyrics)',
                'category' => 'poster',
                'media_type' => 'image',
                'image_url' => 'https://cms-assets.youmind.com/media/1767061783878_ziw62d_G9LqAHxasAAOo20.jpg',
            ],
            [
                'slug' => 'social-media-1231',
                'title' => 'Anime Movie Poster Prompt: Chibi Character and Road Bike',
                'category' => 'social-media',
                'media_type' => 'image',
                'image_url' => 'https://cms-assets.youmind.com/media/1769927698361_4y8q0m_Go8dooFbYAAXzKj.jpg',
            ],
            [
                'slug' => 'other-529',
                'title' => 'Top View of Chess Board in Starting Position',
                'category' => 'other',
                'media_type' => 'image',
                'image_url' => 'https://cms-assets.youmind.com/media/1766042075854_qvzihe_G8XB5fTaIAA-yr8.jpg',
            ],
            [
                'slug' => 'other-623',
                'title' => 'YAML prompt conversion for images and diagrams',
                'category' => 'other',
                'media_type' => 'image',
                'image_url' => 'https://cms-assets.youmind.com/media/1764577566138_041vou_G67R84uaoAAb33m.jpg',
            ],
            [
                'slug' => 'profile-avatar-904',
                'title' => 'Cinematic Close-Up Portrait Prompt with Red-Tinted Glasses',
                'category' => 'profile-avatar',
                'media_type' => 'image',
                'image_url' => 'https://cms-assets.youmind.com/media/1765421940555_tcbeel_G7kCD3HbgAAsWXE.jpg',
            ],
            [
                'slug' => 'infographic-284',
                'title' => 'Predictive K-Line Chart Extension for Stock Trends',
                'category' => 'infographic',
                'media_type' => 'image',
                'image_url' => 'https://cms-assets.youmind.com/media/1765509789181_13l0y1_G71a8JDa0AALMNm.jpg',
            ],
            [
                'slug' => 'poster-342',
                'title' => 'Cinematic Urban Night Scene with Digital Billboard',
                'category' => 'poster',
                'media_type' => 'image',
                'image_url' => 'https://cms-assets.youmind.com/media/1765440034657_iaro9w_G7u73RTacAALXLM.jpg',
            ],
            [
                'slug' => 'social-media-5832',
                'title' => 'Die-Cut Sticker Illustration Prompt with Japanese Text',
                'category' => 'social-media',
                'media_type' => 'image',
                'image_url' => 'https://cms-assets.youmind.com/media/1765122651472_5g1m6q_G7dLSUEb0AQPTN2.jpg',
            ],
            [
                'slug' => 'product-marketing-2042',
                'title' => 'Stylized Nighttime Group Photo on Concrete Staircase',
                'category' => 'product-marketing',
                'media_type' => 'image',
                'image_url' => 'https://cms-assets.youmind.com/media/1768319176186_5pyfgt_G-d0BdyagAEnLuw.jpg',
            ],
            [
                'slug' => 'other-584',
                'title' => 'LINE Sticker Generation Prompt',
                'category' => 'other',
                'media_type' => 'image',
                'image_url' => 'https://cms-assets.youmind.com/media/1765967793359_4w2dzv_G73LM-xbIAA9Qkn.jpg',
            ],
            [
                'slug' => 'youtube-63',
                'title' => 'Premium Esports Gamer Portrait Prompt',
                'category' => 'youtube',
                'media_type' => 'image',
                'image_url' => 'https://cms-assets.youmind.com/media/1768226083853_13jvv1_G-XE0HHbQAAvOTo.jpg',
            ],
            [
                'slug' => 'profile-avatar-723',
                'title' => 'Photo to Vector Art Illustration',
                'category' => 'profile-avatar',
                'media_type' => 'image',
                'image_url' => 'https://cms-assets.youmind.com/media/1765967644224_a7e17m_G74yjm0aoAAB-mc.jpg',
            ],
            [
                'slug' => 'infographic-1',
                'title' => 'Hand-drawn style header image prompt from photo',
                'category' => 'infographic',
                'media_type' => 'image',
                'image_url' => 'https://cms-assets.youmind.com/media/1763885651870_4szbai_G6VZiROagAAqsIh.jpg',
            ],
            [
                'slug' => 'product-marketing-3132',
                'title' => 'Billboard Food Commercial Flat Lay',
                'category' => 'product-marketing',
                'media_type' => 'image',
                'image_url' => 'https://cms-assets.youmind.com/media/1765991226500_q0ahyr_G8Ixnw-a4AABI1o.jpg',
            ],
            [
                'slug' => 'other-703',
                'title' => 'Cheerful minimal edit to existing art',
                'category' => 'other',
                'media_type' => 'image',
                'image_url' => 'https://cms-assets.youmind.com/media/1763887274809_smjul2_G6Pxup6XcAEwLb2.jpg',
            ],
            [
                'slug' => 'other-635',
                'title' => 'Multiple characters in one basket',
                'category' => 'other',
                'media_type' => 'image',
                'image_url' => 'https://cms-assets.youmind.com/media/1763885713322_k74s2a_G6V7YsJXsAAiJ-g.jpg',
            ],
            [
                'slug' => 'game-asset-247',
                'title' => 'Ultra-Cinematic Arcane Sword Close-up',
                'category' => 'game-asset',
                'media_type' => 'image',
                'image_url' => 'https://cms-assets.youmind.com/media/1766237868955_hitpd6_G8bim48akAAuPuC.jpg',
            ],
            [
                'slug' => 'profile-avatar-815',
                'title' => 'Lifestyle Photo with a Duct-Taped Banana',
                'category' => 'profile-avatar',
                'media_type' => 'image',
                'image_url' => 'https://cms-assets.youmind.com/media/1765438632943_nf5rr7_G7qCWKwa0AAMG_a.jpg',
            ],
            [
                'slug' => 'social-media-6015',
                'title' => 'Selfie of multiple random female celebrities',
                'category' => 'social-media',
                'media_type' => 'image',
                'image_url' => 'https://cms-assets.youmind.com/media/1764209336879_bw7l5j_G6Oo4iwX0AAZOL9.jpg',
            ],
            [
                'slug' => 'product-marketing-3016',
                'title' => 'Cartoon to Funko Pop Transformation Prompt',
                'category' => 'product-marketing',
                'media_type' => 'image',
                'image_url' => 'https://cms-assets.youmind.com/media/1766489482183_crrrp6_G8sOnDqXAAAJ4U6.jpg',
            ],
            [
                'slug' => 'product-marketing-3383',
                'title' => 'Product sketch to final render prompt',
                'category' => 'product-marketing',
                'media_type' => 'image',
                'image_url' => 'https://cms-assets.youmind.com/media/1764577197724_4u7hm0_6uEg00so5XfBLc7c.jpg',
            ],
            [
                'slug' => 'other-876',
                'title' => 'Chinese poetic and crossover character prompts',
                'category' => 'other',
                'media_type' => 'image',
                'image_url' => 'https://cms-assets.youmind.com/media/1763886898619_8vo7nt_G6DY5TPagAAmwof.jpg',
            ],
            [
                'slug' => 'youtube-v-1',
                'title' => 'Cinematic trailer motion prompt',
                'category' => 'youtube',
                'media_type' => 'video',
                'image_url' => 'https://cms-assets.youmind.com/media/1768226083853_13jvv1_G-XE0HHbQAAvOTo.jpg',
                'video_url' => 'https://commondatastorage.googleapis.com/gtv-videos-bucket/sample/ForBiggerBlazes.mp4',
                'aspect_ratio' => '16:9',
                'resolution' => '1080p',
                'duration' => '8',
                'generate_audio' => true,
            ],
            [
                'slug' => 'product-marketing-v-1',
                'title' => 'Product reveal video prompt',
                'category' => 'product-marketing',
                'media_type' => 'video',
                'image_url' => 'https://cms-assets.youmind.com/media/1765991226500_q0ahyr_G8Ixnw-a4AABI1o.jpg',
                'video_url' => 'https://commondatastorage.googleapis.com/gtv-videos-bucket/sample/ForBiggerEscapes.mp4',
                'aspect_ratio' => '9:16',
                'resolution' => '720p',
                'duration' => '5',
                'generate_audio' => true,
            ],
            [
                'slug' => 'social-media-v-1',
                'title' => 'Short-form social motion prompt',
                'category' => 'social-media',
                'media_type' => 'video',
                'image_url' => 'https://cms-assets.youmind.com/media/1765440114630_fl20t1_G7nc1_0aEAAcsiI.jpg',
                'video_url' => 'https://commondatastorage.googleapis.com/gtv-videos-bucket/sample/ForBiggerJoyrides.mp4',
                'aspect_ratio' => '9:16',
                'resolution' => '720p',
                'duration' => '5',
                'generate_audio' => false,
            ],
            [
                'slug' => 'game-asset-v-1',
                'title' => 'Game cinematic camera move',
                'category' => 'game-asset',
                'media_type' => 'video',
                'image_url' => 'https://cms-assets.youmind.com/media/1766237868955_hitpd6_G8bim48akAAuPuC.jpg',
                'video_url' => 'https://commondatastorage.googleapis.com/gtv-videos-bucket/sample/ForBiggerMeltdowns.mp4',
                'aspect_ratio' => '16:9',
                'resolution' => '1080p',
                'duration' => '10',
                'generate_audio' => true,
            ],
            [
                'slug' => 'comic-v-1',
                'title' => 'Storyboard panel animation',
                'category' => 'comic',
                'media_type' => 'video',
                'image_url' => 'https://cms-assets.youmind.com/media/1765440087611_ghti6o_G7sy_YHbIAAPZbX.jpg',
                'video_url' => 'https://commondatastorage.googleapis.com/gtv-videos-bucket/sample/SubaruOutbackOnStreetAndDirt.mp4',
                'aspect_ratio' => '16:9',
                'resolution' => '720p',
                'duration' => 'auto',
                'generate_audio' => true,
            ],
            [
                'slug' => 'e-commerce-1',
                'title' => 'E-commerce product grid lifestyle',
                'category' => 'e-commerce',
                'media_type' => 'image',
                'image_url' => 'https://cms-assets.youmind.com/media/1764577197724_4u7hm0_6uEg00so5XfBLc7c.jpg',
            ],
            [
                'slug' => 'comic-1',
                'title' => 'Comic panel storyboard layout',
                'category' => 'comic',
                'media_type' => 'image',
                'image_url' => 'https://cms-assets.youmind.com/media/1769927698361_4y8q0m_Go8dooFbYAAXzKj.jpg',
            ],
            [
                'slug' => 'app-web-design-1',
                'title' => 'Mobile app UI mockup prompt',
                'category' => 'app-web-design',
                'media_type' => 'image',
                'image_url' => 'https://cms-assets.youmind.com/media/1763885651870_4szbai_G6VZiROagAAqsIh.jpg',
            ],
            [
                'slug' => 'music-rai-demo-1',
                'title' => 'Emotional Rai-pop breakup demo track',
                'category' => 'music',
                'media_type' => 'music',
                'image_url' => 'https://cms-assets.youmind.com/media/1767061783878_ziw62d_G9LqAHxasAAOo20.jpg',
                'model' => 'MiniMax Music 2.6',
                'prompt' => "[verse]\nSoft pads, warm bass, emotional rai-pop vibe\n[chorus]\nFemale vocal, modern mix, spacious reverb",
                'style_prompt' => 'rai, pop, emotional, female vocals, modern mix',
                'is_featured' => true,
            ],
        ];
    }
}
