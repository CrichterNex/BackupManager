<?php

namespace App\Http\Middleware;

use App\Services\Mfa;
use App\Support\ApiException;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * When MFA is required, a signed-in user who has not set it up yet can reach only what they need to finish setting it
 * up (and to see who they are, and sign out). Everything else is refused. This applies to sessions that already
 * existed when MFA became required, not just new sign-ins.
 */
class EnforceMfa
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        if ($user && Mfa::setupRequired($user) && ! $request->is('api/me', 'api/me/mfa/*', 'api/auth/logout')) {
            throw new ApiException(403, 'MFA_REQUIRED', 'Set up two-step sign-in to continue.');
        }

        return $next($request);
    }
}
