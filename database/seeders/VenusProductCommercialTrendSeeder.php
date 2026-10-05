<?php

namespace Database\Seeders;

use App\Models\TrendTemplate;
use App\Services\CatalogCache;
use Illuminate\Database\Seeder;

/**
 * Product commercial trend from Lab creation #94 (MiniMax H3 R2V).
 * Client uploads their shampoo/packshot and edits the written prompt.
 */
class VenusProductCommercialTrendSeeder extends Seeder
{
    private const EXAMPLE_VIDEO = 'https://v3b.fal.media/files/b/0aad28fb/cQaEN8WM4ZjLfSJUz_OT3_minimax-h3.mp4';

    private const MOTION_REF = 'https://v3b.fal.media/files/b/0aad28fa/3QTaXymbnDLSuMf2lPwaY_aafa4c25-505d-4f63-b92d-0f9c31cda829.mp4';

    public function run(): void
    {
        $prompt = <<<'PROMPT'
Create a premium 9-second vertical 9:16 commercial using @Image1 as the EXACT visual product reference, and @Video1 as the EXACT reference for the overall visual style, pacing, camera movement, transitions, liquid effects, composition and commercial feel.

PRODUCT: [CHANGE THIS — your product name]. @Image1 is the single authoritative reference for the bottle / pack. Preserve the exact bottle shape, proportions, colors, cap, label artwork, logos, typography (all languages on the label), label placement and packaging details from @Image1.

Do not redesign, reinterpret, modernize, simplify or replace the packaging. The product must remain recognizable as the exact uploaded bottle throughout the entire video.

CONCEPT: “[CHANGE THIS — short campaign line, e.g. 2 EN 1 — CLEAN + SOFT]”

A luxurious hair-care commercial built around crystal water, shampoo texture, silky conditioner texture and refreshing liquid that matches @Image1 packaging colors, ending with a dramatic premium product hero shot.

01 | 0–1s — HOOK

Extreme macro shot of a crystal-clear water droplet flying rapidly toward the camera. The droplet splashes across the lens and reveals the @Image1 product bottle behind it. Fast cinematic push-in, realistic water physics, dramatic lighting matched to the pack colors, premium beauty-commercial photography.

On-screen text: “[CHANGE THIS — short hook text]”

02 | 1–2.5s — SHAMPOO TEXTURE

A glossy stream of pearlescent shampoo flows elegantly through the frame. The liquid moves smoothly around the bottle, catching highlights and creating luxurious reflections. Macro cinematography, shallow depth of field, realistic liquid physics, smooth tracking movement and focus pull. The bottle remains visually accurate to @Image1.

On-screen text: “[CHANGE THIS — benefit line 1]”

03 | 2.5–4s — SILKY TEXTURE

The glossy shampoo texture transforms into a soft, silky cream-like conditioner texture. The texture flows in elegant ribbons across a clean glossy surface. Subtle bubbles and water droplets create a fresh hair-care feeling. Premium macro photography, slow-motion liquid movement, realistic reflections, cinematic focus pull.

On-screen text: “[CHANGE THIS — benefit line 2]”

04 | 4–6s — WATER BURST

The exact @Image1 bottle dramatically rises upward through a spectacular crystal-water explosion. Water droplets are suspended around the bottle in slow motion. Luminous lighting creates a fresh, clean and luxurious atmosphere using the pack’s color language. Camera performs a fast cinematic push-in followed by a smooth 120° orbit around the product. The bottle remains perfectly intact and unchanged.

On-screen text: “[CHANGE THIS — benefit line 3]”

05 | 6–8s — HERO SHOT

Water settles. The exact @Image1 bottle stands centered on a wet glossy reflective surface. Small realistic water droplets and condensation are visible on the bottle. Subtle silky liquid movement surrounds the base. Soft light sweeps across the packaging, revealing logos and original label details. Smooth 180° cinematic orbit combined with a subtle push-in.

On-screen text: “[CHANGE THIS — closing benefit]”

06 | 8–9s — FINAL LOCKUP

The @Image1 bottle faces the camera perfectly, centered and dominant in frame. A final water droplet falls beside the bottle and creates a small realistic ripple on the reflective surface. Clean premium background matching pack colors. Very subtle camera pull-out.

Final text:
“[CHANGE THIS — BRAND / PRODUCT NAME]”
“[CHANGE THIS — product subtitle]”

CAMERA / MOTION:

Follow @Video1 for pacing, transitions, liquid choreography and commercial feel. Continuous cinematic motion throughout. Use extreme macro push-ins, smooth tracking, focus pulls, slow-motion water droplets, product reveal, 120° product orbit, 180° hero orbit and subtle final pull-out. No static slideshow shots.

VISUAL STYLE:

Premium international FMCG hair-care commercial. Fresh, clean, luxurious, glossy and refreshing. Crystal water, pearlescent shampoo texture, silky conditioner texture, glossy reflections, realistic condensation, cinematic macro photography, shallow depth of field, HDR, realistic materials, physically accurate liquid simulation, premium television commercial quality.

Use the color language of @Image1 packaging — do not invent a different brand palette.

PRODUCT LOCK — EXTREMELY IMPORTANT:

@Image1 is the exact product reference.
Preserve exact silhouette, proportions, colors, cap, label, logos and typography from @Image1.
Do not generate a new bottle. Do not redesign the label. Do not change the logo. Do not invent ingredients or claims.

REALISM:

Ultra-realistic commercial photography. Photorealistic product rendering. Realistic plastic reflections and highlights. Realistic water droplets and liquid physics. Sharp readable product packaging during hero shots. No excessive CGI appearance.

NEGATIVE:

People, hands, faces, hair models, bathroom scenes, extra products, duplicate bottles, different bottle designs, redesigned packaging, changed label, incorrect logo, incorrect typography, misspelled text, distorted bottle, warped cap, melted packaging, floating bottle without physical support, unrealistic liquid physics, excessive foam, cartoon style, low resolution, blurry product, watermark, static slideshow.

OUTPUT: One continuous professional ~9–15 second vertical 9:16 commercial. Use @Video1 for visual language and motion style while replacing its original product with the exact @Image1 packshot.
PROMPT;

        TrendTemplate::query()->updateOrCreate(
            ['slug' => '2-in-1-shampoo-commercial'],
            [
                'title' => '2-in-1 Shampoo Commercial',
                'description' => 'Upload your shampoo / hair-care packshot, edit the prompt with your brand name & on-screen text, and remake this liquid product ad with MiniMax H3.',
                'cover_url' => self::EXAMPLE_VIDEO,
                'is_published' => true,
                'is_featured' => true,
                'sort_order' => 5,
                'endpoint_id' => 'minimax/h3/reference-to-video',
                'workflow' => TrendTemplate::WORKFLOW_H3_DIRECT,
                'model_name' => 'MiniMax H3',
                'prompt' => $prompt,
                'prompt_editable' => true,
                'aspect_ratio' => '9:16',
                'resolution' => '768p',
                'duration' => '15',
                'generate_audio' => true,
                'fal_estimate_usd' => 0.90,
                'trend_cost' => 120,
                'slots' => TrendTemplate::productSlots(),
                'locked_assets' => [
                    [
                        'role' => 'motion_sketch',
                        'kind' => 'video',
                        'url' => self::MOTION_REF,
                        'label' => 'Style & motion reference',
                    ],
                ],
                'sheet_endpoint_id' => TrendTemplate::DEFAULT_SHEET_ENDPOINT,
                'sheet_prompt' => null,
            ],
        );

        CatalogCache::forgetBrands();
        TrendTemplate::bustFeedCache();

        $this->command?->info('Seeded trend: 2-in-1-shampoo-commercial (MiniMax H3 direct, prompt editable).');
    }
}
