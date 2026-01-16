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
        // Default API rate limit: 200 requests per minute per user
        // Increased from 60 to prevent rate limiting on mobile app reloads
        RateLimiter::for('api', function (Request $request) {
            return Limit::perMinute(200)->by($request->attributes->get('user_id') ?? $request->ip())
                ->response(function (Request $request, array $headers) {
                    return response()->json([
                        'status' => 'error',
                        'message' => 'Too many requests. Please wait a moment and try again.',
                        'retry_after' => $headers['Retry-After'] ?? 60,
                    ], 429);
                });
        });

        // Lobby creation rate limit: 100 lobbies per hour per user (increased for development)
        RateLimiter::for('lobby_creation', function (Request $request) {
            $userId = $request->attributes->get('user_id') ?? $request->ip();
            return Limit::perHour(100)->by("lobby_creation:{$userId}")
                ->response(function (Request $request, array $headers) {
                    return response()->json([
                        'status' => 'error',
                        'message' => 'Too many lobby creations. You can create a maximum of 100 lobbies per hour.',
                        'retry_after' => $headers['Retry-After'] ?? 3600,
                    ], 429);
                });
        });

        // Invitation rate limit: 20 invitations per hour per user
        RateLimiter::for('invitations', function (Request $request) {
            $userId = $request->attributes->get('user_id') ?? $request->ip();
            return Limit::perHour(20)->by("invitations:{$userId}")
                ->response(function (Request $request, array $headers) {
                    return response()->json([
                        'status' => 'error',
                        'message' => 'Too many invitations sent. You can send a maximum of 20 invitations per hour.',
                        'retry_after' => $headers['Retry-After'] ?? 3600,
                    ], 429);
                });
        });

        // Chat message rate limit: 30 messages per minute per user
        RateLimiter::for('chat_messages', function (Request $request) {
            $userId = $request->attributes->get('user_id') ?? $request->ip();
            return Limit::perMinute(30)->by("chat:{$userId}")
                ->response(function (Request $request, array $headers) {
                    return response()->json([
                        'status' => 'error',
                        'message' => 'Too many messages. Please slow down.',
                        'retry_after' => $headers['Retry-After'] ?? 60,
                    ], 429);
                });
        });

        // Status update rate limit: 20 updates per minute per user
        RateLimiter::for('status_updates', function (Request $request) {
            $userId = $request->attributes->get('user_id') ?? $request->ip();
            return Limit::perMinute(20)->by("status:{$userId}")
                ->response(function (Request $request, array $headers) {
                    return response()->json([
                        'status' => 'error',
                        'message' => 'Too many status updates. Please slow down.',
                        'retry_after' => $headers['Retry-After'] ?? 60,
                    ], 429);
                });
        });

        // Moderation actions rate limit: 10 kicks per hour per user
        RateLimiter::for('moderation_actions', function (Request $request) {
            $userId = $request->attributes->get('user_id') ?? $request->ip();
            return Limit::perHour(10)->by("moderation:{$userId}")
                ->response(function (Request $request, array $headers) {
                    return response()->json([
                        'status' => 'error',
                        'message' => 'Too many moderation actions. Please wait before trying again.',
                        'retry_after' => $headers['Retry-After'] ?? 3600,
                    ], 429);
                });
        });
    }
}
