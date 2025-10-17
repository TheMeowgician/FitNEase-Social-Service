<?php

namespace App\Http\Controllers;

use App\Models\WorkoutLobby;
use App\Models\WorkoutLobbyMember;
use App\Models\WorkoutLobbyChatMessage;
use App\Models\WorkoutInvitation;
use App\Events\UserWorkoutInvitation;
use App\Events\LobbyStateChanged;
use App\Events\LobbyMessageSent;
use App\Events\MemberJoined;
use App\Events\MemberLeft;
use App\Events\MemberStatusUpdated;
use App\Events\MemberKicked;
use App\Events\LobbyDeleted;
use App\Events\InitiatorRoleTransferred;
use App\Events\WorkoutStarted;
use App\Services\AuthService;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;

class LobbyController extends Controller
{
    private AuthService $authService;

    public function __construct()
    {
        $this->authService = new AuthService();
    }

    /**
     * POST /api/social/lobby/create
     *
     * Create a new workout lobby
     */
    public function createLobby(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'group_id' => 'required|integer|min:1',
            'workout_data' => 'required|array',
            'workout_data.workout_format' => 'required|string',
            'workout_data.exercises' => 'present|array',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'status' => 'error',
                'message' => 'Validation failed',
                'errors' => $validator->errors()
            ], 422);
        }

        $userId = (int) $request->attributes->get('user_id');
        $groupId = $request->input('group_id');
        $workoutData = $request->input('workout_data');

        try {
            DB::beginTransaction();

            // VALIDATION 1: Check if user is already in another lobby
            $existingLobby = WorkoutLobby::active()
                ->whereHas('activeMembers', function($q) use ($userId) {
                    $q->where('user_id', $userId);
                })
                ->first();

            if ($existingLobby) {
                DB::rollBack();
                return response()->json([
                    'status' => 'error',
                    'message' => 'You are already in another lobby',
                    'data' => ['session_id' => $existingLobby->session_id]
                ], 409);
            }

            // Create lobby
            $sessionId = Str::uuid()->toString();
            $lobby = WorkoutLobby::create([
                'session_id' => $sessionId,
                'group_id' => $groupId,
                'initiator_id' => $userId,
                'workout_data' => $workoutData,
                'status' => 'waiting',
                'expires_at' => now()->addMinutes(config('lobby.expiry_minutes', 30)),
            ]);

            // Add initiator as first member
            $lobby->addMember($userId, 'waiting');

            // Add system message
            $userProfile = $this->authService->getUserProfile($request->bearerToken(), $userId);
            $userName = $this->getUsernameFromProfile($userProfile, $userId);
            $lobby->addSystemMessage("Lobby created by {$userName}");

            DB::commit();

            // Broadcast lobby state
            broadcast(new LobbyStateChanged($sessionId, $this->buildLobbyState($lobby)));

            Log::info('Lobby created', [
                'session_id' => $sessionId,
                'group_id' => $groupId,
                'initiator_id' => $userId,
            ]);

            return response()->json([
                'status' => 'success',
                'data' => [
                    'session_id' => $sessionId,
                    'lobby_state' => $this->buildLobbyState($lobby),
                ],
                'message' => 'Lobby created successfully'
            ]);

        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Failed to create lobby', [
                'error' => $e->getMessage(),
                'user_id' => $userId,
            ]);

            return response()->json([
                'status' => 'error',
                'message' => 'Failed to create lobby'
            ], 500);
        }
    }

    /**
     * POST /api/social/lobby/{sessionId}/join
     *
     * Join an existing lobby
     */
    public function joinLobby(Request $request, string $sessionId): JsonResponse
    {
        $userId = (int) $request->attributes->get('user_id');

        try {
            DB::beginTransaction();

            // Find lobby
            $lobby = WorkoutLobby::where('session_id', $sessionId)->first();

            if (!$lobby) {
                DB::rollBack();
                return response()->json([
                    'status' => 'error',
                    'message' => 'Lobby not found'
                ], 404);
            }

            // VALIDATION 1: Check if lobby is still active
            if (!$lobby->is_active) {
                DB::rollBack();
                return response()->json([
                    'status' => 'error',
                    'message' => 'Lobby is no longer active'
                ], 410);
            }

            // VALIDATION 2: Check if user is already a member
            if ($lobby->hasMember($userId)) {
                DB::rollBack();
                return response()->json([
                    'status' => 'error',
                    'message' => 'You are already in this lobby'
                ], 409);
            }

            // VALIDATION 3: Check if user is in another lobby
            $existingLobby = WorkoutLobby::active()
                ->where('session_id', '!=', $sessionId)
                ->whereHas('activeMembers', function($q) use ($userId) {
                    $q->where('user_id', $userId);
                })
                ->first();

            if ($existingLobby) {
                DB::rollBack();
                return response()->json([
                    'status' => 'error',
                    'message' => 'You are already in another lobby'
                ], 409);
            }

            // Add member
            $lobby->addMember($userId, 'waiting');

            // Get user info
            $userProfile = $this->authService->getUserProfile($request->bearerToken(), $userId);
            $userName = $this->getUsernameFromProfile($userProfile, $userId);

            // Add system message
            $lobby->addSystemMessage("{$userName} joined the lobby");

            DB::commit();

            // Broadcast events
            $member = ['user_id' => $userId, 'user_name' => $userName, 'status' => 'waiting'];
            broadcast(new MemberJoined($sessionId, $member, $this->buildLobbyState($lobby), 1));
            broadcast(new LobbyStateChanged($sessionId, $this->buildLobbyState($lobby)));

            Log::info('User joined lobby', [
                'session_id' => $sessionId,
                'user_id' => $userId,
            ]);

            return response()->json([
                'status' => 'success',
                'data' => [
                    'lobby_state' => $this->buildLobbyState($lobby),
                ],
                'message' => 'Joined lobby successfully'
            ]);

        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Failed to join lobby', [
                'error' => $e->getMessage(),
                'session_id' => $sessionId,
                'user_id' => $userId,
            ]);

            return response()->json([
                'status' => 'error',
                'message' => 'Failed to join lobby'
            ], 500);
        }
    }

    /**
     * POST /api/social/lobby/{sessionId}/leave
     *
     * Leave the lobby
     */
    public function leaveLobby(Request $request, string $sessionId): JsonResponse
    {
        $userId = (int) $request->attributes->get('user_id');

        try {
            DB::beginTransaction();

            $lobby = WorkoutLobby::where('session_id', $sessionId)->first();

            if (!$lobby) {
                DB::rollBack();
                return response()->json([
                    'status' => 'error',
                    'message' => 'Lobby not found'
                ], 404);
            }

            if (!$lobby->hasMember($userId)) {
                DB::rollBack();
                return response()->json([
                    'status' => 'error',
                    'message' => 'You are not in this lobby'
                ], 404);
            }

            // Get user info
            $userProfile = $this->authService->getUserProfile($request->bearerToken(), $userId);
            $userName = $this->getUsernameFromProfile($userProfile, $userId);

            // Remove member
            $lobby->removeMember($userId, 'user_left');

            // Add system message
            $lobby->addSystemMessage("{$userName} left the lobby");

            // CRITICAL: If initiator leaves, transfer role to next member
            if ($lobby->isInitiator($userId)) {
                $nextMember = $lobby->activeMembers()->first();

                if ($nextMember) {
                    $lobby->transferInitiator($nextMember->user_id);
                    $nextMemberProfile = $this->authService->getUserProfile($request->bearerToken(), $nextMember->user_id);
                    $nextMemberName = $this->getUsernameFromProfile($nextMemberProfile, $nextMember->user_id);

                    $lobby->addSystemMessage("{$nextMemberName} is now the lobby leader");

                    broadcast(new InitiatorRoleTransferred(
                        $sessionId,
                        $userId,
                        $nextMember->user_id,
                        $nextMemberName,
                        time()
                    ));
                }
            }

            // CRITICAL: Auto-complete lobby if no members left
            $lobby->autoCompleteIfEmpty();

            DB::commit();

            // Broadcast events
            broadcast(new MemberLeft($sessionId, $userId, $userName, $this->buildLobbyState($lobby), 1));

            if ($lobby->status === 'completed') {
                broadcast(new LobbyDeleted($sessionId, 'All members left', time()));
            } else {
                broadcast(new LobbyStateChanged($sessionId, $this->buildLobbyState($lobby)));
            }

            Log::info('User left lobby', [
                'session_id' => $sessionId,
                'user_id' => $userId,
            ]);

            return response()->json([
                'status' => 'success',
                'message' => 'Left lobby successfully'
            ]);

        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Failed to leave lobby', [
                'error' => $e->getMessage(),
                'session_id' => $sessionId,
                'user_id' => $userId,
            ]);

            return response()->json([
                'status' => 'error',
                'message' => 'Failed to leave lobby'
            ], 500);
        }
    }

    /**
     * POST /api/social/lobby/{sessionId}/status
     *
     * Update member status (waiting/ready)
     */
    public function updateStatus(Request $request, string $sessionId): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'status' => 'required|in:waiting,ready',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'status' => 'error',
                'message' => 'Validation failed',
                'errors' => $validator->errors()
            ], 422);
        }

        $userId = (int) $request->attributes->get('user_id');
        $newStatus = $request->input('status');

        try {
            $lobby = WorkoutLobby::where('session_id', $sessionId)->first();

            if (!$lobby) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Lobby not found'
                ], 404);
            }

            if (!$lobby->hasMember($userId)) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'You are not in this lobby'
                ], 404);
            }

            // Update status
            $lobby->updateMemberStatus($userId, $newStatus);

            // Broadcast events
            broadcast(new MemberStatusUpdated($sessionId, $userId, $newStatus, time()));
            broadcast(new LobbyStateChanged($sessionId, $this->buildLobbyState($lobby)));

            Log::info('Member status updated', [
                'session_id' => $sessionId,
                'user_id' => $userId,
                'status' => $newStatus,
            ]);

            return response()->json([
                'status' => 'success',
                'data' => [
                    'lobby_state' => $this->buildLobbyState($lobby),
                ],
                'message' => 'Status updated successfully'
            ]);

        } catch (\Exception $e) {
            Log::error('Failed to update status', [
                'error' => $e->getMessage(),
                'session_id' => $sessionId,
                'user_id' => $userId,
            ]);

            return response()->json([
                'status' => 'error',
                'message' => 'Failed to update status'
            ], 500);
        }
    }

    /**
     * POST /api/social/lobby/{sessionId}/message
     *
     * Send chat message to lobby
     */
    public function sendMessage(Request $request, string $sessionId): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'message' => 'required|string|min:1|max:' . config('lobby.max_message_length', 500),
        ]);

        if ($validator->fails()) {
            return response()->json([
                'status' => 'error',
                'message' => 'Validation failed',
                'errors' => $validator->errors()
            ], 422);
        }

        $userId = (int) $request->attributes->get('user_id');
        $message = $request->input('message');

        try {
            $lobby = WorkoutLobby::where('session_id', $sessionId)->first();

            if (!$lobby) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Lobby not found'
                ], 404);
            }

            if (!$lobby->hasMember($userId)) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'You are not in this lobby'
                ], 404);
            }

            // XSS Protection: Sanitize message
            $sanitizedMessage = htmlspecialchars($message, ENT_QUOTES | ENT_HTML5, 'UTF-8');

            // Get user info
            $userProfile = $this->authService->getUserProfile($request->bearerToken(), $userId);
            $userName = $this->getUsernameFromProfile($userProfile, $userId);

            // Save message
            $messageId = Str::uuid()->toString();
            $chatMessage = WorkoutLobbyChatMessage::create([
                'message_id' => $messageId,
                'lobby_id' => $lobby->id,
                'user_id' => $userId,
                'message' => $sanitizedMessage,
                'is_system_message' => false,
            ]);

            // Broadcast message
            broadcast(new LobbyMessageSent(
                $sessionId,
                $userId,
                $userName,
                $sanitizedMessage,
                time(),
                $messageId,
                false
            ));

            return response()->json([
                'status' => 'success',
                'data' => [
                    'message_id' => $messageId,
                    'timestamp' => $chatMessage->timestamp,
                ],
                'message' => 'Message sent successfully'
            ]);

        } catch (\Exception $e) {
            Log::error('Failed to send message', [
                'error' => $e->getMessage(),
                'session_id' => $sessionId,
                'user_id' => $userId,
            ]);

            return response()->json([
                'status' => 'error',
                'message' => 'Failed to send message'
            ], 500);
        }
    }

    /**
     * GET /api/social/lobby/{sessionId}/messages?limit=50&before=messageId
     *
     * Get chat messages with pagination
     */
    public function getChatMessages(Request $request, string $sessionId): JsonResponse
    {
        try {
            $lobby = WorkoutLobby::where('session_id', $sessionId)->first();

            if (!$lobby) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Lobby not found'
                ], 404);
            }

            $limit = min((int) $request->input('limit', 50), 100);
            $before = $request->input('before'); // Message ID to paginate before

            $query = WorkoutLobbyChatMessage::where('lobby_id', $lobby->id)
                ->orderBy('created_at', 'desc')
                ->orderBy('id', 'desc');

            if ($before) {
                $beforeMessage = WorkoutLobbyChatMessage::where('message_id', $before)
                    ->where('lobby_id', $lobby->id)
                    ->first();

                if ($beforeMessage) {
                    $query->where('id', '<', $beforeMessage->id);
                }
            }

            $messages = $query->limit($limit)->get();

            // Check if there are more messages
            $hasMore = false;
            if ($messages->count() === $limit) {
                $oldestMessageId = $messages->last()->id;
                $hasMore = WorkoutLobbyChatMessage::where('lobby_id', $lobby->id)
                    ->where('id', '<', $oldestMessageId)
                    ->exists();
            }

            $formattedMessages = $messages->map(function ($msg) {
                return [
                    'message_id' => $msg->message_id,
                    'user_id' => $msg->user_id,
                    'message' => $msg->message,
                    'timestamp' => $msg->timestamp,
                    'is_system_message' => $msg->is_system_message,
                ];
            });

            return response()->json([
                'status' => 'success',
                'data' => [
                    'messages' => $formattedMessages,
                    'has_more' => $hasMore,
                    'count' => $messages->count(),
                ],
                'message' => 'Messages retrieved successfully'
            ]);

        } catch (\Exception $e) {
            Log::error('Failed to get messages', [
                'error' => $e->getMessage(),
                'session_id' => $sessionId,
            ]);

            return response()->json([
                'status' => 'error',
                'message' => 'Failed to retrieve messages'
            ], 500);
        }
    }

    /**
     * GET /api/social/lobby/{sessionId}
     *
     * Get current lobby state
     */
    public function getLobbyState(Request $request, string $sessionId): JsonResponse
    {
        try {
            $lobby = WorkoutLobby::where('session_id', $sessionId)->first();

            if (!$lobby) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Lobby not found'
                ], 404);
            }

            return response()->json([
                'status' => 'success',
                'data' => [
                    'lobby_state' => $this->buildLobbyState($lobby),
                ],
                'message' => 'Lobby state retrieved successfully'
            ]);

        } catch (\Exception $e) {
            Log::error('Failed to get lobby state', [
                'error' => $e->getMessage(),
                'session_id' => $sessionId,
            ]);

            return response()->json([
                'status' => 'error',
                'message' => 'Failed to retrieve lobby state'
            ], 500);
        }
    }

    /**
     * POST /api/social/lobby/{sessionId}/invite
     *
     * Invite a user to the lobby (USER-SPECIFIC CHANNEL)
     */
    public function inviteMember(Request $request, string $sessionId): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'invited_user_id' => 'required|integer|min:1',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'status' => 'error',
                'message' => 'Validation failed',
                'errors' => $validator->errors()
            ], 422);
        }

        $initiatorId = (int) $request->attributes->get('user_id');
        $invitedUserId = (int) $request->input('invited_user_id');

        try {
            DB::beginTransaction();

            $lobby = WorkoutLobby::where('session_id', $sessionId)->first();

            if (!$lobby) {
                DB::rollBack();
                return response()->json([
                    'status' => 'error',
                    'message' => 'Lobby not found'
                ], 404);
            }

            // VALIDATION 1: Only initiator can invite
            if (!$lobby->isInitiator($initiatorId)) {
                DB::rollBack();
                return response()->json([
                    'status' => 'error',
                    'message' => 'Only the lobby leader can invite members'
                ], 403);
            }

            // VALIDATION 2: Check if user already has pending invitation
            $existingInvitation = WorkoutInvitation::forSession($sessionId)
                ->forUser($invitedUserId)
                ->pending()
                ->first();

            if ($existingInvitation) {
                DB::rollBack();
                return response()->json([
                    'status' => 'error',
                    'message' => 'User already has a pending invitation for this lobby'
                ], 409);
            }

            // VALIDATION 3: Check if user is already in a lobby
            $existingLobby = WorkoutLobby::active()
                ->whereHas('activeMembers', function($q) use ($invitedUserId) {
                    $q->where('user_id', $invitedUserId);
                })
                ->first();

            if ($existingLobby) {
                DB::rollBack();
                return response()->json([
                    'status' => 'error',
                    'message' => 'User is already in another lobby'
                ], 409);
            }

            // VALIDATION 4: Check if user is already a member
            if ($lobby->hasMember($invitedUserId)) {
                DB::rollBack();
                return response()->json([
                    'status' => 'error',
                    'message' => 'User is already in this lobby'
                ], 409);
            }

            // Get initiator info
            $initiatorProfile = $this->authService->getUserProfile($request->bearerToken(), $initiatorId);
            $initiatorName = $this->getUsernameFromProfile($initiatorProfile, $initiatorId);

            // Create invitation record
            $invitationId = Str::uuid()->toString();
            $invitation = WorkoutInvitation::create([
                'invitation_id' => $invitationId,
                'session_id' => $sessionId,
                'group_id' => $lobby->group_id,
                'initiator_id' => $initiatorId,
                'invited_user_id' => $invitedUserId,
                'workout_data' => $lobby->workout_data,
                'sent_at' => now(),
                'expires_at' => now()->addMinutes(config('lobby.invitation_expiry_minutes', 5)),
            ]);

            DB::commit();

            // Broadcast invitation to USER-SPECIFIC channel
            broadcast(new UserWorkoutInvitation(
                $invitationId,
                $sessionId,
                $lobby->group_id,
                $initiatorId,
                $initiatorName,
                $invitedUserId,
                $lobby->workout_data,
                $invitation->expires_at->timestamp
            ));

            Log::info('Invitation sent', [
                'invitation_id' => $invitationId,
                'session_id' => $sessionId,
                'initiator_id' => $initiatorId,
                'invited_user_id' => $invitedUserId,
            ]);

            return response()->json([
                'status' => 'success',
                'data' => [
                    'invitation_id' => $invitationId,
                    'expires_at' => $invitation->expires_at->timestamp,
                ],
                'message' => 'Invitation sent successfully'
            ]);

        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Failed to send invitation', [
                'error' => $e->getMessage(),
                'session_id' => $sessionId,
                'initiator_id' => $initiatorId,
                'invited_user_id' => $invitedUserId,
            ]);

            return response()->json([
                'status' => 'error',
                'message' => 'Failed to send invitation'
            ], 500);
        }
    }

    /**
     * POST /api/social/invitations/{invitationId}/accept
     *
     * Accept a workout invitation
     */
    public function acceptInvitation(Request $request, string $invitationId): JsonResponse
    {
        $userId = (int) $request->attributes->get('user_id');

        try {
            DB::beginTransaction();

            $invitation = WorkoutInvitation::where('invitation_id', $invitationId)
                ->where('invited_user_id', $userId)
                ->first();

            if (!$invitation) {
                DB::rollBack();
                return response()->json([
                    'status' => 'error',
                    'message' => 'Invitation not found'
                ], 404);
            }

            if (!$invitation->is_pending) {
                DB::rollBack();
                return response()->json([
                    'status' => 'error',
                    'message' => 'Invitation is no longer valid'
                ], 410);
            }

            // Mark invitation as accepted
            $invitation->accept();

            // Join the lobby
            $lobby = WorkoutLobby::where('session_id', $invitation->session_id)->first();

            if (!$lobby || !$lobby->is_active) {
                DB::rollBack();
                return response()->json([
                    'status' => 'error',
                    'message' => 'Lobby is no longer active'
                ], 410);
            }

            // Add member
            $lobby->addMember($userId, 'waiting');

            // Get user info
            $userProfile = $this->authService->getUserProfile($request->bearerToken(), $userId);
            $userName = $this->getUsernameFromProfile($userProfile, $userId);

            // Add system message
            $lobby->addSystemMessage("{$userName} joined via invitation");

            DB::commit();

            // Broadcast events
            $member = ['user_id' => $userId, 'user_name' => $userName, 'status' => 'waiting'];
            broadcast(new MemberJoined($invitation->session_id, $member, $this->buildLobbyState($lobby), 1));
            broadcast(new LobbyStateChanged($invitation->session_id, $this->buildLobbyState($lobby)));

            Log::info('Invitation accepted', [
                'invitation_id' => $invitationId,
                'user_id' => $userId,
                'session_id' => $invitation->session_id,
            ]);

            return response()->json([
                'status' => 'success',
                'data' => [
                    'session_id' => $invitation->session_id,
                    'lobby_state' => $this->buildLobbyState($lobby),
                ],
                'message' => 'Invitation accepted'
            ]);

        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Failed to accept invitation', [
                'error' => $e->getMessage(),
                'invitation_id' => $invitationId,
                'user_id' => $userId,
            ]);

            return response()->json([
                'status' => 'error',
                'message' => 'Failed to accept invitation'
            ], 500);
        }
    }

    /**
     * POST /api/social/invitations/{invitationId}/decline
     *
     * Decline a workout invitation
     */
    public function declineInvitation(Request $request, string $invitationId): JsonResponse
    {
        $userId = (int) $request->attributes->get('user_id');

        try {
            $invitation = WorkoutInvitation::where('invitation_id', $invitationId)
                ->where('invited_user_id', $userId)
                ->first();

            if (!$invitation) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Invitation not found'
                ], 404);
            }

            if (!$invitation->is_pending) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Invitation is no longer valid'
                ], 410);
            }

            $invitation->decline('user_declined');

            Log::info('Invitation declined', [
                'invitation_id' => $invitationId,
                'user_id' => $userId,
            ]);

            return response()->json([
                'status' => 'success',
                'message' => 'Invitation declined'
            ]);

        } catch (\Exception $e) {
            Log::error('Failed to decline invitation', [
                'error' => $e->getMessage(),
                'invitation_id' => $invitationId,
                'user_id' => $userId,
            ]);

            return response()->json([
                'status' => 'error',
                'message' => 'Failed to decline invitation'
            ], 500);
        }
    }

    /**
     * GET /api/social/invitations/pending
     *
     * Get user's pending invitations
     */
    public function getPendingInvitations(Request $request): JsonResponse
    {
        $userId = (int) $request->attributes->get('user_id');

        try {
            $invitations = WorkoutInvitation::forUser($userId)
                ->pending()
                ->orderBy('sent_at', 'desc')
                ->get();

            $formattedInvitations = $invitations->map(function ($inv) use ($request) {
                // Get initiator name
                $initiator = $this->authService->getUserProfile($request->bearerToken(), $inv->initiator_id);
                $initiatorName = $this->getUsernameFromProfile($initiator, $inv->initiator_id);

                return [
                    'invitation_id' => $inv->invitation_id,
                    'session_id' => $inv->session_id,
                    'group_id' => $inv->group_id,
                    'initiator_id' => $inv->initiator_id,
                    'initiator_name' => $initiatorName,
                    'workout_data' => $inv->workout_data,
                    'expires_at' => $inv->expires_at->timestamp,
                    'time_remaining' => $inv->time_remaining,
                ];
            });

            return response()->json([
                'status' => 'success',
                'data' => [
                    'invitations' => $formattedInvitations,
                    'count' => $formattedInvitations->count(),
                ],
                'message' => 'Pending invitations retrieved successfully'
            ]);

        } catch (\Exception $e) {
            Log::error('Failed to get pending invitations', [
                'error' => $e->getMessage(),
                'user_id' => $userId,
            ]);

            return response()->json([
                'status' => 'error',
                'message' => 'Failed to retrieve invitations'
            ], 500);
        }
    }

    /**
     * POST /api/social/lobby/{sessionId}/kick
     *
     * Kick a member from the lobby (initiator only)
     */
    public function kickMember(Request $request, string $sessionId): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'kicked_user_id' => 'required|integer|min:1',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'status' => 'error',
                'message' => 'Validation failed',
                'errors' => $validator->errors()
            ], 422);
        }

        $initiatorId = (int) $request->attributes->get('user_id');
        $kickedUserId = (int) $request->input('kicked_user_id');

        try {
            DB::beginTransaction();

            $lobby = WorkoutLobby::where('session_id', $sessionId)->first();

            if (!$lobby) {
                DB::rollBack();
                return response()->json([
                    'status' => 'error',
                    'message' => 'Lobby not found'
                ], 404);
            }

            // VALIDATION 1: Only initiator can kick
            if (!$lobby->isInitiator($initiatorId)) {
                DB::rollBack();
                return response()->json([
                    'status' => 'error',
                    'message' => 'Only the lobby leader can kick members'
                ], 403);
            }

            // VALIDATION 2: Cannot kick yourself
            if ($kickedUserId === $initiatorId) {
                DB::rollBack();
                return response()->json([
                    'status' => 'error',
                    'message' => 'You cannot kick yourself'
                ], 400);
            }

            // VALIDATION 3: User must be in lobby
            if (!$lobby->hasMember($kickedUserId)) {
                DB::rollBack();
                return response()->json([
                    'status' => 'error',
                    'message' => 'User is not in this lobby'
                ], 404);
            }

            // Get kicked user info
            $kickedUserProfile = $this->authService->getUserProfile($request->bearerToken(), $kickedUserId);
            $kickedUserName = $this->getUsernameFromProfile($kickedUserProfile, $kickedUserId);

            // Remove member
            $lobby->removeMember($kickedUserId, 'kicked');

            // Add system message
            $lobby->addSystemMessage("{$kickedUserName} was removed from the lobby");

            DB::commit();

            // Broadcast to kicked user's personal channel
            broadcast(new MemberKicked($sessionId, $kickedUserId, time()));

            // Broadcast to lobby
            broadcast(new MemberLeft($sessionId, $kickedUserId, $kickedUserName, $this->buildLobbyState($lobby), 1));
            broadcast(new LobbyStateChanged($sessionId, $this->buildLobbyState($lobby)));

            Log::info('Member kicked', [
                'session_id' => $sessionId,
                'initiator_id' => $initiatorId,
                'kicked_user_id' => $kickedUserId,
            ]);

            return response()->json([
                'status' => 'success',
                'message' => 'Member removed successfully'
            ]);

        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Failed to kick member', [
                'error' => $e->getMessage(),
                'session_id' => $sessionId,
                'initiator_id' => $initiatorId,
                'kicked_user_id' => $kickedUserId,
            ]);

            return response()->json([
                'status' => 'error',
                'message' => 'Failed to remove member'
            ], 500);
        }
    }

    /**
     * POST /api/social/lobby/{sessionId}/start
     *
     * Start the workout (initiator only, all members must be ready)
     */
    public function startWorkout(Request $request, string $sessionId): JsonResponse
    {
        $initiatorId = (int) $request->attributes->get('user_id');

        try {
            DB::beginTransaction();

            $lobby = WorkoutLobby::where('session_id', $sessionId)->first();

            if (!$lobby) {
                DB::rollBack();
                return response()->json([
                    'status' => 'error',
                    'message' => 'Lobby not found'
                ], 404);
            }

            // VALIDATION 1: Only initiator can start
            if (!$lobby->isInitiator($initiatorId)) {
                DB::rollBack();
                return response()->json([
                    'status' => 'error',
                    'message' => 'Only the lobby leader can start the workout'
                ], 403);
            }

            // VALIDATION 2: All members must be ready
            if (!$lobby->are_all_members_ready) {
                DB::rollBack();
                return response()->json([
                    'status' => 'error',
                    'message' => 'All members must be ready before starting'
                ], 400);
            }

            // Mark lobby as started
            $lobby->markAsStarted();

            DB::commit();

            // Broadcast workout start
            broadcast(new WorkoutStarted($sessionId, time()));

            Log::info('Workout started', [
                'session_id' => $sessionId,
                'initiator_id' => $initiatorId,
            ]);

            return response()->json([
                'status' => 'success',
                'data' => [
                    'start_time' => time(),
                ],
                'message' => 'Workout started successfully'
            ]);

        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Failed to start workout', [
                'error' => $e->getMessage(),
                'session_id' => $sessionId,
                'initiator_id' => $initiatorId,
            ]);

            return response()->json([
                'status' => 'error',
                'message' => 'Failed to start workout'
            ], 500);
        }
    }

    /**
     * Build lobby state array for broadcasting
     */
    private function buildLobbyState(WorkoutLobby $lobby): array
    {
        $members = $lobby->activeMembers()->get()->map(function ($member) use ($lobby) {
            $userProfile = $this->authService->getUserProfile(null, $member->user_id);
            $userName = $this->getUsernameFromProfile($userProfile, $member->user_id);

            return [
                'user_id' => $member->user_id,
                'user_name' => $userName,
                'status' => $member->status,
                'joined_at' => $member->joined_at->timestamp,
            ];
        });

        return [
            'session_id' => $lobby->session_id,
            'group_id' => $lobby->group_id,
            'initiator_id' => $lobby->initiator_id,
            'status' => $lobby->status,
            'workout_data' => $lobby->workout_data,
            'members' => $members,
            'member_count' => $members->count(),
            'created_at' => $lobby->created_at->timestamp,
            'expires_at' => $lobby->expires_at->timestamp,
            'is_expired' => $lobby->is_expired,
        ];
    }

    /**
     * Extract username from user profile
     */
    private function getUsernameFromProfile(?array $userProfile, int $userId): string
    {
        if (!$userProfile) {
            return 'User ' . $userId;
        }

        return $userProfile['username'] ?? $userProfile['email'] ?? 'User ' . $userId;
    }
}
