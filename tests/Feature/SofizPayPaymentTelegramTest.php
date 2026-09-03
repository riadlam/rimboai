<?php

namespace Tests\Feature;

use App\Models\Payment;
use App\Models\TokenPackage;
use App\Models\User;
use App\Services\SofizPay\SofizPayFulfillmentService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class SofizPayPaymentTelegramTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config([
            'app.url' => 'http://localhost',
            'services.sofizpay.enabled' => true,
            'services.sofizpay.sandbox' => false,
            'services.sofizpay.base_url' => 'https://sofizpay.com',
            'services.sofizpay.merchant_account' => 'GTESTMERCHANT',
            'services.sofizpay.min_amount_dzd' => 75,
            'services.telegram.creations_bot_token' => 'test-creations-token',
            'services.telegram.creations_chat_id' => '12345',
            'services.telegram.bot_token' => null,
            'services.telegram.chat_id' => null,
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

        Schema::create('token_packages', function (Blueprint $table) {
            $table->id();
            $table->string('slug', 64)->unique();
            $table->string('name');
            $table->unsignedBigInteger('tokens');
            $table->decimal('price_dzd', 12, 2);
            $table->unsignedInteger('sort')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('payments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id');
            $table->string('reference', 40)->unique();
            $table->string('provider', 32)->default('sofizpay');
            $table->string('package_slug', 64)->nullable();
            $table->unsignedBigInteger('tokens');
            $table->decimal('amount', 12, 2);
            $table->string('currency', 8)->default('DZD');
            $table->string('status', 32)->default('pending');
            $table->string('transaction_id')->nullable();
            $table->string('cib_order_number', 64)->nullable();
            $table->string('cib_order_id', 128)->nullable();
            $table->json('create_response')->nullable();
            $table->json('last_check_response')->nullable();
            $table->unsignedBigInteger('telegram_message_id')->nullable();
            $table->timestamp('paid_at')->nullable();
            $table->timestamps();
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

        TokenPackage::query()->create([
            'slug' => 'starter',
            'name' => 'Starter',
            'tokens' => 5000,
            'price_dzd' => 3750,
            'sort' => 1,
            'is_active' => true,
        ]);
    }

    public function test_create_checkout_stores_telegram_message_id_and_hides_secrets(): void
    {
        $user = User::factory()->create(['tokens' => 10, 'email' => 'buyer@example.com']);

        $this->fakeGateway(paid: false, telegramMessageId: 9001);

        $response = $this->actingAs($user)
            ->withoutMiddleware(ValidateCsrfToken::class)
            ->postJson('/billing/sofizpay/create', ['pack' => 'starter']);

        $response->assertOk()
            ->assertJson([
                'success' => true,
                'checkout_url' => 'https://cib.satim.dz/checkout/abc',
            ]);
        $this->assertArrayNotHasKey('telegram_message_id', $response->json());

        $payment = Payment::query()->first();
        $this->assertNotNull($payment);
        $this->assertSame(9001, (int) $payment->telegram_message_id);
        $this->assertSame('pending', $payment->status);

        $sends = $this->recordedContaining('sendMessage');
        $this->assertCount(1, $sends);
        $body = (string) ($sends[0][0]->data()['text'] ?? '');
        $this->assertStringContainsString('Status: <b>pending</b>', $body);
        $this->assertStringContainsString('buyer@example.com', $body);
        $this->assertStringContainsString('starter', $body);
        $this->assertStringNotContainsString('https://cib.satim.dz/checkout/abc', $body);
        $this->assertStringNotContainsString('payment_url', $body);
        $this->assertStringNotContainsString('GTESTMERCHANT', $body);
        $this->assertStringNotContainsString('cib-order-1', $body);
    }

    public function test_paid_edits_the_same_telegram_message(): void
    {
        $user = User::factory()->create(['tokens' => 10, 'email' => 'buyer@example.com']);
        $payment = $this->pendingPayment($user, telegramMessageId: 9001);

        $this->fakeGateway(paid: true, telegramMessageId: 9001);

        $result = app(SofizPayFulfillmentService::class)->verifyAndFulfill($payment);

        $this->assertSame('success', $result['status']);
        $this->assertTrue($result['credited']);
        $this->assertSame('paid', $payment->fresh()->status);
        $this->assertSame(5010, (int) $user->fresh()->tokens);
        $this->assertSame(9001, (int) $payment->fresh()->telegram_message_id);

        $this->assertCount(0, $this->recordedContaining('sendMessage'));
        $edits = $this->recordedContaining('editMessageText');
        $this->assertCount(1, $edits);
        $edit = $edits[0][0]->data();
        $body = (string) ($edit['text'] ?? '');
        $this->assertSame('9001', (string) ($edit['message_id'] ?? ''));
        $this->assertStringContainsString('Status: <b>paid</b>', $body);
        $this->assertStringNotContainsString('https://cib.satim.dz', $body);
        $this->assertStringNotContainsString('payment_url', $body);
    }

    public function test_cancel_on_return_edits_status_without_crediting(): void
    {
        $user = User::factory()->create(['tokens' => 10, 'email' => 'buyer@example.com']);
        $payment = $this->pendingPayment($user, telegramMessageId: 9001);

        $this->fakeGateway(
            paid: false,
            telegramMessageId: 9001,
            checkPayload: [
                'respCode' => '17',
                'errorCode' => 0,
                'orderStatus' => 0,
                'ResponseDescription' => 'Customer cancellation',
            ],
        );

        $eid = Crypt::encryptString((string) $payment->id);
        $response = $this->get('/billing/sofizpay/return?eid='.rawurlencode($eid));
        $response->assertRedirect();
        $this->assertStringContainsString('payment=canceled', (string) $response->headers->get('Location'));

        $this->assertSame('canceled', $payment->fresh()->status);
        $this->assertSame(10, (int) $user->fresh()->tokens);

        $edits = $this->recordedContaining('editMessageText');
        $this->assertCount(1, $edits);
        $body = (string) ($edits[0][0]->data()['text'] ?? '');
        $this->assertStringContainsString('Status: <b>canceled</b>', $body);
        $this->assertStringContainsString('Customer cancellation', $body);
        $this->assertCount(0, $this->recordedContaining('sendMessage'));
    }

    public function test_paid_still_credits_if_row_was_canceled(): void
    {
        $user = User::factory()->create(['tokens' => 10, 'email' => 'buyer@example.com']);
        $payment = $this->pendingPayment($user, telegramMessageId: 9001);
        $payment->update(['status' => 'canceled']);

        $this->fakeGateway(paid: true, telegramMessageId: 9001);

        $result = app(SofizPayFulfillmentService::class)->verifyAndFulfill($payment->fresh());

        $this->assertSame('success', $result['status']);
        $this->assertTrue($result['credited']);
        $this->assertSame('paid', $payment->fresh()->status);
        $this->assertSame(5010, (int) $user->fresh()->tokens);

        $edits = $this->recordedContaining('editMessageText');
        $this->assertCount(1, $edits);
        $this->assertStringContainsString('Status: <b>paid</b>', (string) ($edits[0][0]->data()['text'] ?? ''));
    }

    public function test_pending_return_does_not_cancel_or_edit_telegram(): void
    {
        $user = User::factory()->create(['tokens' => 10]);
        $payment = $this->pendingPayment($user, telegramMessageId: 9001);

        $this->fakeGateway(
            paid: false,
            telegramMessageId: 9001,
            checkPayload: [
                'respCode' => '00',
                'errorCode' => 0,
                'orderStatus' => 0,
            ],
        );

        $result = app(SofizPayFulfillmentService::class)->verifyAndFulfill($payment);

        $this->assertSame('pending', $result['status']);
        $this->assertSame('pending', $payment->fresh()->status);
        $this->assertCount(0, $this->recordedContaining('editMessageText'));
        $this->assertCount(0, $this->recordedContaining('sendMessage'));
    }

    public function test_stale_pending_is_abandoned_and_telegram_edited(): void
    {
        $user = User::factory()->create(['tokens' => 10]);
        $payment = $this->pendingPayment($user, telegramMessageId: 9001);
        $payment->forceFill(['created_at' => now()->subHours(49), 'updated_at' => now()->subHours(49)])->save();

        $this->fakeGateway(paid: false, telegramMessageId: 9001);

        $this->artisan('payments:reconcile-sofizpay', ['--hours' => 48])->assertSuccessful();

        $this->assertSame('canceled', $payment->fresh()->status);
        $edits = $this->recordedContaining('editMessageText');
        $this->assertCount(1, $edits);
        $body = (string) ($edits[0][0]->data()['text'] ?? '');
        $this->assertStringContainsString('Status: <b>canceled</b>', $body);
        $this->assertStringContainsString('No payment within 48 hours', $body);
    }

    /**
     * @return Collection<int, array{0: Request, 1: mixed}>
     */
    private function recordedContaining(string $needle)
    {
        return Http::recorded(fn ($request) => str_contains($request->url(), $needle))->values();
    }

    /**
     * @param  array<string, mixed>|null  $checkPayload
     */
    private function fakeGateway(bool $paid, int $telegramMessageId, ?array $checkPayload = null): void
    {
        $check = $checkPayload ?? ($paid
            ? [
                'respCode' => '00',
                'errorCode' => 0,
                'orderStatus' => 2,
                'Amount' => '3750.00',
                'destination_account' => 'GTESTMERCHANT',
            ]
            : [
                'respCode' => '00',
                'errorCode' => 0,
                'orderStatus' => 0,
            ]);

        Http::fake(function ($request) use ($telegramMessageId, $check) {
            $url = $request->url();

            if (str_contains($url, 'make-cib-transaction')) {
                return Http::response([
                    'success' => true,
                    'payment_url' => 'https://cib.satim.dz/checkout/abc',
                    'transaction_id' => 'tx-1',
                    'cib_transaction_id' => 'cib-order-1',
                    'cib_response' => ['orderId' => 'md-1'],
                ]);
            }

            if (str_contains($url, 'cib-transaction-check')) {
                return Http::response($check);
            }

            if (str_contains($url, 'sendMessage')) {
                return Http::response([
                    'ok' => true,
                    'result' => ['message_id' => $telegramMessageId],
                ]);
            }

            if (str_contains($url, 'editMessageText')) {
                return Http::response([
                    'ok' => true,
                    'result' => ['message_id' => $telegramMessageId],
                ]);
            }

            return Http::response(['ok' => true]);
        });
    }

    private function pendingPayment(User $user, int $telegramMessageId): Payment
    {
        return Payment::query()->create([
            'user_id' => $user->id,
            'reference' => 'RBTEST'.strtoupper(substr(md5((string) microtime(true)), 0, 8)),
            'provider' => 'sofizpay',
            'package_slug' => 'starter',
            'tokens' => 5000,
            'amount' => 3750,
            'currency' => 'DZD',
            'status' => 'pending',
            'cib_order_number' => 'cib-order-1',
            'telegram_message_id' => $telegramMessageId,
        ]);
    }
}
