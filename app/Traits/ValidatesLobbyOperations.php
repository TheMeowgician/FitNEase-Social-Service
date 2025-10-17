<?php

namespace App\Traits;

use App\Models\WorkoutLobby;
use App\Models\WorkoutInvitation;

trait ValidatesLobbyOperations
{
    /**
     * Check if user can create/join lobby
     */
    protected function canUserJoinLobby(int $userId): array
    {
        $activeLobby = WorkoutLobby::active()
            ->whereHas('activeMembers', function($q) use ($userId) {
                $q->where('user_id', $userId);
            })
            ->first();

        if ($activeLobby) {
            return [
                'can_join' => false,
                'reason' => 'User is already in another lobby',
                'lobby' => $activeLobby,
            ];
        }

        return ['can_join' => true];
    }

    /**
     * Check if invitation can be sent
     */
    protected function canSendInvitation(string $sessionId, int $userId): array
    {
        // Check if user already has pending invitation for this lobby
        $existingInvitation = WorkoutInvitation::forSession($sessionId)
            ->forUser($userId)
            ->pending()
            ->first();

        if ($existingInvitation) {
            return [
                'can_send' => false,
                'reason' => 'User already has a pending invitation for this lobby',
            ];
        }

        // Check if user is already in a lobby
        $checkResult = $this->canUserJoinLobby($userId);
        if (!$checkResult['can_join']) {
            return [
                'can_send' => false,
                'reason' => 'User is already in another lobby',
            ];
        }

        return ['can_send' => true];
    }
}
