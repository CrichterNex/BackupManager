<?php

namespace App\Http\Middleware;

use App\Support\CompanyContext;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

class RequestId
{
    public function handle(Request $request, Closure $next): Response
    {
        // Every request starts with no company; the tenant middleware sets it from the authenticated user.
        CompanyContext::set(null);
        $id = (string) Str::uuid();
        $request->attributes->set('request_id', $id);
        $response = $next($request);
        $response->headers->set('X-Request-Id', $id);

        return $response;
    }
}
