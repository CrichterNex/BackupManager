<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * The web UI keeps its API token in an HttpOnly cookie, so scripts never see it. This lets that cookie
 * authenticate the same way an Authorization: Bearer header does for other API clients.
 */
class CookieToken
{
    public const COOKIE = 'auth_token';

    public function handle(Request $request, Closure $next): Response
    {
        if (! $request->bearerToken() && is_string($token = $request->cookie(self::COOKIE)) && $token !== '') {
            $request->headers->set('Authorization', 'Bearer '.$token);
        }

        return $next($request);
    }
}
