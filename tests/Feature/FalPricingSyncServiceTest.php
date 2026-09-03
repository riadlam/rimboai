<?php

namespace Tests\Feature;

use App\Services\FalPricingSyncService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class FalPricingSyncServiceTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config(['services.fal.key' => 'test-key', 'credits.pricing_min_coverage' => 1.0, 'credits.pricing_max_change_ratio' => 4.0]);

        Schema::create('fal_pricing_sync_runs', function (Blueprint $table) {
            $table->id();
            $table->string('status', 24)->default('running');
            $table->unsignedInteger('requested')->default(0);
            $table->unsignedInteger('observed')->default(0);
            $table->unsignedInteger('priced')->default(0);
            $table->unsignedInteger('published')->default(0);
            $table->unsignedInteger('quarantined')->default(0);
            $table->unsignedInteger('kept_last_good')->default(0);
            $table->unsignedInteger('deactivated')->default(0);
            $table->unsignedInteger('reactivated')->default(0);
            $table->decimal('coverage', 8, 4)->nullable();
            $table->boolean('dry_run')->default(false);
            $table->json('tables')->nullable();
            $table->text('error')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();
        });

        Schema::create('fal_pricing_observations', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('sync_run_id');
            $table->string('model_table', 64);
            $table->unsignedBigInteger('model_id')->nullable();
            $table->string('endpoint_id', 255);
            $table->string('name')->nullable();
            $table->string('raw_unit', 64)->nullable();
            $table->decimal('raw_price', 16, 8)->nullable();
            $table->string('currency', 8)->nullable();
            $table->string('normalized_unit', 64)->nullable();
            $table->decimal('normalized_price', 16, 8)->nullable();
            $table->string('fal_status', 24)->nullable();
            $table->string('decision', 32);
            $table->string('anomaly', 64)->nullable();
            $table->json('payload')->nullable();
            $table->timestamp('checked_at')->nullable();
            $table->timestamp('published_at')->nullable();
            $table->timestamps();
        });

        Schema::create('text_to_image_models', function (Blueprint $table) {
            $table->id();
            $table->string('endpoint_id');
            $table->string('name')->nullable();
            $table->string('status')->default('active');
            $table->string('unit')->nullable();
            $table->decimal('unit_price', 12, 6)->nullable();
            $table->timestamp('pricing_fetched_at')->nullable();
            $table->unsignedTinyInteger('status_missing_streak')->default(0);
            $table->timestamps();
        });
    }

    public function test_successful_sync_publishes_price_and_records_run(): void
    {
        \Illuminate\Support\Facades\DB::table('text_to_image_models')->insert([
            'endpoint_id' => 'fal-ai/flux/dev',
            'name' => 'Flux',
            'status' => 'active',
            'unit' => 'image',
            'unit_price' => 0.02,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        Http::fake([
            'https://api.fal.ai/v1/models?*' => Http::response([
                'models' => [['endpoint_id' => 'fal-ai/flux/dev', 'metadata' => ['status' => 'active']]],
            ], 200),
            'https://api.fal.ai/v1/models/pricing?*' => Http::response([
                'prices' => [[
                    'endpoint_id' => 'fal-ai/flux/dev',
                    'unit_price' => 0.025,
                    'unit' => 'image',
                    'currency' => 'USD',
                ]],
                'has_more' => false,
                'next_cursor' => null,
            ], 200),
        ]);

        $summary = app(FalPricingSyncService::class)->run(['text_to_image_models'], false, false, 0);

        $this->assertFalse($summary['failed']);
        $this->assertSame(1, $summary['priced']);
        $this->assertEqualsWithDelta(0.025, (float) \Illuminate\Support\Facades\DB::table('text_to_image_models')->value('unit_price'), 1e-6);
        $this->assertNotNull(\Illuminate\Support\Facades\DB::table('text_to_image_models')->value('pricing_fetched_at'));
        $this->assertDatabaseCount('fal_pricing_observations', 1);
    }

    public function test_incomplete_pricing_keeps_last_good_and_fails_coverage(): void
    {
        \Illuminate\Support\Facades\DB::table('text_to_image_models')->insert([
            'endpoint_id' => 'fal-ai/flux/dev',
            'name' => 'Flux',
            'status' => 'active',
            'unit' => 'image',
            'unit_price' => 0.02,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        Http::fake([
            'https://api.fal.ai/v1/models?*' => Http::response([
                'models' => [['endpoint_id' => 'fal-ai/flux/dev', 'metadata' => ['status' => 'active']]],
            ], 200),
            'https://api.fal.ai/v1/models/pricing?*' => Http::response(['prices' => [], 'has_more' => false], 200),
        ]);

        $summary = app(FalPricingSyncService::class)->run(['text_to_image_models'], false, false, 0);

        $this->assertTrue($summary['failed']);
        $this->assertEqualsWithDelta(0.02, (float) \Illuminate\Support\Facades\DB::table('text_to_image_models')->value('unit_price'), 1e-6);
    }

    public function test_missing_endpoint_is_not_deactivated_on_first_sight(): void
    {
        \Illuminate\Support\Facades\DB::table('text_to_image_models')->insert([
            'endpoint_id' => 'fal-ai/flux/dev',
            'name' => 'Flux',
            'status' => 'active',
            'unit' => 'image',
            'unit_price' => 0.025,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        Http::fake([
            'https://api.fal.ai/v1/models?*' => Http::response(['models' => []], 200),
            'https://api.fal.ai/v1/models/pricing?*' => Http::response([
                'prices' => [[
                    'endpoint_id' => 'fal-ai/flux/dev',
                    'unit_price' => 0.025,
                    'unit' => 'image',
                    'currency' => 'USD',
                ]],
                'has_more' => false,
            ], 200),
        ]);

        app(FalPricingSyncService::class)->run(['text_to_image_models'], false, false, 0);

        $row = \Illuminate\Support\Facades\DB::table('text_to_image_models')->first();
        $this->assertSame('active', $row->status);
        $this->assertSame(1, (int) $row->status_missing_streak);
    }

    public function test_suspicious_price_change_is_quarantined(): void
    {
        \Illuminate\Support\Facades\DB::table('text_to_image_models')->insert([
            'endpoint_id' => 'fal-ai/flux/dev',
            'name' => 'Flux',
            'status' => 'active',
            'unit' => 'image',
            'unit_price' => 0.025,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        Http::fake([
            'https://api.fal.ai/v1/models?*' => Http::response([
                'models' => [['endpoint_id' => 'fal-ai/flux/dev', 'metadata' => ['status' => 'active']]],
            ], 200),
            'https://api.fal.ai/v1/models/pricing?*' => Http::response([
                'prices' => [[
                    'endpoint_id' => 'fal-ai/flux/dev',
                    'unit_price' => 2.5,
                    'unit' => 'image',
                    'currency' => 'USD',
                ]],
                'has_more' => false,
            ], 200),
        ]);

        $summary = app(FalPricingSyncService::class)->run(['text_to_image_models'], false, false, 0);

        $this->assertSame(1, $summary['quarantined']);
        $this->assertEqualsWithDelta(0.025, (float) \Illuminate\Support\Facades\DB::table('text_to_image_models')->value('unit_price'), 1e-6);
    }
}
