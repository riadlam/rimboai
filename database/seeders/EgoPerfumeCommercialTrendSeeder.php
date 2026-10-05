<?php

namespace Database\Seeders;

use App\Models\TrendTemplate;
use App\Services\CatalogCache;
use Illuminate\Database\Seeder;

/**
 * Giant perfume highway spectacle from Lab creation #96 (MiniMax H3 R2V).
 * Client uploads their perfume bottle and edits the written prompt.
 */
class EgoPerfumeCommercialTrendSeeder extends Seeder
{
    private const EXAMPLE_VIDEO = 'https://v3b.fal.media/files/b/0aad29f1/YE6ZXjGADP8mPJGAy6ilP_minimax-h3.mp4';

    private const MOTION_REF = 'https://v3b.fal.media/files/b/0aad29f0/2TaBk9N-C2eNhuyhdzvAB_a94520f0-0c53-49ee-be7a-6610bbf589a6.mp4';

    public function run(): void
    {
        $prompt = <<<'PROMPT'
Create a cinematic 15-second vertical 9:16 product commercial using @Image1 as the EXACT visual product reference, and @Video1 as the EXACT reference for the overall cinematic style, pacing, camera movements, scene transitions, composition, scale and spectacle.

The goal is to recreate the same type of commercial shown in @Video1, but replace the original perfume product completely with the uploaded perfume bottle in @Image1.

PRODUCT: [CHANGE THIS — your perfume brand / product name]. @Image1 is the single authoritative product reference.

Preserve the exact bottle shape, proportions, glass/material, cap, liquid color, typography, logos, label placement and premium finish from @Image1.

Do NOT redesign the bottle. Do NOT create a different perfume bottle. Do NOT change the brand typography. Do NOT change the liquid color. Do NOT add another logo to the bottle. Do NOT add extra bottles. Do NOT distort, melt, stretch or morph the product.

The bottle must remain recognizable and visually consistent throughout the entire video.

VEHICLE REQUIREMENT — IMPORTANT:

The car in the opening driving sequence MUST be a realistic Fiat 500.

The Fiat 500 must have an authentic, recognizable Fiat 500 interior, including the correct compact dashboard, steering wheel and interior proportions.

The Fiat logo must be clearly and naturally visible in the center of the steering wheel.

Do NOT use a generic car. Do NOT use another vehicle. Do NOT change the Fiat 500 into another car.

Do NOT place the Fiat logo on the perfume bottle. Fiat branding must appear ONLY on the vehicle.

STYLE:

Cinematic realism transitioning into surreal spectacle.

Begin with gritty, realistic Fiat 500 driving footage and dusty road debris, then transition into polished high-gloss reflections on the perfume glass bottle from @Image1.

Bright washed-out midday sunlight with a premium cinematic commercial look.

Color palette:
pale beige desert tones,
gray asphalt,
bright white and silver vehicle tones,
clear glass / packaging materials from @Image1,
the exact liquid color from @Image1,
exact brand lettering from @Image1.

The perfume bottle should create a strong visual contrast against the pale desert environment.

Atmosphere transitions from ordinary daily commuting to an unexpected massive product spectacle, ending with clean luxury perfume branding.

CINEMATOGRAPHY:

Start with an immersive fixed first-person POV from inside a moving Fiat 500.

The driver's hands grip the steering wheel naturally.

The Fiat logo is visible on the center of the steering wheel.

The dashboard, windshield and rearview mirror are visible.

Use realistic vehicle vibration, subtle handheld movement and natural windshield perspective.

Transition into a wide symmetrical aerial drone shot showing the enormous scale of the product.

Gradually move from broad environmental shots into detailed close-ups of the @Image1 bottle.

Use realistic cinematic lenses, natural depth of field, realistic sunlight and high-end commercial product photography.

SCENE 1 — 00:00–00:05

FIRST-PERSON FIAT 500 HIGHWAY REVEAL

Open from a first-person POV inside a moving Fiat 500 traveling on a wide modern multi-lane highway.

The Fiat 500 interior must be realistic and recognizable.

The driver's hands grip the steering wheel.

The authentic Fiat logo is naturally visible in the center of the steering wheel.

Bright hazy midday sunlight fills the scene.

Suddenly, an enormous perfume bottle matching @Image1 exactly begins emerging vertically from the asphalt directly ahead of the Fiat 500.

The gigantic bottle pushes through the road surface with tremendous physical force.

As it rises, realistic asphalt cracks, chunks of pavement, dust and debris explode outward around its base.

The enormous bottle towers over the highway.

Its materials catch the sunlight exactly like @Image1.

The liquid color and brand lettering from @Image1 are clearly recognizable.

The cap catches strong sunlight and realistic reflections.

The camera remains mostly fixed from the driver's perspective with subtle realistic vehicle vibration.

The driver reacts by turning the steering wheel.

The camera then swings toward the passenger-side window, revealing the gigantic bottle in profile against the bright city/desert skyline.

The product must remain physically realistic despite its enormous scale.

SOUND:

Engine hum, tire noise and highway ambience.

Suddenly introduce a deep low-frequency rumble as the bottle breaks through the asphalt.

Add realistic cracking pavement, crashing debris, dust impact and powerful air movement.

No dialogue.

SCENE 2 — 00:05–00:10

GIANT BOTTLE — AERIAL REVEAL

Hard cut to a high-angle cinematic aerial drone shot looking down over the enormous highway.

The gigantic @Image1 perfume bottle stands vertically in the center of the road, surrounded by cracked asphalt, broken pavement and clouds of dust.

Traffic has stopped around the enormous bottle.

The Fiat 500 from the opening sequence can remain visible among the stopped traffic for visual continuity.

Small human figures can be visible at a safe distance around the scene, reacting with surprise.

The camera slowly drifts forward and slightly downward while maintaining a strong symmetrical composition.

The bottle remains perfectly centered on the road axis.

At approximately 00:09–00:10, create a powerful full-frame white flash transition.

SOUND:

Energetic modern electronic/pop music begins after the giant bottle reveal.

Strong rhythmic beat.

Build energy toward the white flash.

SCENE 3 — 00:10–00:15

HERO REVEAL

After the white flash, return to the aerial environment.

The gigantic @Image1 perfume bottle remains standing in the center of the highway.

Dust slowly settles around its base.

The camera smoothly glides forward and downward toward the bottle.

Gradually tighten the framing into a premium cinematic close-up focusing on the glass/material, liquid, cap and brand lettering from @Image1.

Use a smooth cinematic push-in with a shallow depth of field.

End with a clean luxury title card:

“[CHANGE THIS — BRAND NAME]”
“[CHANGE THIS — PARFUM / EAU DE PARFUM / product line]”

Minimal, sophisticated luxury perfume aesthetic.

No unnecessary graphics. No additional logos. No invented claims.

The Fiat logo must NOT appear on the final perfume title card.

SOUND:

The energetic electronic music continues during the final aerial push-in.

As the final title card appears, the music stops abruptly.

Leave a short elegant silence or subtle soft audio tail.

VISUAL QUALITY:

Ultra-realistic cinematic commercial. Photorealistic Fiat 500. Authentic Fiat 500 interior. Photorealistic product materials matching @Image1. Realistic sunlight, asphalt destruction, dust and debris. High-end luxury perfume advertising. HDR cinematic image quality. Sharp product details.

PRODUCT CONSISTENCY:

The exact uploaded bottle from @Image1 must be maintained throughout every shot.

The bottle cannot change shape between scenes.

The cap cannot change.

The liquid cannot change color.

The brand lettering cannot change.

Do not generate alternative packaging.

Do not replace the bottle with a generic perfume bottle.

VEHICLE CONSISTENCY:

The opening vehicle MUST be a Fiat 500.

Maintain the same Fiat 500 interior throughout Scene 1.

Do not morph the Fiat 500 into another car.

Do not place Fiat branding on the perfume bottle.

NEGATIVE:

Wrong bottle, generic perfume bottle, redesigned packaging, changed bottle shape, changed cap, changed label, wrong typography, misspelled brand, distorted letters, warped glass, melted glass, duplicated bottle, multiple giant bottles, cartoon, low-quality CGI, fake reflections, generic car interior, wrong car, non-Fiat vehicle, distorted Fiat logo, people close to the bottle, hands holding the bottle, extra logos on the perfume bottle, watermark, static slideshow.

OUTPUT:

One continuous professional 15-second vertical 9:16 cinematic perfume commercial.

Use @Video1 as the primary reference for scene progression, camera movement, timing, spectacle and overall cinematic advertising language, while replacing the original product with the EXACT @Image1 perfume bottle.

The opening driving vehicle MUST specifically be a realistic Fiat 500, with the authentic Fiat logo naturally visible on the steering wheel.
PROMPT;

        TrendTemplate::query()->updateOrCreate(
            ['slug' => 'giant-perfume-highway'],
            [
                'title' => 'Giant Perfume Highway',
                'description' => 'Upload your perfume bottle, edit the prompt with your brand name, and remake this giant-product highway spectacle with MiniMax H3.',
                'cover_url' => self::EXAMPLE_VIDEO,
                'is_published' => true,
                'is_featured' => true,
                'sort_order' => 4,
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
                'slots' => [
                    [
                        'key' => 'product',
                        'kind' => 'image',
                        'label' => 'Your perfume photo',
                        'role' => 'product',
                        'hint' => 'Clear packshot of your perfume bottle. Becomes @Image1 — edit the prompt with your brand name & colors.',
                        'accept' => 'image/*',
                        'required' => true,
                    ],
                ],
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

        $this->command?->info('Seeded trend: giant-perfume-highway (MiniMax H3 direct, prompt editable).');
    }
}
