<?php

namespace Tests\Feature;

use App\Services\FalPricingService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class FalPricingServiceStaleTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Schema::create('text_to_image_models', function (Blueprint $table) {
            $table->id();
            $table->string('endpoint_id');
            $table->string('status')->default('active');
            $table->string('unit')->nullable();
            $table->decimal('unit_price', 12, 6)->nullable();
            $table->timestamp('pricing_fetched_at')->nullable();
            $table->timestamps();
        });
    }

    public function test_stale_catalog_price_fails_closed(): void
    {
        config(['credits.pricing_max_age_minutes' => 60]);

        DB::table('text_to_image_models')->insert([
            'endpoint_id' => 'fal-ai/flux/dev',
            'status' => 'active',
            'unit' => 'image',
            'unit_price' => 0.025,
            'pricing_fetched_at' => now()->subHours(5),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->assertNull(app(FalPricingService::class)->resolve('fal-ai/flux/dev'));
    }

    public function test_fresh_catalog_price_resolves(): void
    {
        config(['credits.pricing_max_age_minutes' => 1440]);

        DB::table('text_to_image_models')->insert([
            'endpoint_id' => 'fal-ai/flux/dev',
            'status' => 'active',
            'unit' => 'image',
            'unit_price' => 0.025,
            'pricing_fetched_at' => now()->subMinutes(8),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $resolved = app(FalPricingService::class)->resolve('fal-ai/flux/dev');
        $this->assertNotNull($resolved);
        $this->assertEqualsWithDelta(0.025, $resolved['unit_price'], 1e-6);
    }
}
