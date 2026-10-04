<?php

namespace Tests\Unit;

use App\Models\TrendTemplate;
use App\Services\TrendsFeedService;
use ReflectionMethod;
use Tests\TestCase;

class TrendTemplateFeedMappingTest extends TestCase
{
    public function test_map_trend_template_card_shape(): void
    {
        $template = new TrendTemplate([
            'title' => 'Hotel Lobby Duo',
            'slug' => 'hotel-lobby-duo',
            'description' => 'Official template',
            'cover_url' => 'https://example.com/cover.jpg',
            'is_published' => true,
            'is_featured' => true,
            'sort_order' => 1,
            'uses_count' => 3,
            'endpoint_id' => TrendTemplate::FALLBACK_ENDPOINT,
            'model_name' => 'Seedance 2.0',
            'prompt' => TrendTemplate::defaultPromptScaffold(),
            'aspect_ratio' => '16:9',
            'resolution' => '720p',
            'duration' => '10',
            'generate_audio' => false,
            'trend_cost' => 42,
            'locked_assets' => [[
                'key' => 'motion_sketch',
                'kind' => 'video',
                'role' => 'motion_sketch',
                'url' => 'https://example.com/sketch.mp4',
            ]],
            'slots' => TrendTemplate::defaultSlots(),
        ]);
        $template->id = 99;

        $service = app(TrendsFeedService::class);
        $method = new ReflectionMethod(TrendsFeedService::class, 'mapTrendTemplate');
        $method->setAccessible(true);
        $card = $method->invoke($service, $template);

        $this->assertIsArray($card);
        $this->assertSame('template-99', $card['id']);
        $this->assertSame('template', $card['type']);
        $this->assertSame(42, $card['credits']);
        $this->assertTrue($card['featured']);
        $this->assertSame('https://example.com/sketch.mp4', $card['video_url']);
        $this->assertSame('RIMBOAI', $card['creator']);
    }

    public function test_map_trend_template_works_without_cover(): void
    {
        $template = new TrendTemplate([
            'title' => 'No Cover',
            'slug' => 'no-cover',
            'is_published' => true,
            'is_featured' => false,
            'trend_cost' => 10,
            'endpoint_id' => TrendTemplate::FALLBACK_ENDPOINT,
            'prompt' => 'test',
            'cover_url' => null,
            'locked_assets' => [],
        ]);
        $template->id = 7;

        $service = app(TrendsFeedService::class);
        $method = new ReflectionMethod(TrendsFeedService::class, 'mapTrendTemplate');
        $method->setAccessible(true);
        $card = $method->invoke($service, $template);

        $this->assertIsArray($card);
        $this->assertSame('template-7', $card['id']);
        $this->assertSame('', $card['cover']);
        $this->assertNull($card['video_url']);
    }
}
