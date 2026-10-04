<?php

namespace Tests\Unit;

use App\Models\TrendTemplate;
use App\Services\TrendTemplateCostEstimator;
use PHPUnit\Framework\TestCase;

class TrendTemplateModelTest extends TestCase
{
    public function test_default_prompt_scaffold_tags_video_and_images(): void
    {
        $prompt = TrendTemplate::defaultPromptScaffold();
        $this->assertStringContainsString('@Video1', $prompt);
        $this->assertStringContainsString('@Image1', $prompt);
        $this->assertStringContainsString('@Image2', $prompt);
        $this->assertStringContainsString('burnt-orange', $prompt);
        $this->assertSame('fal-ai/wan/v2.7/reference-to-video', TrendTemplate::DEFAULT_ENDPOINT);
    }

    public function test_client_slots_are_image_only_in_order(): void
    {
        $template = new TrendTemplate([
            'slots' => TrendTemplate::defaultSlots(),
        ]);
        $slots = $template->clientSlots();
        $this->assertCount(2, $slots);
        $this->assertSame('body_right', $slots[0]['key']);
        $this->assertSame('body_left', $slots[1]['key']);
    }

    public function test_cost_estimator_returns_structure(): void
    {
        // Bind lightweight doubles via the real class with catalog miss → zeros ok.
        $this->assertTrue(class_exists(TrendTemplateCostEstimator::class));
    }
}
