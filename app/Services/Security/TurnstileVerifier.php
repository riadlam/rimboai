<?php

namespace App\Services\Security;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class TurnstileVerifier
{
    public function verify(?string $token, ?string $remoteIp = null): bool
    {
        $secret = (string) config('services.turnstile.secret_key', '');

        // Fail closed in production if keys are missing; allow local/testing without Turnstile.
        if ($secret === '') {
            return ! app()->environment('production');
        }

        if ($token === null || trim($token) === '') {
            return false;
        }

        try {
            $response = Http::asForm()
                ->timeout(8)
                ->post('https://challenges.cloudflare.com/turnstile/v0/siteverify', array_filter([
                    'secret' => $secret,
                    'response' => $token,
                    'remoteip' => $remoteIp,
                ]));

            if (! $response->successful()) {
                Log::warning('Turnstile siteverify HTTP failure', ['status' => $response->status()]);

                return false;
            }

            return (bool) $response->json('success');
        } catch (\Throwable $e) {
            Log::warning('Turnstile siteverify exception', ['error' => $e->getMessage()]);

            return false;
        }
    }
}
