<?php

namespace Tests\Feature;

use App\Models\TokenLot;
use App\Models\User;
use App\Models\UserVoiceCreation;
use App\Services\CreationProfitCalculator;
use App\Services\Tokens\TokenLotLedger;
use App\Services\Tokens\TokenService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class TokenLotAndProfitTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config([
            'credits.usd_per_credit' => 0.01,
            'credits.usd_dzd_rate' => 250,
            'credits.gateway_fee_percent' => 0,
            'credits.gateway_fee_fixed_dzd' => 0,
        ]);

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
            $table->decimal('cost_usd', 14, 8)->nullable();
            $table->string('status');
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('token_transactions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id');
            $table->string('kind', 16);
            $table->unsignedBigInteger('amount');
            $table->unsignedBigInteger('balance_after');
            $table->string('creation_type', 32);
            $table->unsignedBigInteger('creation_id');
            $table->json('metadata')->nullable();
            $table->timestamps();
            $table->unique(['creation_type', 'creation_id', 'kind']);
        });

        Schema::create('token_lots', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id');
            $table->string('source', 24);
            $table->unsignedBigInteger('tokens_total');
            $table->unsignedBigInteger('tokens_remaining');
            $table->decimal('amount_dzd', 14, 2)->default(0);
            $table->decimal('fee_dzd', 14, 2)->default(0);
            $table->decimal('net_dzd', 14, 2)->default(0);
            $table->decimal('usd_dzd_rate', 12, 4)->nullable();
            $table->decimal('net_usd', 14, 6)->nullable();
            $table->decimal('usd_per_token', 16, 10)->nullable();
            $table->unsignedBigInteger('payment_id')->nullable();
            $table->timestamps();
        });

        Schema::create('token_lot_allocations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('token_lot_id');
            $table->foreignId('user_id');
            $table->string('creation_type', 32);
            $table->unsignedBigInteger('creation_id');
            $table->string('kind', 16);
            $table->unsignedBigInteger('tokens');
            $table->timestamps();
        });
    }

    public function test_purchase_lot_is_consumed_fifo_and_restored_on_refund(): void
    {
        $user = User::factory()->create(['tokens' => 0]);
        $lots = app(TokenLotLedger::class);
        $lots->grantPurchase($user, 100, 250.0, 9);
        $user->tokens = 100;
        $user->save();

        $tokens = app(TokenService::class);
        $creation = $tokens->reserve($user, 40, 'voice', fn () => $this->voice($user, 40));

        $lot = TokenLot::query()->first();
        $this->assertSame(60, (int) $lot->fresh()->tokens_remaining);

        $this->assertTrue($tokens->refund($user, $creation, 'voice', 'fal_submit_failed'));
        $this->assertSame(100, (int) $lot->fresh()->tokens_remaining);
        $this->assertSame(100, (int) $user->fresh()->tokens);
    }

    public function test_profit_includes_nominal_and_cash_when_lots_exist(): void
    {
        $user = User::factory()->create(['tokens' => 0]);
        app(TokenLotLedger::class)->grantPurchase($user, 100, 125.0, 3);
        $user->tokens = 100;
        $user->save();

        $creation = app(TokenService::class)->reserve($user, 50, 'voice', fn () => $this->voice($user, 50, [
            'fal_cost_usd' => 0.32,
        ]));
        $creation->forceFill(['cost_usd' => 0.32])->save();

        $pnl = app(CreationProfitCalculator::class)->compute($creation->fresh(), 'voice');

        $this->assertSame(50, $pnl['net_tokens']);
        $this->assertEqualsWithDelta(0.50, $pnl['nominal_revenue_usd'], 1e-6);
        $this->assertEqualsWithDelta(0.18, $pnl['nominal_profit_usd'], 1e-6);
        $this->assertTrue($pnl['cash_available']);
        $this->assertEqualsWithDelta(0.25, $pnl['cash_revenue_usd'], 1e-6);
        $this->assertEqualsWithDelta(-0.07, $pnl['cash_profit_usd'], 1e-6);
    }

    public function test_legacy_tokens_mark_cash_unavailable(): void
    {
        $user = User::factory()->create(['tokens' => 80]);
        $creation = app(TokenService::class)->reserve($user, 10, 'voice', fn () => $this->voice($user, 10));
        $creation->forceFill(['cost_usd' => 0.05])->save();

        $pnl = app(CreationProfitCalculator::class)->compute($creation->fresh(), 'voice');

        $this->assertFalse($pnl['cash_available']);
        $this->assertSame('legacy_or_free_tokens', $pnl['cash_note']);
        $this->assertEqualsWithDelta(0.10, $pnl['nominal_revenue_usd'], 1e-6);
    }

    /**
     * @param  array<string, mixed>  $settings
     */
    private function voice(User $user, int $credits, array $settings = []): UserVoiceCreation
    {
        return UserVoiceCreation::create([
            'user_id' => $user->id,
            'mode' => 'text-to-voice',
            'endpoint_id' => 'test/voice',
            'model_name' => 'Test Voice',
            'prompt' => 'Hello',
            'credits_charged' => $credits,
            'settings' => $settings,
            'status' => UserVoiceCreation::STATUS_PENDING,
        ]);
    }
}
