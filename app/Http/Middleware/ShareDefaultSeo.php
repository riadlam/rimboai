<?php

namespace App\Http\Middleware;

use App\Services\SeoService;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\View;
use Symfony\Component\HttpFoundation\Response;

/**
 * Injects server-rendered SEO meta into the Blade layout for crawlers and social bots.
 * Controllers may call SeoService::share() to override before Inertia::render().
 */
class ShareDefaultSeo
{
    public function __construct(private readonly SeoService $seo) {}

    public function handle(Request $request, Closure $next): Response
    {
        if (! View::shared('seo')) {
            $this->seo->share($this->seo->forRoute());
        }

        return $next($request);
    }
}
