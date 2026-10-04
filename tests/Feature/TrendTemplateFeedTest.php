<?php

namespace Tests\Feature;

use App\Models\TrendTemplate;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Smoke checks against the configured app DB when trend_templates exists.
 * Skips on sqlite RefreshDatabase environments that cannot run MySQL-only migrations.
 */
class TrendTemplateFeedTest extends TestCase
{
    public function test_trends_show_route_accepts_template_keys(): void
    {
        if (! Schema::hasTable('trend_templates')) {
            $this->markTestSkipped('trend_templates table not available');
        }

        $template = TrendTemplate::query()->first();
        if (! $template) {
            $template = TrendTemplate::query()->create([
                'title' => 'Smoke Template',
                'slug' => 'smoke-template-'.uniqid(),
                'cover_url' => 'https://example.com/cover.jpg',
                'is_published' => true,
                'is_featured' => true,
                'sort_order' => 0,
                'endpoint_id' => TrendTemplate::FALLBACK_ENDPOINT,
                'prompt' => TrendTemplate::defaultPromptScaffold(),
                'trend_cost' => 10,
                'locked_assets' => [[
                    'key' => 'motion_sketch',
                    'kind' => 'video',
                    'role' => 'motion_sketch',
                    'url' => 'https://example.com/sketch.mp4',
                ]],
                'slots' => TrendTemplate::defaultSlots(),
                'sheet_prompt' => TrendTemplate::defaultSheetPrompt(),
            ]);
        }

        $response = $this->get('/trends/'.$template->feedKey());
        $response->assertOk();
    }

    public function test_template_remake_route_is_registered(): void
    {
        $this->assertTrue(
            collect(\Illuminate\Support\Facades\Route::getRoutes())->contains(
                fn ($route) => $route->getName() === 'trends.templates.remake',
            ),
        );
    }
}
