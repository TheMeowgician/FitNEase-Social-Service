<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class AuthService
{
    private string $baseUrl;

    public function __construct()
    {
        $this->baseUrl = env('AUTH_SERVICE_URL');
    }

    /**
     * Get user profile information
     */
    public function getUserProfile(string $token, int $userId): ?array
    {
        try {
            // Check Redis cache first (5-min TTL) — avoids HTTP call to auth service
            $cacheKey = "user_profile:{$userId}";
            $cached = Cache::get($cacheKey);
            if ($cached) {
                return $cached;
            }

            $response = Http::timeout(5)->withHeaders([
                'Authorization' => 'Bearer ' . $token,
                'Accept' => 'application/json',
            ])->get($this->baseUrl . "/api/auth/user-profile/{$userId}");

            if ($response->successful()) {
                $userData = $response->json();
                Cache::put($cacheKey, $userData, 300); // 5 minutes
                return $userData;
            }

            Log::warning('Failed to retrieve user profile', [
                'service' => 'fitnease-social',
                'target_service' => 'fitnease-auth',
                'user_id' => $userId,
                'status_code' => $response->status(),
            ]);

            return null;

        } catch (\Exception $e) {
            Log::error('Error communicating with auth service', [
                'service' => 'fitnease-social',
                'target_service' => 'fitnease-auth',
                'user_id' => $userId,
                'error' => $e->getMessage()
            ]);

            return null;
        }
    }

    /**
     * Validate token and get user data
     */
    public function validateToken(string $token): ?array
    {
        try {
            $response = Http::timeout(10)->withHeaders([
                'Authorization' => 'Bearer ' . $token,
                'Accept' => 'application/json',
            ])->get($this->baseUrl . '/api/auth/validate');

            if ($response->successful()) {
                return $response->json();
            }

            return null;

        } catch (\Exception $e) {
            Log::error('Token validation failed', [
                'service' => 'fitnease-social',
                'target_service' => 'fitnease-auth',
                'error' => $e->getMessage()
            ]);

            return null;
        }
    }

    /**
     * Search for user by username
     */
    public function searchUserByUsername(string $token, string $username): ?array
    {
        try {
            Log::info('Searching user by username from auth service', [
                'service' => 'fitnease-social',
                'target_service' => 'fitnease-auth',
                'username' => $username
            ]);

            $response = Http::timeout(5)->withHeaders([
                'Authorization' => 'Bearer ' . $token,
                'Accept' => 'application/json',
            ])->get($this->baseUrl . "/api/auth/user-by-username/{$username}");

            if ($response->successful()) {
                $userData = $response->json();

                Log::info('User found by username', [
                    'service' => 'fitnease-social',
                    'target_service' => 'fitnease-auth',
                    'username' => $username,
                    'user_id' => $userData['user_id'] ?? null
                ]);

                return $userData;
            }

            Log::warning('User not found by username', [
                'service' => 'fitnease-social',
                'target_service' => 'fitnease-auth',
                'username' => $username,
                'status_code' => $response->status()
            ]);

            return null;

        } catch (\Exception $e) {
            Log::error('Error searching user by username', [
                'service' => 'fitnease-social',
                'target_service' => 'fitnease-auth',
                'username' => $username,
                'error' => $e->getMessage()
            ]);

            return null;
        }
    }
}