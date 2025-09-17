<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class CommunicationsService
{
    private string $baseUrl;

    public function __construct()
    {
        $this->baseUrl = env('COMMS_SERVICE_URL');
    }

    /**
     * Send group notification to communications service
     */
    public function sendGroupNotification(string $token, array $notificationData): ?array
    {
        try {
            Log::info('Sending group notification to communications service', [
                'service' => 'fitnease-social',
                'target_service' => 'fitnease-comms',
                'notification_type' => $notificationData['type'] ?? 'unknown',
                'group_id' => $notificationData['group_id'] ?? null
            ]);

            $response = Http::timeout(10)->withHeaders([
                'Authorization' => 'Bearer ' . $token,
                'Accept' => 'application/json',
                'Content-Type' => 'application/json',
            ])->post($this->baseUrl . '/api/comms/group-notification', $notificationData);

            if ($response->successful()) {
                $result = $response->json();

                Log::info('Group notification sent successfully', [
                    'service' => 'fitnease-social',
                    'target_service' => 'fitnease-comms',
                    'notification_type' => $notificationData['type'] ?? 'unknown',
                    'notification_id' => $result['notification_id'] ?? null
                ]);

                return $result;
            }

            Log::warning('Failed to send group notification', [
                'service' => 'fitnease-social',
                'target_service' => 'fitnease-comms',
                'notification_data' => $notificationData,
                'status_code' => $response->status(),
                'response_body' => $response->body()
            ]);

            return null;

        } catch (\Exception $e) {
            Log::error('Error communicating with communications service', [
                'service' => 'fitnease-social',
                'target_service' => 'fitnease-comms',
                'notification_data' => $notificationData,
                'error' => $e->getMessage()
            ]);

            return null;
        }
    }

    /**
     * Send group invitation to communications service
     */
    public function sendGroupInvitation(string $token, array $invitationData): ?array
    {
        try {
            Log::info('Sending group invitation to communications service', [
                'service' => 'fitnease-social',
                'target_service' => 'fitnease-comms',
                'group_id' => $invitationData['group_id'] ?? null,
                'invited_user_id' => $invitationData['invited_user_id'] ?? null
            ]);

            $response = Http::timeout(10)->withHeaders([
                'Authorization' => 'Bearer ' . $token,
                'Accept' => 'application/json',
                'Content-Type' => 'application/json',
            ])->post($this->baseUrl . '/api/comms/group-invitation', $invitationData);

            if ($response->successful()) {
                $result = $response->json();

                Log::info('Group invitation sent successfully', [
                    'service' => 'fitnease-social',
                    'target_service' => 'fitnease-comms',
                    'group_id' => $invitationData['group_id'] ?? null,
                    'invitation_id' => $result['invitation_id'] ?? null
                ]);

                return $result;
            }

            Log::warning('Failed to send group invitation', [
                'service' => 'fitnease-social',
                'target_service' => 'fitnease-comms',
                'invitation_data' => $invitationData,
                'status_code' => $response->status(),
                'response_body' => $response->body()
            ]);

            return null;

        } catch (\Exception $e) {
            Log::error('Error sending group invitation via communications service', [
                'service' => 'fitnease-social',
                'target_service' => 'fitnease-comms',
                'invitation_data' => $invitationData,
                'error' => $e->getMessage()
            ]);

            return null;
        }
    }
}