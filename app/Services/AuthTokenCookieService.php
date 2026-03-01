<?php

namespace App\Services;

use Illuminate\Http\JsonResponse;

class AuthTokenCookieService
{
    public const COOKIE_NAME = 'auth_token';

    private const DEFAULT_COOKIE_LIFETIME_MINUTES = 60 * 24 * 7;

    public function attachTokenCookie(JsonResponse $response, string $token): JsonResponse
    {
        $cookieMinutes = (int) config('sanctum.expiration', self::DEFAULT_COOKIE_LIFETIME_MINUTES);
        $minutes = $cookieMinutes > 0 ? $cookieMinutes : self::DEFAULT_COOKIE_LIFETIME_MINUTES;

        return $response->withCookie(cookie(
            self::COOKIE_NAME,
            $token,
            $minutes,
            '/',
            null,
            !app()->environment('local'),
            true,
            false,
            'strict',
        ));
    }

    public function clearTokenCookie(JsonResponse $response): JsonResponse
    {
        return $response->withCookie(cookie()->forget(self::COOKIE_NAME, '/', null));
    }
}

