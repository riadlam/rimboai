<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class SetLocale
{
    /** @var list<string> */
    private const SUPPORTED = ['en', 'fr', 'ar'];

    public function handle(Request $request, Closure $next): Response
    {
        $locale = $request->cookie('app_lang')
            ?? $request->header('X-App-Lang')
            ?? config('app.locale', 'en');

        if (! is_string($locale) || ! in_array($locale, self::SUPPORTED, true)) {
            $locale = 'en';
        }

        app()->setLocale($locale);

        return $next($request);
    }
}
