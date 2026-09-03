<?php

namespace Tests\Unit;

use App\Services\Credits\CreditCalculator;
use App\Services\Credits\FalEndpointPricingPolicy;
use App\Services\Credits\VideoGenerationCostEstimator;
use Tests\TestCase;

class FalEndpointPricingPolicyTest extends TestCase
{
    public function test_grok_720p_uses_gallery_rate_not_catalog_scalar(): void
    {
        $policy = new FalEndpointPricingPolicy;
        $quote = $policy->quoteVideo([
            'endpoint_id' => 'xai/grok-imagine-video/text-to-video',
            'unit' => 'seconds',
            'unit_price' => 0.05,
            'duration_seconds' => 5,
            'resolution' => '720p',
        ]);

        $this->assertNotNull($quote);
        $this->assertEqualsWithDelta(0.35, $quote['fal_cost_usd'], 1e-6);
        $this->assertSame(0.07, $quote['unit_price']);
    }

    public function test_pixverse_c1_720p_audio_is_official_rate(): void
    {
        $policy = new FalEndpointPricingPolicy;
        $quote = $policy->quoteVideo([
            'endpoint_id' => 'fal-ai/pixverse/c1/reference-to-video',
            'unit' => 'seconds',
            'unit_price' => 0.005,
            'duration_seconds' => 5,
            'resolution' => '720p',
            'audio' => true,
        ]);

        $this->assertNotNull($quote);
        $this->assertEqualsWithDelta(0.325, $quote['fal_cost_usd'], 1e-6);
    }

    public function test_veo_silent_720p_is_not_audio_rate(): void
    {
        $policy = new FalEndpointPricingPolicy;
        $quote = $policy->quoteVideo([
            'endpoint_id' => 'fal-ai/veo3.1',
            'unit' => 'seconds',
            'unit_price' => 0.40,
            'duration_seconds' => 8,
            'resolution' => '720p',
            'audio' => false,
        ]);

        $this->assertNotNull($quote);
        $this->assertEqualsWithDelta(1.60, $quote['fal_cost_usd'], 1e-6);
    }

    public function test_kling_v3_pro_voice_control(): void
    {
        $policy = new FalEndpointPricingPolicy;
        $quote = $policy->quoteVideo([
            'endpoint_id' => 'fal-ai/kling-video/v3/pro/image-to-video',
            'duration_seconds' => 5,
            'audio' => true,
            'voice_control' => true,
        ]);

        $this->assertNotNull($quote);
        $this->assertEqualsWithDelta(0.98, $quote['fal_cost_usd'], 1e-6);
    }

    public function test_seedance_includes_reference_video_seconds(): void
    {
        $policy = new FalEndpointPricingPolicy;
        $without = $policy->quoteVideo([
            'endpoint_id' => 'fal-ai/bytedance/seedance/v1/text-to-video',
            'unit' => 'tokens_per_1000',
            'unit_price' => 0.014,
            'duration_seconds' => 5,
            'resolution' => '720p',
            'aspect' => '16:9',
            'reference_video_seconds' => 0,
        ]);
        $with = $policy->quoteVideo([
            'endpoint_id' => 'fal-ai/bytedance/seedance/v1/reference-to-video',
            'unit' => 'tokens_per_1000',
            'unit_price' => 0.014,
            'duration_seconds' => 5,
            'resolution' => '720p',
            'aspect' => '16:9',
            'reference_video_seconds' => 5,
        ]);

        $this->assertNotNull($without);
        $this->assertNotNull($with);
        $this->assertGreaterThan($without['fal_cost_usd'], $with['fal_cost_usd']);
    }

    public function test_opaque_units_fail_closed(): void
    {
        $policy = new FalEndpointPricingPolicy;
        $this->assertNull($policy->quoteVideo([
            'endpoint_id' => 'unknown/mystery-model',
            'unit' => 'units',
            'unit_price' => 1.0,
            'duration_seconds' => 5,
        ]));
    }

    public function test_void_add_ons(): void
    {
        $policy = new FalEndpointPricingPolicy;
        $base = $policy->quoteVoid(['endpoint_id' => 'fal-ai/void-video-inpainting']);
        $all = $policy->quoteVoid([
            'endpoint_id' => 'fal-ai/void-video-inpainting',
            'pass2' => true,
            'sam_mask' => true,
        ]);

        $this->assertEqualsWithDelta(0.05, $base['fal_cost_usd'], 1e-6);
        $this->assertEqualsWithDelta(0.15, $all['fal_cost_usd'], 1e-6);
    }

    public function test_estimator_applies_markup_to_policy_quote(): void
    {
        config(['credits.markup' => 1.25, 'credits.usd_per_credit' => 0.01, 'credits.min_credits.video' => 0]);
        $estimator = new VideoGenerationCostEstimator(new CreditCalculator, new FalEndpointPricingPolicy);
        $cost = $estimator->estimate([
            'endpoint_id' => 'xai/grok-imagine-video/text-to-video',
            'unit' => 'seconds',
            'unit_price' => 0.05,
            'duration_seconds' => 5,
            'resolution' => '720p',
        ]);

        $this->assertEqualsWithDelta(0.35, $cost['fal_cost_usd'], 1e-6);
        $this->assertSame(44, $cost['credits']);
    }
}
