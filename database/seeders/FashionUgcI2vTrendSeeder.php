<?php

namespace Database\Seeders;

use App\Models\TrendTemplate;
use App\Services\CatalogCache;
use Illuminate\Database\Seeder;

/**
 * Fashion UGC I2V remakes from Lab creations #104 and #103 (MiniMax H3 image-to-video).
 * Users upload one start photo and can edit the full camera-sequence prompt.
 */
class FashionUgcI2vTrendSeeder extends Seeder
{
    public function run(): void
    {
        $prompt = <<<'PROMPT'
Create a high-end, ultra-realistic fashion UGC video starting EXACTLY from @Image1 as the first frame.

FIRST FRAME — ABSOLUTE REFERENCE

@Image1 must be preserved as the exact starting frame.

Do not change:
- the woman's face or identity
- facial features
- hairstyle
- makeup
- skin tone
- body proportions
- outfit
- garment colors, embroidery or details
- environment
- background
- furniture
- architecture
- lighting
- color palette
- overall atmosphere

The video should feel like a continuous real camera shoot that begins from this exact photograph (@Image1).

FASHION CAMERA SEQUENCE

Create a dynamic, elegant sequence of camera movements and poses designed specifically to showcase the outfit.

SHOT 1 — ESTABLISHING
Start exactly from @Image1.
Hold the initial composition briefly while the woman naturally smiles toward the camera.

SHOT 2 — FULL OUTFIT
Slowly pull the camera backward to reveal the complete outfit.
The woman makes a subtle natural posture adjustment while keeping the same environment.

SHOT 3 — THREE-QUARTER ANGLE
Move the camera smoothly around her approximately 30–45 degrees.
She gently turns with the camera so the silhouette and garment structure become visible.

SHOT 4 — OUTFIT DETAIL
Move closer toward the garment.
Show the embroidery, fabric texture, neckline, sleeves and important design details.
Use a smooth cinematic push-in rather than a sudden zoom.

SHOT 5 — SIDE ANGLE
Move smoothly toward a side/three-quarter view.
The woman makes a subtle elegant turn to reveal how the garment falls around her body.

SHOT 6 — FACE PORTRAIT
Transition into a beautiful medium close-up of her face.
She looks naturally toward the camera and gives a warm, confident smile.
Preserve her exact facial identity and makeup from @Image1.

SHOT 7 — MOVING PORTRAIT
Make a subtle camera movement around her face and upper body.
Keep the expression natural and elegant.
Allow her hair and clothing to move naturally.

SHOT 8 — FINAL HERO SHOT
Pull back into a flattering three-quarter/full-body composition.
She gives one final elegant pose and confident smile.
End with a strong fashion-editorial composition showcasing the complete outfit.

CAMERA STYLE

Use sophisticated fashion cinematography:
- smooth dolly movements
- slow push-ins
- controlled lateral movements
- gentle orbiting
- subtle changes in camera distance
- natural handheld micro-movement where appropriate
- cinematic depth of field

Every camera movement should have a purpose: reveal the outfit, silhouette, craftsmanship, or the woman's expression.

PERFORMANCE

The woman should move naturally and confidently like a real fashion UGC creator.

Use subtle movements:
- small body turns
- gentle posture changes
- slight hand movements
- natural smile
- brief eye contact with camera
- subtle garment interaction

Avoid exaggerated dancing or unnatural posing.

CONSISTENCY — EXTREMELY IMPORTANT

The woman is the SAME PERSON throughout the entire video.
The outfit is the SAME OUTFIT throughout the entire video.
The environment is the SAME ENVIRONMENT throughout the entire video.

Do not change identity, face, hairstyle, makeup, body proportions, outfit, colors, embroidery, background or architecture.

No outfit transformation.
No scene transition.
No location change.
No new accessories.
No additional people.
No text.
No logos.
No watermark.

VISUAL QUALITY

Ultra-realistic luxury fashion UGC.
Natural skin texture.
Realistic hair.
Realistic fabric physics.
Natural folds and shadows.
Accurate anatomy.
Consistent lighting.
Premium fashion-commercial quality.
Vertical 9:16.

The final result should feel like one continuous professional fashion shoot filmed in the exact location shown in @Image1, with carefully planned camera angles designed to show both the outfit and the model's face.
PROMPT;

        $shared = [
            'category' => TrendTemplate::CATEGORY_UGC,
            'is_published' => true,
            'is_featured' => true,
            'endpoint_id' => 'minimax/h3/image-to-video',
            'workflow' => TrendTemplate::WORKFLOW_H3_I2V,
            'model_name' => 'MiniMax H3',
            'prompt' => $prompt,
            'prompt_editable' => true,
            'aspect_ratio' => '9:16',
            'resolution' => '768p',
            'generate_audio' => false,
            'slots' => TrendTemplate::firstFrameSlot(),
            'locked_assets' => [],
            'sheet_endpoint_id' => TrendTemplate::DEFAULT_SHEET_ENDPOINT,
            'sheet_prompt' => null,
        ];

        // Creation #104 — 10s, 75 credits (~$0.60 fal)
        TrendTemplate::query()->updateOrCreate(
            ['slug' => 'fashion-ugc-camera-showcase-10s'],
            array_merge($shared, [
                'title' => 'Fashion UGC Camera Showcase',
                'description' => 'Upload your look photo as the first frame, edit the camera prompt, and remake this 10s fashion UGC with MiniMax H3.',
                'cover_url' => 'https://v3b.fal.media/files/b/0aad4745/rDo2wzwXFt8mXlByo-wCU_minimax-h3.mp4',
                'sort_order' => 1,
                'duration' => '10',
                'fal_estimate_usd' => 0.60,
                'trend_cost' => 75,
            ]),
        );

        // Creation #103 — 8s, 60 credits (~$0.48 fal)
        TrendTemplate::query()->updateOrCreate(
            ['slug' => 'fashion-ugc-camera-showcase-8s'],
            array_merge($shared, [
                'title' => 'Fashion UGC Editorial Turn',
                'description' => 'Upload your look photo as the first frame, edit the camera prompt, and remake this 8s fashion UGC with MiniMax H3.',
                'cover_url' => 'https://v3b.fal.media/files/b/0aad4733/NCc_aGLNakQJgyU6QaiWa_minimax-h3.mp4',
                'sort_order' => 2,
                'duration' => '8',
                'fal_estimate_usd' => 0.48,
                'trend_cost' => 60,
            ]),
        );

        // Keep older R2V UGC below these two newest templates.
        TrendTemplate::query()
            ->where('slug', 'fashion-character-outfit-ugc')
            ->update(['sort_order' => 10]);

        CatalogCache::forgetBrands();
        TrendTemplate::bustFeedCache();

        $this->command?->info('Seeded UGC I2V trends: fashion-ugc-camera-showcase-10s (#104) + fashion-ugc-camera-showcase-8s (#103).');
    }
}
