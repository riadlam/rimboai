<?php

namespace Database\Seeders;

use App\Models\TrendTemplate;
use App\Services\CatalogCache;
use Illuminate\Database\Seeder;

/**
 * Fashion UGC remake from Lab creation #101 (MiniMax H3 R2V).
 * Client uploads character + outfit photos; motion/scene stay locked from the reference video.
 */
class FashionCharacterOutfitUgcTrendSeeder extends Seeder
{
    private const EXAMPLE_VIDEO = 'https://v3b.fal.media/files/b/0aad465b/kUH3_BjewoGg20ZjrIhV1_minimax-h3.mp4';

    private const MOTION_REF = 'https://v3b.fal.media/files/b/0aad465a/Gl9hkYn9lEEsMGKBq7mB4_132066d5-f3e9-48ea-b8e1-cc49b680490d.mp4';

    public function run(): void
    {
        $prompt = <<<'PROMPT'
Create an ultra-realistic fashion video using @Image1 as the character reference and @Image2 as the outfit reference.

REFERENCE VIDEO — ABSOLUTE MOTION & SCENE SOURCE

Use @Video1 as the exact source for:
- environment and location
- background
- architecture
- time of day
- lighting
- shadows
- camera position
- camera movement
- camera speed
- framing
- composition
- lens perspective
- depth of field
- subject movement
- walking
- posing
- gestures
- timing
- overall cinematic style

Recreate the scene and movement of @Video1 as closely as possible.

CHARACTER REPLACEMENT

Replace ONLY the person in @Video1 with the person from @Image1.

@Image1 is the absolute source of truth for:
- face
- identity
- facial features
- skin tone
- hairstyle
- body proportions
- age
- physical appearance

Do not use the face or body appearance of the person from @Video1.

OUTFIT REPLACEMENT

Dress the new character in the exact outfit from @Image2.

@Image2 is the absolute source of truth for:
- garment design
- colors
- fabric
- embroidery
- patterns
- sleeves
- neckline
- buttons
- decorations
- proportions
- fit

Preserve the garment exactly throughout the entire video.

MOTION

The new character must perform the EXACT same movements, poses, gestures and timing as the person in @Video1.

Preserve the natural body motion and interaction with the environment.

CAMERA & ENVIRONMENT

Do not change the camera movement, camera angle, framing, location, background, lighting, atmosphere or composition from @Video1.

The final result should look like @Video1 was filmed with the new character wearing the new outfit.

REALISM

Ultra-realistic fashion video.
Natural skin texture.
Realistic hair movement.
Realistic fabric physics.
Natural folds and shadows.
Accurate body anatomy.
Consistent face and identity throughout the video.
High-end professional fashion cinematography.
Vertical 9:16.
Exactly 12 seconds.

DO NOT:
- change the location
- change the background
- change the camera movement
- change the lighting
- change the framing
- change the pose sequence
- copy the original person's face
- copy the original person's body
- change the outfit
- redesign the garment
- add accessories
- distort the face
- distort hands or body
- create extra limbs or fingers
- create CGI or cartoon appearance
- add text, logos or watermarks

ONLY replace the person and outfit.
Everything else should remain faithful to @Video1.
PROMPT;

        TrendTemplate::query()->updateOrCreate(
            ['slug' => 'fashion-character-outfit-ugc'],
            [
                'title' => 'Fashion Character Outfit UGC',
                'category' => TrendTemplate::CATEGORY_UGC,
                'description' => 'Upload your character photo + outfit photo and remake this fashion walk UGC with MiniMax H3. Scene, camera, and motion stay locked.',
                'cover_url' => self::EXAMPLE_VIDEO,
                'is_published' => true,
                'is_featured' => true,
                'sort_order' => 1,
                'endpoint_id' => 'minimax/h3/reference-to-video',
                'workflow' => TrendTemplate::WORKFLOW_H3_DIRECT,
                'model_name' => 'MiniMax H3',
                'prompt' => $prompt,
                'prompt_editable' => true,
                'aspect_ratio' => '9:16',
                'resolution' => '768p',
                'duration' => '12',
                'generate_audio' => true,
                // Creation #101: ~$1.44 fal → 188 credits charged
                'fal_estimate_usd' => 1.50,
                'trend_cost' => 188,
                'slots' => TrendTemplate::characterAndOutfitSlots(),
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

        $this->command?->info('Seeded UGC trend: fashion-character-outfit-ugc (MiniMax H3 direct, character+outfit).');
    }
}
