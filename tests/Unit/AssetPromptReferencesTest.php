<?php

namespace Tests\Unit;

use App\Services\AssetPromptReferences;
use PHPUnit\Framework\TestCase;

class AssetPromptReferencesTest extends TestCase
{
    public function test_it_resolves_valid_ordered_asset_aliases(): void
    {
        $resolved = (new AssetPromptReferences)->resolve(
            'Use @image2 for the subject, animate @video1, and follow @audio1.',
            ['image' => 2, 'video' => 1, 'audio' => 1],
        );

        $this->assertSame(
            'Use reference image 2 for the subject, animate reference video 1, and follow reference audio 1.',
            $resolved,
        );
    }

    public function test_it_does_not_remap_an_alias_that_has_no_asset(): void
    {
        $resolved = (new AssetPromptReferences)->resolve(
            'Use @image2 and @image1.',
            ['image' => 1],
        );

        $this->assertSame('Use @image2 and reference image 1.', $resolved);
    }
}
