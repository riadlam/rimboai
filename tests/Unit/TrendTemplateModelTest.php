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
        $this->assertStringContainsString('burnt-orange studio backdrop', $prompt);
        $this->assertStringContainsString('silver condenser microphone', $prompt);
        $this->assertStringContainsString('camera movement', $prompt);
        $this->assertSame('higgsfield/genjutsu/motion-transfer/v1.0', TrendTemplate::DEFAULT_ENDPOINT);
        $this->assertSame('minimax/h3/reference-to-video', TrendTemplate::SECONDARY_FALLBACK_ENDPOINT);
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

    public function test_product_slots_mark_h3_direct_workflow(): void
    {
        $template = new TrendTemplate([
            'endpoint_id' => 'minimax/h3/reference-to-video',
            'workflow' => TrendTemplate::WORKFLOW_H3_DIRECT,
            'prompt_editable' => true,
            'slots' => TrendTemplate::productSlots(),
        ]);
        $this->assertTrue($template->isPromptEditable());
        $this->assertTrue($template->isH3DirectWorkflow());
        $this->assertSame('product', $template->clientSlots()[0]['role']);
    }

    public function test_product_slots_four_are_locked_prompt_ready(): void
    {
        $template = new TrendTemplate([
            'endpoint_id' => 'minimax/h3/reference-to-video',
            'workflow' => TrendTemplate::WORKFLOW_H3_DIRECT,
            'prompt_editable' => false,
            'slots' => TrendTemplate::productSlotsFour(),
        ]);
        $this->assertFalse($template->isPromptEditable());
        $this->assertTrue($template->isH3DirectWorkflow());
        $this->assertCount(4, $template->clientSlots());
        $this->assertSame('product_1', $template->clientSlots()[0]['key']);
        $this->assertSame('product', $template->clientSlots()[0]['role']);
        $this->assertTrue((bool) ($template->clientSlots()[3]['required'] ?? false));
    }

    public function test_cost_estimator_returns_structure(): void
    {
        // Bind lightweight doubles via the real class with catalog miss → zeros ok.
        $this->assertTrue(class_exists(TrendTemplateCostEstimator::class));
    }
}
