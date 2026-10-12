<?php

namespace App\Http\Middleware;

use App\Support\ApiException;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** Only platform admins may use /api/platform/*. A company admin gets 403, not a hint the routes exist for others. */
class RequirePlatformAdmin
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        if (! $user->is_platform_admin || $user->role !== 'company_admin') {
            throw ApiException::forbidden();
        }

        return $next($request);
    }
}
