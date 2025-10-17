<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Console\Scheduling\Schedule;

return Application::configure(basePath: dirname(__DIR__))
    ->withProviders([
        \App\Providers\RateLimitServiceProvider::class,
    ])
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        channels: __DIR__.'/../routes/channels.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->alias([
            'auth.api' => \App\Http\Middleware\ValidateApiToken::class,
        ]);

        // Configure API rate limiting
        $middleware->throttleApi();

        // Custom rate limiters
        $middleware->alias([
            'throttle.lobby' => \Illuminate\Routing\Middleware\ThrottleRequests::class.':lobby_creation',
            'throttle.invitations' => \Illuminate\Routing\Middleware\ThrottleRequests::class.':invitations',
        ]);
    })
    ->withSchedule(function (Schedule $schedule): void {
        // Cleanup expired lobbies every 5 minutes
        $schedule->job(\App\Jobs\CleanupExpiredLobbies::class)->everyFiveMinutes();
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        //
    })->create();
