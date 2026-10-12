<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class SecurityHeaders
{
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);
        $h = $response->headers;
        // Inline styles are allowed because the printable report pages carry their own stylesheet.
        $h->set('Content-Security-Policy', "default-src 'self'; script-src 'self'; style-src 'self' 'unsafe-inline'; img-src 'self' data:; frame-ancestors 'none'; base-uri 'self'; form-action 'self'");
        $h->set('X-Content-Type-Options', 'nosniff');
        $h->set('X-Frame-Options', 'DENY');
        $h->set('Referrer-Policy', 'no-referrer');
        $h->remove('X-Powered-By');
        header_remove('X-Powered-By'); // set by PHP itself, below Laravel
        if ($request->is('api/*')) {
            $h->set('Cache-Control', 'no-store');
        }

        return $response;
    }
}
