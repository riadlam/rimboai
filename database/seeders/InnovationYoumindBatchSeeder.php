<?php

namespace Database\Seeders;

use App\Models\Innovation;
use App\Models\InnovationCategory;
use Illuminate\Database\Seeder;

/**
 * Fresh Youmind-sourced innovations for e-commerce (1:1) and social-media (9:16).
 * Prompts are stored verbatim — do not trim or rewrite content.
 */
class InnovationYoumindBatchSeeder extends Seeder
{
    private const MODEL = 'Nano Banana Pro';

    private const ENDPOINT = 'fal-ai/nano-banana-pro';

    public function run(): void
    {
        $categories = InnovationCategory::query()
            ->whereIn('slug', ['e-commerce', 'social-media'])
            ->pluck('id', 'slug');

        if ($categories->isEmpty()) {
            $this->command?->warn('Innovation categories missing — run InnovationSeeder first.');

            return;
        }

        $sort = (int) (Innovation::query()->max('sort') ?? 0);

        foreach ($this->posts() as $post) {
            $sort += 10;
            $urls = array_values(array_filter($post['image_urls'] ?? [], static fn ($u) => is_string($u) && $u !== ''));
            $primary = $urls[0] ?? ($post['image_url'] ?? null);

            Innovation::query()->updateOrCreate(
                ['slug' => $post['slug']],
                [
                    'innovation_category_id' => $categories[$post['category']] ?? $categories->first(),
                    'title' => $post['title'],
                    'prompt' => $post['prompt'],
                    'media_type' => 'image',
                    'image_url' => $primary,
                    'video_url' => null,
                    'audio_url' => null,
                    'model_name' => self::MODEL,
                    'endpoint_id' => self::ENDPOINT,
                    'lab_type' => 'text-to-image',
                    'aspect_ratio' => $post['aspect_ratio'],
                    'resolution' => '1K',
                    'duration' => null,
                    'quantity' => 1,
                    'generate_audio' => null,
                    'image_mode' => 'create',
                    'style_prompt' => null,
                    // Multi-frame gallery lives in existing settings JSON (no new column).
                    'settings' => [
                        'image_urls' => $urls,
                    ],
                    'sort' => $sort,
                    'status' => 'active',
                    'is_featured' => (bool) ($post['is_featured'] ?? false),
                ],
            );
        }
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function posts(): array
    {
        return [
            // ─── e-commerce · 1:1 ───────────────────────────────────────────
            [
                'slug' => 'e-commerce-colossal-product-city',
                'title' => 'Colossal Product in City',
                'category' => 'e-commerce',
                'aspect_ratio' => '1:1',
                'is_featured' => true,
                'image_urls' => [
                    'https://youmind.com/cdn-cgi/image/width=828,quality=90,format=auto,metadata=none/https%3A%2F%2Fcms-assets.youmind.com%2Fmedia%2F1783753511208_a1h26g_HM4otU-X0AARXE9-900x600.jpg',
                    'https://youmind.com/cdn-cgi/image/width=828,quality=90,format=auto,metadata=none/https%3A%2F%2Fcms-assets.youmind.com%2Fmedia%2F1783753510504_aot4ui_HM4osjaXsAA9rmo-900x600.jpg',
                    'https://youmind.com/cdn-cgi/image/width=828,quality=90,format=auto,metadata=none/https%3A%2F%2Fcms-assets.youmind.com%2Fmedia%2F1783753510627_j5oo4p_HM4oszUWgAAZuc5-900x601.jpg',
                ],
                'prompt' => <<<'PROMPT'
A colossal {argument name="product" default="luxury sneaker"} naturally integrated into {argument name="location" default="a busy city square"}, hovering low above the ground/water with realistic scale, accurate perspective, soft contact shadows, reflected light, environmental reflections on the product surface, people and vehicles reacting below, cinematic advertising composition, hyper-realistic photography, ultra-detailed materials, dramatic lighting, vibrant color grading, high-resolution commercial render.
PROMPT,
            ],
            [
                'slug' => 'e-commerce-luxury-skincare-campaign',
                'title' => 'Luxury Skincare Campaign',
                'category' => 'e-commerce',
                'aspect_ratio' => '1:1',
                'image_urls' => [
                    'https://cms-assets.youmind.com/media/1783063701886_3qzdam_HMN5tEdWgAAeWvo.jpg',
                    'https://cms-assets.youmind.com/media/1783063701924_o3gv3r_HMN5tEaXAAANZgZ.jpg',
                ],
                'prompt' => <<<'PROMPT'
Ultra-realistic luxury skincare commercial. A {argument name="model description" default="beautiful female model"} with naturally glowing, healthy skin holds an {argument name="product" default="elegant premium skincare cream jar"} beside her face while looking confidently at the camera. Minimal {argument name="studio color" default="white and beige"} luxury studio with soft daylight, clean aesthetic, subtle marble textures, fresh green botanical accents, smooth skin texture without over-retouching, premium beauty photography, cinematic lighting, soft shadows, shallow depth of field, luxury cosmetic branding, editorial beauty campaign, photorealistic, ultra-detailed, 8K, high-end commercial quality.

Ultra-realistic luxury skincare campaign featuring the same female model in the same elegant white outfit and the same premium skincare cream jar inside the same minimalist white and beige studio. The model gently applies the cream to her cheek with a soft smile while looking naturally toward the camera. Matching daylight, identical color grading, marble countertop, botanical accents, glowing healthy skin, premium beauty photography, cinematic soft lighting, shallow depth of field, editorial cosmetic advertisement, photorealistic, ultra-detailed, 8K. Maintain perfect visual consistency with the previous image, including the same model, outfit, hairstyle, makeup, product, environment, lighting, and luxury aesthetic.
PROMPT,
            ],
            [
                'slug' => 'e-commerce-matcha-spa-bottle',
                'title' => 'Matcha Spa Product Shot',
                'category' => 'e-commerce',
                'aspect_ratio' => '1:1',
                'image_urls' => [
                    'https://cms-assets.youmind.com/media/1782028899182_v0b03t_HLP-DxuakAA2AOk.jpg',
                ],
                'prompt' => <<<'PROMPT'
{argument name="product" default="Premium green bottle"} placed on stone podium, surrounded by {argument name="surroundings" default="matcha powder clouds, green tea leaves and creamy foam"}, Japanese spa aesthetic, {argument name="composition" default="minimal luxury composition"}, soft natural lighting, highly realistic skincare advertisement, 8K.
PROMPT,
            ],

            // ─── social-media · 9:16 ───────────────────────────────────────
            [
                'slug' => 'social-media-airport-giantess',
                'title' => 'Airport Giantess Portrait',
                'category' => 'social-media',
                'aspect_ratio' => '9:16',
                'is_featured' => true,
                'image_urls' => [
                    'https://cms-assets.youmind.com/media/1784012480180_9ihyd3_HNENhy-aoAAJzP7.jpg',
                ],
                'prompt' => <<<'PROMPT'
Ultra-realistic IMAX-level Netflix-style cinematic surreal airport giantess portrait, 4:5 vertical composition, use the uploaded image as the primary facial reference with maximum face consistency and exact facial identity preservation, create a beautiful {argument name="subject description" default="young adult woman"} appearing as a giant figure lying playfully across a massive airport runway and terminal apron at golden hour, captured in a dramatic wide cinematic perspective that emphasizes her enormous scale compared to the real airplanes, airport vehicles and terminal buildings around her, her body stretched comfortably across the tarmac in a relaxed pose, one arm folded beneath her and the other hand holding a small airplane model near the camera, her facial expression warm, cheerful and softly playful with bright eyes, relaxed brows and a gentle smiling face, her luminous fair porcelain milky-white skin rendered with an ultra-smooth clean texture, soft natural pink freshness and perfect even face-to-body skin tone consistency, her long dark-brown hair flowing naturally around her shoulders with soft smooth volume, wearing a stylish {argument name="clothing type" default="check-pattern shirt"} in a {argument name="outfit colors" default="red, soft blue and white"} color combination, the checks looking attractive, balanced and clearly visible, paired with fitted grey cargo-style pants and casual sneakers, background featuring a realistic international airport environment with parked commercial aircraft, taxiways, gates, service vehicles, runway markings, terminal structures, a control tower and a distant city skyline, lighting warm and cinematic with beautiful sunset glow falling across her face, hair, clothes and the airport surface, creating soft highlights, long shadows and a premium dreamy atmosphere, emphasize the surreal scale contrast between the girl and the airplanes while keeping the scene photorealistic and visually believable, ultra-rich color grading with warm amber sunset tones, cool airport greys, soft blue accents and premium cinematic depth, HDR lighting, global illumination, shallow depth of field, photorealistic detailing, masterpiece quality, 8K production detail.
PROMPT,
            ],
            [
                'slug' => 'social-media-flower-blossom-portrait',
                'title' => 'Flower Blossom Portrait',
                'category' => 'social-media',
                'aspect_ratio' => '9:16',
                'image_urls' => [
                    'https://youmind.com/cdn-cgi/image/width=828,quality=90,format=auto,metadata=none/https%3A%2F%2Fcms-assets.youmind.com%2Fmedia%2F1783753508780_psl237_HM1uFjBbkAAQ-y_.jpg',
                    'https://youmind.com/cdn-cgi/image/width=828,quality=90,format=auto,metadata=none/https%3A%2F%2Fcms-assets.youmind.com%2Fmedia%2F1783753508673_gkojmn_HM1uFjHbUAApsVq.jpg',
                    'https://youmind.com/cdn-cgi/image/width=828,quality=90,format=auto,metadata=none/https%3A%2F%2Fcms-assets.youmind.com%2Fmedia%2F1783753509052_cz4xbh_HM1uFi5b0AA3x_Y.jpg',
                    'https://youmind.com/cdn-cgi/image/width=828,quality=90,format=auto,metadata=none/https%3A%2F%2Fcms-assets.youmind.com%2Fmedia%2F1783753509634_n46yac_HM1uFi8boAAwjc6.jpg',
                ],
                'prompt' => <<<'PROMPT'
"Poetic cinematic close-up portrait of a {argument name="subject" default="young woman"} partially obscured by {argument name="flower color" default="soft pink"} blossoms and petals in the foreground.",
 "subject_details": {
 "appearance": "Young woman, expressive green eyes, calm introspective expression, natural beauty",
 "skin_texture": "Ultra-realistic skin texture",
 "apparel": "Shimmering {argument name="dress color" default="rose-gold"} high-neck dress that catches warm sunlight"
 },"composition_and_framing": {
 "framing": "Close-up, face in sharp focus through gaps in the flowers",
 "foreground": "Soft pink blossoms and petals, soft blur",
 "background": "Deep blue sky, creamy bokeh",
 "depth": "Shallow depth of field"
 },
 "lighting_and_color": {
 "lighting_source": "Golden-hour lighting",
 "effects": "Delicate shadows of petals across skin, dress catching sunlight","palette": "Pastel pinks, warm peach tones, rose-gold, deep blue"
 },
 "atmosphere_and_style": {
 "mood": "Romantic spring atmosphere, ethereal, poetic",
 "style": "Editorial fashion photography",
 "grading": "Cinematic color grading"
 },
 "technical_specs": {
 "quality": "8K resolution, high detail",
 "visuals": "Ultra-realistic"
 }
PROMPT,
            ],
            [
                'slug' => 'social-media-paparazzi-flash',
                'title' => 'Paparazzi Flash Close-Up',
                'category' => 'social-media',
                'aspect_ratio' => '9:16',
                'image_urls' => [
                    'https://youmind.com/cdn-cgi/image/width=828,quality=90,format=webp,metadata=none/https%3A%2F%2Fcms-assets.youmind.com%2Fmedia%2F1783582318308_ss9k03_HMsnLzpaQAA4AIC.png',
                    'https://youmind.com/cdn-cgi/image/width=828,quality=90,format=webp,metadata=none/https%3A%2F%2Fcms-assets.youmind.com%2Fmedia%2F1783582318420_4scpim_HMsnL_HbsAAL9S4.png',
                    'https://youmind.com/cdn-cgi/image/width=828,quality=90,format=webp,metadata=none/https%3A%2F%2Fcms-assets.youmind.com%2Fmedia%2F1783582318367_f8f4co_HMsnMH2bsAAFJLH.png',
                ],
                'prompt' => <<<'PROMPT'
Paparazzi-style extreme close-up photo of a {argument name="subject" default="woman with striking facial features"}, caught off-guard while turning toward the camera. Face and shoulders only, shot from a low angle. Strong harsh on-camera flash, grainy high-ISO, raw candid street-photography feel. Background shows a {argument name="location" default="crowded scene with motion blur (Paris Fashion Week atmosphere)"}. Intense, spontaneous energy, imperfect and real. She is wearing a {argument name="clothing" default="school uniform"}. Ultra-realistic, cinematic realism, high detail skin texture, slight lens distortion.

Camera style: “35mm paparazzi lens, f/2.8, flash blown highlights”
Look: “2000s tabloid photo aesthetic”
Quality: “sharp focus on face, background heavily blurred and streaked”
PROMPT,
            ],
            [
                'slug' => 'social-media-real-cartoon-twin',
                'title' => 'Real & Cartoon Twin',
                'category' => 'social-media',
                'aspect_ratio' => '9:16',
                'image_urls' => [
                    'https://cms-assets.youmind.com/media/1783494604676_amqngf_HMo_dkkaIAIfu3O.jpg',
                    'https://cms-assets.youmind.com/media/1783494604702_wfjvop_HMo_eHjaYAAOSsz.jpg',
                ],
                'prompt' => <<<'PROMPT'
Transform the uploaded image into an ultra-high-resolution mixed-reality street portrait. A {argument name="subject" default="young woman"} (same face as the uploaded image) sits on {argument name="setting" default="urban steps"} beside her cartoon illustrated twin. The setting is outdoors against a textured concrete wall with a tall window reflecting trees and soft indoor light. Worn steps with chipped paint add realism.
The real woman wears relaxed {argument name="style" default="Y2K-inspired streetwear"}:an oversized cropped hoodie, baggy dark jeans, chunky sneakers, and a beanie. She sits casually with one knee raised, looking slightly away with a calm, confident expression.
Her illustrated counterpart mirrors her pose, outfit, and proportions exactly, drawn in a bold flat graphic style with thick black outlines and vibrant colors.The cartoon arm aligns with the real arm, creating a playful interaction. Natural daylight lights both figures evenly, blending realistic textures with clean cartoon shading for a cool, nostalgic, creative mixed-media look. 
PROMPT,
            ],
            [
                'slug' => 'social-media-chibi-webtoon-grid',
                'title' => 'Chibi Webtoon Grid',
                'category' => 'social-media',
                'aspect_ratio' => '9:16',
                'image_urls' => [
                    'https://youmind.com/cdn-cgi/image/width=828,quality=90,format=auto,metadata=none/https%3A%2F%2Fcms-assets.youmind.com%2Fmedia%2F1782977580590_oxo9mi_HMJEguTb0AA99uW.jpg',
                ],
                'prompt' => <<<'PROMPT'
A clean 4-panel grid art collage featuring four distinct, {argument name="subject" default="stylish chibi girls"} in a {argument name="aesthetic" default="Korean webtoon aesthetic"}. The illustration uses a minimalist flat vector design with bold, crisp black outlines and a pop-art comic vibe. Each panel features a unique solid color backdrop, including vibrant teal, bright sun yellow, and deep royal blue. The characters have large glossy anime-style dark eyes, softly blushed pink cheeks, and trendy hairstyles such as neat side-braids and elegant low buns. They wear fashionable {argument name="clothing" default="high-contrast striped turtlenecks and sleek black sweaters"}, accessorized with oversized colorful hoop earrings. Perfect symmetry, high resolution, graphic art style. --ar 3:4
PROMPT,
            ],
            [
                'slug' => 'social-media-ethereal-ballgown-princess',
                'title' => 'Ethereal Ballgown Princess',
                'category' => 'social-media',
                'aspect_ratio' => '9:16',
                'image_urls' => [
                    'https://cms-assets.youmind.com/media/1782721358154_flmidz_HL7dUzrXYAAPegk.jpg',
                ],
                'prompt' => <<<'PROMPT'
A highly detailed, cinematic 3D digital portrait of an {argument name="subject" default="ethereal blonde princess"}, featuring delicate doll-like features, large luminous blue eyes, and soft porcelain skin, in a medium close-up shot. She is adorned in an elaborate, textured {argument name="outfit" default="bright yellow ballgown embellished with intricate white lace trim"}, sheer tiered puffed sleeves, and detailed 3D floral appliqués on the bodice. Her voluminous wavy blonde hair is styled elegantly and intensely kissed by golden hour backlighting, creating a glowing rim light effect, accented by pastel roses and a faint blue butterfly ornament. The composition is vertical, set in a {argument name="background" default="lush, magical garden"} characterized by deep green foliage and abundant bright yellow background bokeh (shallow depth of field). Strong volumetric light bathes the scene, highlighting the detailed textures of the fabric and creating a warm, fantasy atmosphere. Masterpiece quality, ultra photorealistic rendering, 8K, inspired by high-end CGI animation and fairy tale aesthetics.
PROMPT,
            ],
        ];
    }
}
