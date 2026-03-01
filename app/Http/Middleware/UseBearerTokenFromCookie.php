<?php

namespace App\Http\Middleware;

use App\Services\AuthTokenCookieService;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class UseBearerTokenFromCookie
{
    public function handle(Request $request, Closure $next): Response
    {
        if (!$request->bearerToken()) {
            $cookieToken = (string) $request->cookie(AuthTokenCookieService::COOKIE_NAME, '');

            if ($cookieToken !== '') {
                $request->headers->set('Authorization', sprintf('Bearer %s', $cookieToken));
            }
        }

        return $next($request);
    }
}

