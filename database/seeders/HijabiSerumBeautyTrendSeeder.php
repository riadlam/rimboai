<?php

namespace Database\Seeders;

use App\Models\TrendTemplate;
use App\Services\CatalogCache;
use Illuminate\Database\Seeder;

/**
 * Hijabi beauty creator serum ad from Lab creation #100 (MiniMax H3 R2V).
 * Client uploads woman + product photos and can edit the prompt (Darija VO / brand text).
 */
class HijabiSerumBeautyTrendSeeder extends Seeder
{
    private const EXAMPLE_VIDEO = 'https://v3b.fal.media/files/b/0aad2e39/sEYkwOWgmB2rzN0ZTjJ_u_minimax-h3.mp4';

    private const MOTION_REF = 'https://v3b.fal.media/files/b/0aad2e38/wgqtHNTZ7Egv1b6zwz2On_3c661444-3fc7-418b-a937-10f5e268b9bb.mp4';

    public function run(): void
    {
        $prompt = <<<'PROMPT'
Create a photorealistic 15-second vertical 9:16 beauty advertisement.

IMPORTANT REFERENCE PRIORITY:
Use @Video1 as the MASTER reference for camera movement, framing, pacing, scene composition, lighting, gestures, actions, and overall visual style.

Use @Image1 as the EXACT character / woman reference.
Use @Image2 as the EXACT product reference.

Do not redesign, replace, reinterpret, or invent either reference.

CHARACTER:
@Image1 must remain the same woman throughout the entire video.

Preserve her exact:
- facial features and identity
- face shape
- skin tone
- eyes, eyebrows and lips
- makeup
- hijab color, fabric and style from @Image1
- clothing colors and style from @Image1
- earrings / accessories from @Image1
- overall appearance and proportions

Do not turn her into a different person.
Do not remove or change her hijab if she wears one in @Image1.
Do not generate loose hair if her hair is covered in @Image1.
Do not change her clothing or facial appearance away from @Image1.

PRODUCT: [CHANGE THIS — your product / brand name]
Use ONLY the uploaded product in @Image2.

@Image2 is the exact product reference.

Preserve the exact:
- bottle / pack shape and proportions
- materials, colors and finishes
- cap / dropper / pump
- branding, logos and label design
- visible product text
- realistic reflections

Do NOT create a generic bottle.
Do NOT change the packaging.
Do NOT invent another product or brand.
Do NOT add fake logos or random text.

SCENE:

0:00–0:04
The woman from @Image1 is in a bright, modern, elegant bathroom/beauty environment inspired by @Video1.

She holds the exact @Image2 product and smiles naturally at the camera.

She speaks naturally in Algerian Darija:

"[CHANGE THIS — opening Darija line, e.g. إلا شعرك يبانلك ناشف وتعبان في الصباح، جربي هاد السيروم… غير شوية منو يفرق بزاف.]"

Natural Algerian female voice, friendly and confident, with accurate lip sync.

On-screen text:
"[CHANGE THIS — hook text, e.g. روتيني للشعر ✨]"

0:04–0:08
She brings the exact @Image2 product closer to the camera.

She uses a small amount of the product appropriately around the scalp/hairline area while keeping her appearance consistent with @Image1.

Do not reveal loose hair if her hair is covered in @Image1.

Natural hand movement and realistic product interaction.

On-screen text:
"[CHANGE THIS — benefit text, e.g. نعومة وانتعاش ✨]"

0:08–0:12
Return to the woman from @Image1.

She smiles confidently and gently touches the area around her hairline / hijab edge.

She looks naturally at the camera and says in Algerian Darija:

"[CHANGE THIS — mid Darija line, e.g. شوفو كيفاش يبان شعري مرتب ولامع… حتى من بعد الحجاب.]"

On-screen text:
"[CHANGE THIS — mid text, e.g. لوك فريش ✨]"

0:12–0:15
Final premium product shot.

She holds the exact @Image2 product next to her face, clearly visible and facing the camera.

She smiles confidently and says:

"[CHANGE THIS — closing Darija line, e.g. هاد السيروم راه يبقى من روتيني الصباحي.]"

Final on-screen text:
"[CHANGE THIS — CTA, e.g. جربيه ✨]"

VOICE:
ALL spoken dialogue MUST be in natural Algerian Darija.

Do NOT speak English.
Do NOT use formal Modern Standard Arabic.
Use a natural young Algerian female voice, conversational and authentic, like an Algerian beauty creator talking to her audience.

Accurate lip synchronization.

VISUAL STYLE:
Photorealistic premium beauty commercial.
Natural realistic skin texture.
Realistic hands and fingers.
Realistic product reflections.
Natural facial expressions.
Soft cinematic lighting.
Warm natural daylight.
Shallow depth of field.
Smooth realistic camera movement.
High-end beauty advertisement quality.
Vertical 9:16.
Exactly 15 seconds.

CRITICAL CONSISTENCY:
@Video1 = camera, movement, pacing, cinematography and visual style.
@Image1 = exact woman and appearance.
@Image2 = exact product.

Never change the woman's identity.
Never remove a hijab if present in @Image1.
Never generate loose hair if hair is covered in @Image1.
Never replace the @Image2 product.
Never invent packaging, brands, products or logos.
Never add extra people.
Never speak English.
Never make the Arabic voice robotic.
Maintain realistic anatomy, hands, facial expressions and product interaction throughout the entire video.
PROMPT;

        TrendTemplate::query()->updateOrCreate(
            ['slug' => 'hijabi-serum-beauty-ad'],
            [
                'title' => 'Hijabi Serum Beauty Ad',
                'description' => 'Upload your woman photo + product packshot, edit the Darija prompt / on-screen text, and remake this beauty creator ad with MiniMax H3.',
                'cover_url' => self::EXAMPLE_VIDEO,
                'is_published' => true,
                'is_featured' => true,
                'sort_order' => 2,
                'endpoint_id' => 'minimax/h3/reference-to-video',
                'workflow' => TrendTemplate::WORKFLOW_H3_DIRECT,
                'model_name' => 'MiniMax H3',
                'prompt' => $prompt,
                'prompt_editable' => true,
                'aspect_ratio' => '9:16',
                'resolution' => '768p',
                'duration' => '15',
                'generate_audio' => true,
                // 15s out + ~15s motion ref @ $0.06/s (768P) = $1.80 fal → ×1.25 markup = 225 credits
                'fal_estimate_usd' => 1.80,
                'trend_cost' => 225,
                'slots' => TrendTemplate::womanAndProductSlots(),
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

        $this->command?->info('Seeded trend: hijabi-serum-beauty-ad (MiniMax H3 direct, woman+product, prompt editable).');
    }
}
