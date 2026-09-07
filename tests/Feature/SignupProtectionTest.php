<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\Security\SignupIpGuard;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Laravel\Socialite\Contracts\User as SocialiteUser;
use Laravel\Socialite\Facades\Socialite;
use Mockery;
use Tests\TestCase;

class SignupProtectionTest extends TestCase
{
    private string $opaque;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutMiddleware(ValidateCsrfToken::class);

        config([
            'services.turnstile.site_key' => 'test-site',
            'services.turnstile.secret_key' => 'test-secret',
            'services.google.client_id' => 'google-client',
            'services.google.client_secret' => 'google-secret',
            'credits.starter_tokens' => 25,
        ]);

        $this->opaque = __('messages.registration_unavailable');

        Schema::dropIfExists('users');
        Schema::create('users', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('email')->unique();
            $table->string('google_id')->nullable()->unique();
            $table->string('avatar')->nullable();
            $table->timestamp('email_verified_at')->nullable();
            $table->string('password')->nullable();
            $table->rememberToken();
            $table->string('registration_ip', 45)->nullable()->index();
            $table->unsignedInteger('tokens')->default(0);
            $table->timestamps();
        });
    }

    public function test_register_rejects_failed_turnstile_with_opaque_message(): void
    {
        Http::fake([
            'https://challenges.cloudflare.com/turnstile/v0/siteverify' => Http::response(['success' => false], 200),
        ]);

        $response = $this->from('/?register')->post('/register', [
            'name' => 'Ada',
            'email' => 'ada@example.com',
            'password' => 'password1',
            'password_confirmation' => 'password1',
            'cf-turnstile-response' => 'bad-token',
        ]);

        $response->assertRedirect('/?register');
        $response->assertSessionHasErrors(['email' => $this->opaque]);
        $this->assertSame(0, User::query()->count());
    }

    public function test_register_succeeds_with_valid_turnstile_and_stores_ip(): void
    {
        Http::fake([
            'https://challenges.cloudflare.com/turnstile/v0/siteverify' => Http::response(['success' => true], 200),
        ]);

        $response = $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.10'])
            ->post('/register', [
                'name' => 'Ada',
                'email' => 'ada@example.com',
                'password' => 'password1',
                'password_confirmation' => 'password1',
                'cf-turnstile-response' => 'ok-token',
            ]);

        $response->assertRedirect(route('home'));
        $user = User::query()->first();
        $this->assertNotNull($user);
        $this->assertSame('203.0.113.10', $user->registration_ip);
        $this->assertTrue(app(SignupIpGuard::class)->isLocked('203.0.113.10'));
    }

    public function test_second_register_from_same_ip_within_24h_is_opaque(): void
    {
        Http::fake([
            'https://challenges.cloudflare.com/turnstile/v0/siteverify' => Http::response(['success' => true], 200),
        ]);

        User::factory()->create([
            'email' => 'first@example.com',
            'registration_ip' => '203.0.113.44',
            'created_at' => now()->subHour(),
        ]);

        $response = $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.44'])
            ->from('/?register')
            ->post('/register', [
                'name' => 'Spam',
                'email' => 'second@example.com',
                'password' => 'password1',
                'password_confirmation' => 'password1',
                'cf-turnstile-response' => 'ok-token',
            ]);

        $response->assertRedirect('/?register');
        $response->assertSessionHasErrors(['email' => $this->opaque]);
        $this->assertFalse(User::query()->where('email', 'second@example.com')->exists());
        $this->assertStringNotContainsStringIgnoringCase('ip', $this->opaque);
        $this->assertStringNotContainsStringIgnoringCase('token', $this->opaque);
    }

    public function test_google_new_user_blocked_by_same_ip_lock(): void
    {
        User::factory()->create([
            'email' => 'existing@example.com',
            'registration_ip' => '198.51.100.7',
            'created_at' => now()->subMinutes(30),
        ]);

        $social = Mockery::mock(SocialiteUser::class);
        $social->shouldReceive('getId')->andReturn('g-123');
        $social->shouldReceive('getEmail')->andReturn('newbie@example.com');
        $social->shouldReceive('getName')->andReturn('Newbie');
        $social->shouldReceive('getAvatar')->andReturn(null);

        $provider = Mockery::mock();
        $provider->shouldReceive('user')->andReturn($social);
        Socialite::shouldReceive('driver')->with('google')->andReturn($provider);

        $response = $this->withServerVariables(['REMOTE_ADDR' => '198.51.100.7'])
            ->get('/auth/google/callback');

        $response->assertRedirect('/?register');
        $response->assertSessionHasErrors(['email' => $this->opaque]);
        $this->assertFalse(User::query()->where('email', 'newbie@example.com')->exists());
    }

    public function test_login_rejects_failed_turnstile(): void
    {
        User::factory()->create([
            'email' => 'ada@example.com',
            'password' => bcrypt('password1'),
        ]);

        Http::fake([
            'https://challenges.cloudflare.com/turnstile/v0/siteverify' => Http::response(['success' => false], 200),
        ]);

        $response = $this->from('/?login')->post('/login', [
            'email' => 'ada@example.com',
            'password' => 'password1',
            'cf-turnstile-response' => 'bad-token',
        ]);

        $response->assertRedirect('/?login');
        $response->assertSessionHasErrors('email');
        $this->assertGuest();
    }
}
