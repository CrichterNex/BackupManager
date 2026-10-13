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

        // The code step of sign-in: separate from the password limit so a normal two-step sign-in is not counted twice.
        RateLimiter::for('mfa', fn (Request $request) => Limit::perMinutes(15, 30)->by('mfa:'.$request->ip())
            ->response(fn (Request $r) => $limited($r, 'Too many attempts. Try again later.')));

        // Agents poll every 30 seconds by default, so the ceiling leaves plenty of room.
        RateLimiter::for('agent', fn (Request $request) => Limit::perHour(config('bms.agent_rate_limit_per_hour'))
            ->by('agent:'.$request->attributes->get('agent')?->id)
            ->response(fn (Request $r) => $limited($r, 'Rate limit exceeded')));

        // Per-user limits, applied once the caller is known. Changes (1000 per hour by default) have their own allowance, so pages
        // that refresh themselves in the background (reads) can never use up what a person needs to make a change.
        RateLimiter::for('api', function (Request $request) use ($limited) {
            $read = in_array($request->method(), ['GET', 'HEAD'], true);

            return Limit::perHour($read ? config('bms.read_rate_limit_per_hour') : config('bms.rate_limit_per_hour'))
                ->by(($read ? 'read:' : 'user:').$request->user()?->id)
                ->response(fn (Request $r) => $limited($r, 'Rate limit exceeded'));
        });
    }
}
