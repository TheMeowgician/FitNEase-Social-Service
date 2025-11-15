<?php

namespace App\Services;

use AgoraTools\RtcTokenBuilder2;
use App\Models\AgoraSession;
use App\Models\AgoraParticipant;
use Illuminate\Support\Str;

class AgoraService
{
    private string $appId;
    private string $appCertificate;
    private int $tokenExpiry;

    public function __construct()
    {
        $this->appId = config('services.agora.app_id');
        $this->appCertificate = config('services.agora.app_certificate');
        $this->tokenExpiry = config('services.agora.token_expiry', 3600);
    }

    /**
     * Generate Agora RTC token for a user in a session
     */
    public function generateToken(string $sessionId, int $userId, string $role = 'publisher'): array
    {
        // Create or get existing Agora session
        $agoraSession = AgoraSession::firstOrCreate(
            ['session_id' => $sessionId],
            [
                'channel_name' => $this->generateChannelName($sessionId),
                'created_at' => now(),
                'expires_at' => now()->addSeconds($this->tokenExpiry),
            ]
        );

        $channelName = $agoraSession->channel_name;

        // Agora role: 1 = publisher (can publish & subscribe), 2 = subscriber (subscribe only)
        $roleCode = $role === 'publisher' ? 1 : 2;

        // Generate token
        $token = RtcTokenBuilder2::buildTokenWithUid(
            $this->appId,
            $this->appCertificate,
            $channelName,
            $userId, // Use user ID as Agora UID
            $roleCode,
            $this->tokenExpiry
        );

        // Save participant info
        AgoraParticipant::updateOrCreate(
            [
                'session_id' => $sessionId,
                'user_id' => $userId,
            ],
            [
                'token' => $token,
                'role' => $role,
                'joined_at' => now(),
                'left_at' => null,
            ]
        );

        return [
            'token' => $token,
            'channel_name' => $channelName,
            'uid' => $userId,
            'app_id' => $this->appId,
            'expires_at' => now()->addSeconds($this->tokenExpiry)->toIso8601String(),
        ];
    }

    /**
     * Revoke user's access (mark as left)
     */
    public function revokeAccess(string $sessionId, int $userId): void
    {
        AgoraParticipant::where('session_id', $sessionId)
            ->where('user_id', $userId)
            ->update(['left_at' => now()]);
    }

    /**
     * Get all active participants in a session
     */
    public function getActiveParticipants(string $sessionId): array
    {
        return AgoraParticipant::where('session_id', $sessionId)
            ->whereNull('left_at')
            ->get()
            ->toArray();
    }

    /**
     * Update participant's audio/video status
     */
    public function updateMediaStatus(string $sessionId, int $userId, bool $videoEnabled, bool $audioEnabled): void
    {
        AgoraParticipant::where('session_id', $sessionId)
            ->where('user_id', $userId)
            ->update([
                'video_enabled' => $videoEnabled,
                'audio_enabled' => $audioEnabled,
            ]);
    }

    /**
     * Clean up expired sessions
     */
    public function cleanupExpiredSessions(): void
    {
        AgoraSession::where('expires_at', '<', now())->delete();
    }

    /**
     * Generate unique channel name for a session
     */
    private function generateChannelName(string $sessionId): string
    {
        return 'fitnease_' . $sessionId;
    }
}
