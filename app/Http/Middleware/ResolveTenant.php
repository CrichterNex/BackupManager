<?php

namespace App\Http\Middleware;

use App\Support\ApiException;
use App\Support\CompanyContext;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * The token only identifies the user. Company, role and active state are read fresh from the database on
 * every request, so disabling a user or changing a role takes effect immediately, and the company used for
 * every tenant query comes from the database, never from the request.
 */
class ResolveTenant
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        $user?->loadMissing('company');
        if (! $user || ! $user->active || ! $user->company?->is_active) {
            throw ApiException::unauthorized('Account is disabled');
        }
        CompanyContext::set($user->company_id);

        return $next($request);
    }

    public function terminate(Request $request, Response $response): void
    {
        CompanyContext::set(null);
    }
}
