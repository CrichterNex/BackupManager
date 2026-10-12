<?php

namespace App\Providers;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        //
    }

    public function boot(): void
    {
        $limited = fn (Request $request, string $message) => response()->json([
            'success' => false, 'error' => $message, 'error_code' => 'RATE_LIMIT', 'request_id' => $request->attributes->get('request_id'),
        ], 429);

        // Brute-force protection on login, per client address.
        RateLimiter::for('login', fn (Request $request) => Limit::perMinutes(15, config('bms.login_attempts_per_15_min'))
            ->by($request->ip())
            ->response(fn (Request $r) => $limited($r, 'Too many login attempts. Try again later.')));

        // Per-user limit (1000 per hour by default), applied once the caller is known.
        RateLimiter::for('api', fn (Request $request) => Limit::perHour(config('bms.rate_limit_per_hour'))
            ->by('user:'.$request->user()?->id)
            ->response(fn (Request $r) => $limited($r, 'Rate limit exceeded')));
    }
}
