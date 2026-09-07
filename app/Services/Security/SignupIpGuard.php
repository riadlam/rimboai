<?php

namespace App\Services\Security;

use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Validation\ValidationException;

class SignupIpGuard
{
    public const CACHE_PREFIX = 'signup:ip:';

    public const WINDOW_HOURS = 24;

    public function opaqueMessage(): string
    {
        return __('messages.registration_unavailable');
    }

    public function assertAllowed(?string $ip): void
    {
        if ($ip === null || $ip === '') {
            return;
        }

        if ($this->isLocked($ip)) {
            throw ValidationException::withMessages([
                'email' => $this->opaqueMessage(),
            ]);
        }
    }

    public function isLocked(string $ip): bool
    {
        if (Cache::has(self::CACHE_PREFIX.$ip)) {
            return true;
        }

        return User::query()
            ->where('registration_ip', $ip)
            ->where('created_at', '>=', now()->subHours(self::WINDOW_HOURS))
            ->exists();
    }

    public function remember(string $ip): void
    {
        Cache::put(self::CACHE_PREFIX.$ip, 1, now()->addHours(self::WINDOW_HOURS));
    }
}
