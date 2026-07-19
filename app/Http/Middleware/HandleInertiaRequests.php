<?php

namespace App\Http\Middleware;

use App\Models\TokenPackage;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;
use Inertia\Middleware;

class HandleInertiaRequests extends Middleware
{
    protected $rootView = 'app';

    public function version(Request $request): ?string
    {
        return parent::version($request);
    }

    public function share(Request $request): array
    {
        return [
            ...parent::share($request),
            'app' => [
                'name' => config('app.name'),
                'url' => rtrim((string) config('app.url'), '/'),
                'env' => config('app.env'),
            ],
            'auth' => [
                'user' => $request->user()
                    ? [
                        'id' => $request->user()->id,
                        'name' => $request->user()->name,
                        'email' => $request->user()->email,
                        'tokens' => (int) $request->user()->tokens,
                    ]
                    : null,
            ],
            // Live catalogue for Pricing + Buy Credits modal. Checkout still
            // re-reads the DB row server-side — this is display-only.
            'tokenPackages' => fn () => \Illuminate\Support\Facades\Cache::remember(
                'inertia.token_packages.active',
                now()->addMinutes(5),
                function () {
                    if (! Schema::hasTable('token_packages')) {
                        return [];
                    }

                    return TokenPackage::query()
                        ->where('is_active', true)
                        ->orderBy('sort')
                        ->orderBy('id')
                        ->get(['slug', 'name', 'tokens', 'price_dzd'])
                        ->map(fn (TokenPackage $p) => [
                            'slug' => $p->slug,
                            'name' => $p->name,
                            'tokens' => (int) $p->tokens,
                            'price_dzd' => (float) $p->price_dzd,
                        ])
                        ->values()
                        ->all();
                },
            ),
            'flash' => [
                'success' => fn () => $request->session()->get('success'),
                'error' => fn () => $request->session()->get('error'),
            ],
        ];
    }
}
