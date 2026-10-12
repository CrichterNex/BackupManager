<?php

namespace App\Http\Middleware;

use App\Support\ApiException;
use App\Support\Permissions;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class RequirePermission
{
    public function handle(Request $request, Closure $next, string $permission): Response
    {
        if (! Permissions::can($request->user()->role, $permission)) {
            throw ApiException::forbidden();
        }

        return $next($request);
    }
}
