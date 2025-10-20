<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

class ValidateApiToken
{
    /**
     * Handle an incoming request.
     *
     * @param  \Closure(\Illuminate\Http\Request): (\Symfony\Component\HttpFoundation\Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $token = $request->bearerToken();

        if (!$token) {
            return response()->json(['error' => 'No token provided'], 401);
        }

        // TEMPORARY: Allow test token for demonstration purposes
        if ($token === 'test-demo-token-123') {
            $request->attributes->set('user', [
                'user_id' => 1,
                'name' => 'Test User',
                'email' => 'test@example.com'
            ]);
            $request->attributes->set('user_id', 1);

            Log::info('Using test demo token for API testing', [
                'user_id' => 1,
                'service' => 'fitnease-social'
            ]);

            return $next($request);
        }

        try {
            $authServiceUrl = env('AUTH_SERVICE_URL');

            Log::info('Validating token with auth service', [
                'service' => 'fitnease-social',
                'auth_service_url' => $authServiceUrl,
                'token_prefix' => substr($token, 0, 10) . '...',
                'endpoint' => $request->path()
            ]);

            $response = Http::timeout(10)->withHeaders([
                'Authorization' => 'Bearer ' . $token,
                'Accept' => 'application/json',
            ])->get($authServiceUrl . '/api/auth/validate');

            if ($response->successful()) {
                $userData = $response->json();

                Log::info('Token validation successful', [
                    'service' => 'fitnease-social',
                    'user_id' => $userData['user_id'] ?? null,
                    'email' => $userData['email'] ?? null,
                    'abilities' => $userData['abilities'] ?? [],
                    'endpoint' => $request->path()
                ]);

                // Store user data in request attributes for controllers
                $request->attributes->set('user', $userData);
                $request->attributes->set('user_id', $userData['user_id'] ?? null);
                $request->attributes->set('user_email', $userData['email'] ?? null);
                $request->attributes->set('user_abilities', $userData['abilities'] ?? []);

                // Set user for broadcasting authentication ONLY for broadcasting routes
                $path = $request->path();
                Log::info('Checking path for broadcasting', [
                    'path' => $path,
                    'is_broadcasting' => str_contains($path, 'broadcasting')
                ]);

                if (str_contains($path, 'broadcasting')) {
                    Log::info('Setting Auth user for broadcasting route', [
                        'user_id' => $userData['user_id'] ?? null,
                        'email' => $userData['email'] ?? null,
                        'username' => $userData['username'] ?? null,
                        'token_prefix' => substr($token, 0, 20) . '...'
                    ]);
                    // Create a User instance for Laravel's broadcasting
                    $user = new \App\Models\User();
                    $user->id = $userData['user_id'] ?? null;
                    $user->email = $userData['email'] ?? null;
                    $user->name = $userData['name'] ?? $userData['email'] ?? 'User';
                    // IMPORTANT: Include username for presence channels
                    $user->username = $userData['username'] ?? 'User';
                    $user->exists = true; // Mark as existing user
                    Auth::setUser($user);
                }

                return $next($request);
            }

            Log::warning('Token validation failed', [
                'service' => 'fitnease-social',
                'status_code' => $response->status(),
                'response_body' => $response->body(),
                'token_prefix' => substr($token, 0, 10) . '...',
                'endpoint' => $request->path()
            ]);

            return response()->json(['error' => 'Invalid token'], 401);

        } catch (\Exception $e) {
            Log::error('Failed to validate token with auth service', [
                'service' => 'fitnease-social',
                'error' => $e->getMessage(),
                'token_prefix' => substr($token, 0, 10) . '...',
                'endpoint' => $request->path(),
                'auth_service_url' => env('AUTH_SERVICE_URL')
            ]);

            return response()->json(['error' => 'Authentication service unavailable'], 503);
        }
    }
}