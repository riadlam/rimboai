<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Route;
use Mockery;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class ClientBootCompatibilityTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Route::middleware('web')->get('/_test/client-boot-shell', function () {
            return view('app', [
                'page' => [
                    'component' => 'Home',
                    'props' => ['auth' => ['user' => null]],
                    'url' => '/_test/client-boot-shell',
                    'version' => null,
                    'clearHistory' => false,
                    'encryptHistory' => false,
                ],
            ]);
        });
        Route::middleware('web')->get('/_test/canonical-url', fn () => response('<p>ok</p>'));
    }

    public function test_meta_iab_receives_the_classic_single_file_bundle(): void
    {
        $response = $this
            ->withHeader(
                'User-Agent',
                'Mozilla/5.0 (iPhone; CPU iPhone OS 18_0 like Mac OS X) AppleWebKit/605.1.15 Mobile/15E148 MetaIAB',
            )
            ->get('/_test/client-boot-shell?fbclid=test-click-id');

        $response->assertOk();
        $response->assertSee('/build-meta/assets/app-meta-', false);
        $response->assertDontSee('System.import', false);
        $response->assertDontSee('type="module"', false);
        $response->assertSee('Fallback navigation', false);
    }

    public function test_facebook_fban_receives_the_classic_bundle(): void
    {
        $response = $this
            ->withHeader(
                'User-Agent',
                'Mozilla/5.0 (iPhone; CPU iPhone OS 18_0 like Mac OS X) AppleWebKit/605.1.15 Mobile/15E148 [FBAN/FBIOS;FBAV/480.0]',
            )
            ->get('/_test/client-boot-shell');

        $response->assertOk();
        $response->assertSee('/build-meta/assets/app-meta-', false);
        $response->assertDontSee('System.import', false);
    }

    #[DataProvider('otherMetaUserAgents')]
    public function test_other_meta_webviews_receive_the_classic_bundle(string $userAgent): void
    {
        $response = $this
            ->withHeader('User-Agent', $userAgent)
            ->get('/_test/client-boot-shell');

        $response->assertOk();
        $response->assertSee('/build-meta/assets/app-meta-', false);
        $response->assertDontSee('type="module"', false);
    }

    public static function otherMetaUserAgents(): array
    {
        return [
            'Instagram' => ['Mozilla/5.0 (iPhone; CPU iPhone OS 18_0 like Mac OS X) Instagram 350.0'],
            'Messenger' => ['Mozilla/5.0 (iPhone; CPU iPhone OS 18_0 like Mac OS X) MessengerForiOS'],
        ];
    }

    #[DataProvider('moduleUserAgents')]
    public function test_safari_and_unmarked_webviews_get_modern_with_classic_recovery(string $userAgent): void
    {
        $response = $this
            ->withHeader('User-Agent', $userAgent)
            ->get('/_test/client-boot-shell');

        $response->assertOk();
        $response->assertSee('type="module"', false);
        $response->assertSee('window.setTimeout(recoverBoot, 6000)', false);
        $response->assertSee('app-meta-', false);
    }

    public static function moduleUserAgents(): array
    {
        return [
            'Safari' => ['Mozilla/5.0 (iPhone; CPU iPhone OS 18_0 like Mac OS X) Version/18.0 Mobile/15E148 Safari/604.1'],
            'unmarked WebView' => ['Mozilla/5.0 (iPhone; CPU iPhone OS 18_0 like Mac OS X) AppleWebKit/605.1.15 Mobile/15E148'],
        ];
    }

    public function test_boot_report_is_a_private_pixel_and_redacts_sensitive_query_values(): void
    {
        Log::spy();

        $response = $this->get(
            '/client/boot-report.gif?id=request-1&stage=bootstrap-failed&entry=meta-classic'
            .'&error='.urlencode('Failed https://example.test/app.js?token=secret&fbclid=click-id'),
        );

        $response->assertOk();
        $response->assertHeader('Content-Type', 'image/gif');
        $response->assertHeader('Cache-Control', 'no-store, private');

        Log::shouldHaveReceived('warning')
            ->once()
            ->with(
                'Client application boot failed',
                Mockery::on(fn (array $context) => ! str_contains($context['error'], 'secret')
                    && ! str_contains($context['error'], 'click-id')),
            );
    }

    public function test_http_links_redirect_to_the_canonical_https_origin(): void
    {
        config()->set('app.url', 'https://rimboai.com');

        $response = $this->get('http://rimboai.com/_test/canonical-url?fbclid=old-post');

        $response->assertStatus(301);
        $response->assertRedirect('https://rimboai.com/_test/canonical-url?fbclid=old-post');
    }

    public function test_https_html_varies_by_user_agent_and_enables_hsts(): void
    {
        config()->set('app.url', 'https://rimboai.com');

        $response = $this->get('https://rimboai.com/_test/canonical-url');

        $response->assertOk();
        $this->assertContains('User-Agent', $response->getVary());
        $response->assertHeader('Strict-Transport-Security', 'max-age=31536000');
    }
}
