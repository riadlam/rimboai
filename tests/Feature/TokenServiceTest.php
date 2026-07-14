<?php

namespace Tests\Feature;

use App\Exceptions\InsufficientTokensException;
use App\Models\User;
use App\Models\UserVoiceCreation;
use App\Services\Tokens\TokenService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class TokenServiceTest extends TestCase
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
            $table->unsignedBigInteger('tokens')->default(100);
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
            $table->string('voice_id')->nullable();
            $table->string('voice_name')->nullable();
            $table->unsignedBigInteger('credits_charged')->default(0);
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
    }

    public function test_it_atomically_debits_tokens_and_records_a_ledger_entry(): void
    {
        $user = User::factory()->create(['tokens' => 50]);
        $service = app(TokenService::class);

        $creation = $service->reserve($user, 15, 'voice', fn () => $this->voiceCreation($user));

        $this->assertSame(35, $user->fresh()->tokens);
        $this->assertDatabaseHas('token_transactions', [
            'user_id' => $user->id,
            'kind' => 'debit',
            'amount' => 15,
            'balance_after' => 35,
            'creation_type' => 'voice',
            'creation_id' => $creation->id,
        ]);
    }

    public function test_insufficient_tokens_roll_back_the_creation_and_balance(): void
    {
        $user = User::factory()->create(['tokens' => 4]);
        $service = app(TokenService::class);

        try {
            $service->reserve($user, 5, 'voice', fn () => $this->voiceCreation($user));
            $this->fail('Expected insufficient tokens exception.');
        } catch (InsufficientTokensException $e) {
            $this->assertSame(5, $e->required);
            $this->assertSame(4, $e->available);
        }

        $this->assertSame(4, $user->fresh()->tokens);
        $this->assertDatabaseCount('user_voice_creations', 0);
        $this->assertDatabaseCount('token_transactions', 0);
    }

    public function test_refunds_are_idempotent(): void
    {
        $user = User::factory()->create(['tokens' => 20]);
        $service = app(TokenService::class);
        $creation = $service->reserve($user, 7, 'voice', fn () => $this->voiceCreation($user));

        $this->assertTrue($service->refund($user, $creation, 'voice', 'submit_failed'));
        $this->assertFalse($service->refund($user, $creation, 'voice', 'submit_failed_again'));

        $this->assertSame(20, $user->fresh()->tokens);
        $this->assertSame(
            1,
            DB::table('token_transactions')
                ->where('creation_type', 'voice')
                ->where('creation_id', $creation->id)
                ->where('kind', 'refund')
                ->count(),
        );
    }

    private function voiceCreation(User $user): UserVoiceCreation
    {
        return UserVoiceCreation::create([
            'user_id' => $user->id,
            'mode' => 'text-to-voice',
            'endpoint_id' => 'test/voice',
            'model_name' => 'Test Voice',
            'prompt' => 'Hello',
            'voice_id' => 'test',
            'voice_name' => 'Test',
            'credits_charged' => 1,
            'status' => UserVoiceCreation::STATUS_PENDING,
        ]);
    }
}
