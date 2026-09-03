<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\UserVoiceCreation;
use App\Services\FalAccountService;
use App\Services\FalWalletCostTracker;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Mockery;
use Tests\TestCase;

class FalWalletCostTrackerTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Schema::create('users', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('email')->unique();
            $table->timestamp('email_verified_at')->nullable();
            $table->string('password');
            $table->unsignedBigInteger('tokens')->default(0);
            $table->rememberToken();
            $table->timestamps();
        });

        Schema::create('user_voice_creations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id');
            $table->string('mode');
            $table->string('endpoint_id');
            $table->string('model_name')->nullable();
            $table->text('prompt');
            $table->unsignedBigInteger('credits_charged')->default(0);
            $table->json('settings')->nullable();
            $table->string('fal_request_id')->nullable();
            $table->decimal('fal_wallet_balance_before', 14, 6)->nullable();
            $table->decimal('fal_wallet_balance_after', 14, 6)->nullable();
            $table->decimal('deducted_amount_from_main_wallet', 14, 6)->nullable();
            $table->decimal('cost_usd', 14, 8)->nullable();
            $table->string('cost_usd_source', 32)->nullable();
            $table->boolean('cost_usd_is_final')->default(false);
            $table->timestamp('settled_at')->nullable();
            $table->timestamp('cost_settled_notified_at')->nullable();
            $table->string('status');
            $table->timestamps();
            $table->softDeletes();
        });
    }

    public function test_billing_events_overwrite_provisional_wallet_delta(): void
    {
        $user = User::factory()->create(['tokens' => 20]);
        $creation = UserVoiceCreation::create([
            'user_id' => $user->id,
            'mode' => 'text-to-voice',
            'endpoint_id' => 'test/voice',
            'model_name' => 'Test',
            'prompt' => 'Hi',
            'credits_charged' => 10,
            'fal_request_id' => 'req_1',
            'fal_wallet_balance_before' => 12.0,
            'cost_usd' => 0.01,
            'cost_usd_source' => 'wallet_delta',
            'cost_usd_is_final' => false,
            'status' => UserVoiceCreation::STATUS_COMPLETED,
        ]);

        $account = Mockery::mock(FalAccountService::class);
        $account->shouldReceive('getRequestCostUsd')->once()->andReturn(0.42);
        $account->shouldReceive('getCreditBalance')->andReturn(11.58);
        $this->app->instance(FalAccountService::class, $account);

        $tracker = app(FalWalletCostTracker::class);
        $this->assertFalse($tracker->isFullyReconciled($creation));
        $this->assertTrue($tracker->reconcile($creation));

        $creation->refresh();
        $this->assertEqualsWithDelta(0.42, (float) $creation->cost_usd, 1e-6);
        $this->assertSame('billing_events', $creation->cost_usd_source);
        $this->assertTrue((bool) $creation->cost_usd_is_final);
        $this->assertTrue($tracker->isFullyReconciled($creation));
    }
}
