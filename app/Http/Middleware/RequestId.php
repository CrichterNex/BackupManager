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
        // The API only ever speaks JSON. Without this, a caller that omits "Accept: application/json" (a browser fetch,
        // curl) would get a redirect to a login page that does not exist, and a 500 instead of a clean 401.
        if ($request->is('api/*')) {
            $request->headers->set('Accept', 'application/json');
        }
        $id = (string) Str::uuid();
        $request->attributes->set('request_id', $id);
        $response = $next($request);
        $response->headers->set('X-Request-Id', $id);

        return $response;
    }
}
