<?php

namespace App\Providers;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Foundation\Support\Providers\RouteServiceProvider as ServiceProvider;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;

class RateLimitServiceProvider extends ServiceProvider
{
    /**
     * Register services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap services.
     */
    public function boot(): void
    {
        // ============================================================
        // RATE LIMITERS DISABLED FOR JMETER TESTING
        // Original values saved in: DOCUMENTATION/rate_limiter_original_values.txt
        // ============================================================

        RateLimiter::for('api', function (Request $request) {
            return Limit::none();
        });

        RateLimiter::for('lobby_creation', function (Request $request) {
            return Limit::none();
        });

        RateLimiter::for('invitations', function (Request $request) {
            return Limit::none();
        });

        RateLimiter::for('chat_messages', function (Request $request) {
            return Limit::none();
        });

        RateLimiter::for('status_updates', function (Request $request) {
            return Limit::none();
        });

        RateLimiter::for('moderation_actions', function (Request $request) {
            return Limit::none();
        });
    }
}
