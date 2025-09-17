<?php

namespace App\Services;

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
            Log::info('Requesting user profile from auth service', [
                'service' => 'fitnease-social',
                'target_service' => 'fitnease-auth',
                'user_id' => $userId,
                'token_prefix' => substr($token, 0, 10) . '...'
            ]);

            $response = Http::timeout(10)->withHeaders([
                'Authorization' => 'Bearer ' . $token,
                'Accept' => 'application/json',
            ])->get($this->baseUrl . "/api/auth/user-profile/{$userId}");

            if ($response->successful()) {
                $userData = $response->json();

                Log::info('User profile retrieved successfully', [
                    'service' => 'fitnease-social',
                    'target_service' => 'fitnease-auth',
                    'user_id' => $userId,
                    'profile_data_keys' => array_keys($userData)
                ]);

                return $userData;
            }

            Log::warning('Failed to retrieve user profile', [
                'service' => 'fitnease-social',
                'target_service' => 'fitnease-auth',
                'user_id' => $userId,
                'status_code' => $response->status(),
                'response_body' => $response->body()
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
}