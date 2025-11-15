<?php

namespace App\Http\Controllers;

use App\Services\AgoraService;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;

class AgoraController extends Controller
{
    private AgoraService $agoraService;

    public function __construct(AgoraService $agoraService)
    {
        $this->agoraService = $agoraService;
    }

    /**
     * Generate Agora token for a user to join video call
     * POST /api/agora/token
     */
    public function generateToken(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'session_id' => 'required|string',
            'user_id' => 'required|integer',
            'role' => 'sometimes|in:publisher,subscriber',
        ]);

        try {
            $tokenData = $this->agoraService->generateToken(
                $validated['session_id'],
                $validated['user_id'],
                $validated['role'] ?? 'publisher'
            );

            return response()->json([
                'status' => 'success',
                'data' => $tokenData,
                'message' => 'Agora token generated successfully',
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'status' => 'error',
                'message' => 'Failed to generate token: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Revoke user's access to video call
     * DELETE /api/agora/token
     */
    public function revokeToken(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'session_id' => 'required|string',
            'user_id' => 'required|integer',
        ]);

        try {
            $this->agoraService->revokeAccess(
                $validated['session_id'],
                $validated['user_id']
            );

            return response()->json([
                'status' => 'success',
                'message' => 'Access revoked successfully',
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'status' => 'error',
                'message' => 'Failed to revoke access: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Get channel info and active participants
     * GET /api/agora/channel/{sessionId}
     */
    public function getChannelInfo(string $sessionId): JsonResponse
    {
        try {
            $participants = $this->agoraService->getActiveParticipants($sessionId);

            return response()->json([
                'status' => 'success',
                'data' => [
                    'session_id' => $sessionId,
                    'participants' => $participants,
                    'participant_count' => count($participants),
                ],
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'status' => 'error',
                'message' => 'Failed to get channel info: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Update user's media status (camera/mic on/off)
     * PATCH /api/agora/media-status
     */
    public function updateMediaStatus(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'session_id' => 'required|string',
            'user_id' => 'required|integer',
            'video_enabled' => 'required|boolean',
            'audio_enabled' => 'required|boolean',
        ]);

        try {
            $this->agoraService->updateMediaStatus(
                $validated['session_id'],
                $validated['user_id'],
                $validated['video_enabled'],
                $validated['audio_enabled']
            );

            return response()->json([
                'status' => 'success',
                'message' => 'Media status updated successfully',
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'status' => 'error',
                'message' => 'Failed to update media status: ' . $e->getMessage(),
            ], 500);
        }
    }
}
