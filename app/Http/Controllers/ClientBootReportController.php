<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Log;

class ClientBootReportController extends Controller
{
    public function __invoke(Request $request): Response
    {
        $stage = mb_substr((string) $request->query('stage', 'unknown'), 0, 80);
        $entry = mb_substr((string) $request->query('entry', 'blade'), 0, 80);
        $error = mb_substr((string) $request->query('error', ''), 0, 500);
        $requestId = mb_substr((string) $request->query('id', ''), 0, 64);

        // Do not retain query values that could contain OAuth or attribution IDs.
        $error = preg_replace(
            '/([?&](?:token|code|state|fbclid|gclid|signature)=)[^&\s]+/i',
            '$1[redacted]',
            str_replace(["\r", "\n"], ' ', $error),
        ) ?? '';

        $referer = (string) $request->headers->get('referer', '');
        $pagePath = parse_url($referer, PHP_URL_PATH);

        Log::warning('Client application boot failed', [
            'request_id' => $requestId,
            'stage' => $stage,
            'entry' => $entry,
            'error' => $error,
            'page_path' => is_string($pagePath) ? mb_substr($pagePath, 0, 255) : null,
            'user_agent' => mb_substr((string) $request->userAgent(), 0, 500),
        ]);

        return response(
            base64_decode('R0lGODlhAQABAIAAAAAAAP///ywAAAAAAQABAAACAUwAOw==', true) ?: '',
            200,
            [
                'Content-Type' => 'image/gif',
                'Cache-Control' => 'no-store, private',
                'X-Content-Type-Options' => 'nosniff',
            ],
        );
    }
}
