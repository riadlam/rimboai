<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnforceCanonicalUrl
{
    public function handle(Request $request, Closure $next): Response
    {
        $appUrl = rtrim((string) config('app.url'), '/');
        $scheme = strtolower((string) parse_url($appUrl, PHP_URL_SCHEME));
        $host = strtolower((string) parse_url($appUrl, PHP_URL_HOST));

        if ($scheme === 'https' && $host !== '') {
            $wrongScheme = ! $request->isSecure();
            $wrongHost = strtolower($request->getHost()) !== $host;

            if ($wrongScheme || $wrongHost) {
                $status = $request->isMethodSafe() ? 301 : 308;

                return redirect()->away($appUrl.$request->getRequestUri(), $status);
            }
        }

        $response = $next($request);

        if (str_starts_with((string) $response->headers->get('Content-Type'), 'text/html')) {
            // app.blade.php selects its boot entry from the browser's UA.
            $response->setVary(array_values(array_unique([
                ...$response->getVary(),
                'User-Agent',
            ])));
        }

        if ($request->isSecure() && $scheme === 'https') {
            $response->headers->set('Strict-Transport-Security', 'max-age=31536000');
        }

        return $response;
    }
}
