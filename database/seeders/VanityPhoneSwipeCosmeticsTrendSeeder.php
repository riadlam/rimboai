<?php

namespace Database\Seeders;

use App\Models\TrendTemplate;
use App\Services\CatalogCache;
use Illuminate\Database\Seeder;

/**
 * Vanity phone-swipe cosmetics ad from Lab creation #99 (MiniMax H3 R2V).
 * Clients upload 4 product photos; prompt stays locked/hidden.
 */
class VanityPhoneSwipeCosmeticsTrendSeeder extends Seeder
{
    private const EXAMPLE_VIDEO = 'https://v3b.fal.media/files/b/0aad2c13/rsqSZszpLwJClSgCNdUxa_minimax-h3.mp4';

    private const MOTION_REF = 'https://v3b.fal.media/files/b/0aad2c12/-h5xQekNx9E4vQcRh7hZo_f36b56ba-2a93-403e-8566-9eabab301e25.mp4';

    public function run(): void
    {
        $prompt = <<<'PROMPT'
Use @Video1 as the EXACT visual reference.

Recreate the same video: same vertical 9:16 framing, camera position, hand movements, phone movement, timing, vanity environment, lighting, colors, background and overall composition.

@Image1, @Image2, @Image3 and @Image4 are the EXACT cosmetic product references, in swipe order.
Show these products on the phone screen exactly as provided, one by one, in that order and in the same on-screen positions as @Video1.
Preserve their real packaging, colors, shapes, labels, logos and text.
Do NOT redesign, replace, invent or alter the products.

SCENE:
Two hands hold a smartphone with a pale pink case in front of a cozy vanity with a mirror, plants, cosmetics and warm golden bokeh lights.

The right hand has long almond-shaped nails with milky-white polish and naturally swipes through the products on the phone.

Keep the phone screen realistic and sharp while the background has soft depth of field.

AUDIO:
Replace the original Portuguese dialogue with natural spoken ARABIC.

Use a warm, playful female Arabic voice with natural conversational delivery.

Arabic dialogue:

"غابرييل كانت مكتئبة. ولما كانت تحس بالاكتئاب... ما كان في شيء يحسّن مزاجها أكثر من إنها تروح تتسوق ببطاقة الائتمان."

Speak the Arabic naturally and clearly, synchronized precisely with the actions and timing of @Video1.

Keep the background music playful, light and whimsical, with subtle phone swipe and tap sounds.

IMPORTANT:
@Video1 controls the visual timing and actions.
@Image1–@Image4 control the exact appearance of the products on the phone screen.
Do not invent products, packaging, labels, logos or text.
Do not change the vanity environment.
Do not create a different scene.
Do not add people beyond the hands already in @Video1.
Do not add subtitles or random text.
Do not hallucinate product details.

Photorealistic, clean high-definition beauty/lifestyle commercial, warm golden lighting, soft background bokeh, realistic hands and natural finger movement.
PROMPT;

        TrendTemplate::query()->updateOrCreate(
            ['slug' => 'vanity-phone-swipe-cosmetics'],
            [
                'title' => 'Vanity Phone Swipe',
                'description' => 'Upload 4 cosmetic product photos and remake this cozy vanity phone-swipe ad with MiniMax H3 — Arabic VO included.',
                'cover_url' => self::EXAMPLE_VIDEO,
                'is_published' => true,
                'is_featured' => true,
                'sort_order' => 3,
                'endpoint_id' => 'minimax/h3/reference-to-video',
                'workflow' => TrendTemplate::WORKFLOW_H3_DIRECT,
                'model_name' => 'MiniMax H3',
                'prompt' => $prompt,
                'prompt_editable' => false,
                'aspect_ratio' => '9:16',
                'resolution' => '768p',
                'duration' => '15',
                'generate_audio' => true,
                // 15s out + ~13s motion ref @ $0.06/s (768P) ≈ $1.68 fal → ×1.25 markup = 210 credits
                'fal_estimate_usd' => 1.68,
                'trend_cost' => 210,
                'slots' => TrendTemplate::productSlotsFour(),
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

        $this->command?->info('Seeded trend: vanity-phone-swipe-cosmetics (MiniMax H3 direct, 4 products, prompt locked).');
    }
}
