<?php

namespace App\Http\Controllers;

use App\Models\WorkoutLobby;
use App\Models\WorkoutLobbyMember;
use App\Models\WorkoutLobbyChatMessage;
use App\Models\WorkoutInvitation;
use App\Models\WorkoutSession;
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

            // ENHANCED PROACTIVE CLEANUP: Clean up ANY active lobby memberships for this user
            // This is more aggressive - removes user from ALL active lobbies, not just abandoned ones
            Log::info('[LOBBY CREATE] Starting proactive cleanup for user', ['user_id' => $userId]);

            // Find ALL lobbies where user is an active member
            $userActiveLobbies = WorkoutLobby::active()
                ->whereHas('activeMembers', function($q) use ($userId) {
                    $q->where('user_id', $userId);
                })
                ->get();

            if ($userActiveLobbies->count() > 0) {
                Log::warning('[LOBBY CREATE] Found existing active lobbies, cleaning up...', [
                    'user_id' => $userId,
                    'lobby_count' => $userActiveLobbies->count(),
                    'session_ids' => $userActiveLobbies->pluck('session_id')->toArray()
                ]);

                foreach ($userActiveLobbies as $existingLobby) {
                    Log::info('[LOBBY CREATE] Removing user from existing lobby', [
                        'user_id' => $userId,
                        'session_id' => $existingLobby->session_id,
                        'is_initiator' => $existingLobby->isInitiator($userId)
                    ]);

                    // Mark member as inactive and left
                    $existingLobby->members()->where('user_id', $userId)->update([
                        'is_active' => false,
                        'status' => 'left',
                        'left_at' => now(),
                        'left_reason' => 'Auto-cleanup: Creating new lobby'
                    ]);

                    // If user was initiator, transfer role or delete lobby
                    if ($existingLobby->isInitiator($userId)) {
                        $nextMember = $existingLobby->activeMembers()->where('user_id', '!=', $userId)->first();

                        if ($nextMember) {
                            // Transfer initiator role
                            $existingLobby->transferInitiator($nextMember->user_id);
                            Log::info('[LOBBY CREATE] Transferred initiator role', [
                                'old_initiator' => $userId,
                                'new_initiator' => $nextMember->user_id,
                                'session_id' => $existingLobby->session_id
                            ]);

                            // Broadcast initiator transfer
                            $nextMemberProfile = $this->authService->getUserProfile($request->bearerToken(), $nextMember->user_id);
                            $nextMemberName = $this->getUsernameFromProfile($nextMemberProfile, $nextMember->user_id);
                            $existingLobby->addSystemMessage("{$nextMemberName} is now the lobby leader (auto-transfer)");

                            $existingLobby->refresh();
                            $lobbyState = $this->buildLobbyState($existingLobby, $request->bearerToken());

                            broadcast(new InitiatorRoleTransferred(
                                $existingLobby->session_id,
                                $userId,
                                $nextMember->user_id,
                                $nextMemberName,
                                $lobbyState,
                                $existingLobby->version ?? 1
                            ))->toOthers();
                        } else {
                            // No other members, delete the lobby
                            $existingLobby->update(['status' => 'cancelled']);
                            Log::info('[LOBBY CREATE] Deleted empty lobby', [
                                'session_id' => $existingLobby->session_id
                            ]);

                            broadcast(new LobbyDeleted(
                                $existingLobby->session_id,
                                'Lobby cancelled - initiator left',
                                time()
                            ))->toOthers();
                        }
                    }

                    // Broadcast member left event if lobby still exists
                    $existingLobby->refresh();
                    if ($existingLobby->status === 'waiting') {
                        $userProfile = $this->authService->getUserProfile($request->bearerToken(), $userId);
                        $userName = $this->getUsernameFromProfile($userProfile, $userId);

                        // Build lobby state for events
                        $lobbyState = $this->buildLobbyState($existingLobby, $request->bearerToken());

                        broadcast(new MemberLeft(
                            $existingLobby->session_id,
                            $userId,
                            $userName,
                            $lobbyState,
                            $existingLobby->version ?? 1
                        ))->toOthers();

                        // Broadcast updated lobby state
                        broadcast(new LobbyStateChanged(
                            $existingLobby->session_id,
                            $lobbyState
                        ))->toOthers();
                    }
                }

                Log::info('[LOBBY CREATE] Proactive cleanup completed', [
                    'user_id' => $userId,
                    'lobbies_cleaned' => $userActiveLobbies->count()
                ]);
            }

            // VALIDATION: Check if user is STILL in an active lobby after cleanup
            // This should NOT happen if cleanup worked properly
            $existingLobby = WorkoutLobby::active()
                ->whereHas('activeMembers', function($q) use ($userId) {
                    $q->where('user_id', $userId);
                })
                ->first();

            if ($existingLobby) {
                // Get member details for debugging
                $memberEntry = $existingLobby->activeMembers()->where('user_id', $userId)->first();

                Log::error('[LOBBY CREATE] CRITICAL: User STILL in active lobby after cleanup!', [
                    'user_id' => $userId,
                    'existing_session_id' => $existingLobby->session_id,
                    'existing_lobby_status' => $existingLobby->status,
                    'member_status' => $memberEntry?->status ?? 'unknown',
                    'member_joined_at' => $memberEntry?->joined_at ?? null,
                    'lobby_created_at' => $existingLobby->created_at,
                    'lobby_expires_at' => $existingLobby->expires_at,
                    'cleanup_failed' => 'Auto-cleanup did not remove user from lobby'
                ]);

                DB::rollBack();
                return response()->json([
                    'status' => 'error',
                    'message' => 'You are already in another lobby. Auto-cleanup failed. Please try calling force-leave-all endpoint first.',
                    'data' => [
                        'session_id' => $existingLobby->session_id,
                        'cleanup_attempted' => true,
                        'suggestion' => 'Call POST /api/social/v2/lobby/force-leave-all then try again'
                    ]
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
                'expires_at' => now()->addMinutes((int) config('lobby.expiry_minutes', 30)),
            ]);

            // Get user info first (we need it for both addMember and system message)
            $userProfile = $this->authService->getUserProfile($request->bearerToken(), $userId);
            $userName = $this->getUsernameFromProfile($userProfile, $userId);

            // Add initiator as first member (cache username for instant pause/resume)
            $lobby->addMember($userId, 'waiting', $userName);

            // Add system message
            $lobby->addSystemMessage("Lobby created by {$userName}");

            DB::commit();

            // CRITICAL: Refresh lobby to get latest member data after commit
            // Without this, buildLobbyState() may use cached relationship data
            $lobby->refresh();

            // CRITICAL: Clear relationship cache to ensure fresh data
            $lobby->unsetRelation('members');

            // Broadcast lobby state
            broadcast(new LobbyStateChanged($sessionId, $this->buildLobbyState($lobby, $request->bearerToken())));

            Log::info('Lobby created', [
                'session_id' => $sessionId,
                'group_id' => $groupId,
                'initiator_id' => $userId,
            ]);

            return response()->json([
                'status' => 'success',
                'data' => [
                    'session_id' => $sessionId,
                    'lobby_state' => $this->buildLobbyState($lobby, $request->bearerToken()),
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

            // Get user info first
            $userProfile = $this->authService->getUserProfile($request->bearerToken(), $userId);
            $userName = $this->getUsernameFromProfile($userProfile, $userId);

            // Add member (cache username for instant pause/resume)
            $lobby->addMember($userId, 'waiting', $userName);

            // Add system message
            $lobby->addSystemMessage("{$userName} joined the lobby");

            DB::commit();

            // CRITICAL: Refresh lobby to get latest member data after commit
            // Without this, buildLobbyState() may use cached relationship data
            $lobby->refresh();

            // CRITICAL: Clear relationship cache to ensure fresh data
            $lobby->unsetRelation('members');

            // Broadcast events
            $member = ['user_id' => $userId, 'user_name' => $userName, 'status' => 'waiting'];
            broadcast(new MemberJoined($sessionId, $member, $this->buildLobbyState($lobby, $request->bearerToken()), 1));
            broadcast(new LobbyStateChanged($sessionId, $this->buildLobbyState($lobby, $request->bearerToken())));

            Log::info('User joined lobby', [
                'session_id' => $sessionId,
                'user_id' => $userId,
            ]);

            return response()->json([
                'status' => 'success',
                'data' => [
                    'lobby_state' => $this->buildLobbyState($lobby, $request->bearerToken()),
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

            // CRITICAL: Clear relationship cache to prevent stale data
            // Laravel caches relationships in memory - refresh() doesn't clear them!
            // Without this, activeMembers() will still return the removed member
            $lobby->unsetRelation('members');

            // CRITICAL: Cancel ALL pending invitations involving this user for this session
            // This includes both:
            // 1. Invitations sent TO this user (invited_user_id = $userId)
            // 2. Invitations sent BY this user (initiator_id = $userId)
            WorkoutInvitation::forSession($sessionId)
                ->where('status', 'pending')
                ->where(function($query) use ($userId) {
                    $query->where('invited_user_id', $userId)
                          ->orWhere('initiator_id', $userId);
                })
                ->update([
                    'status' => 'cancelled',
                    'response_reason' => 'User left the lobby',
                    'responded_at' => now()
                ]);

            Log::info('[LEAVE LOBBY] Cancelled all pending invitations for user', [
                'session_id' => $sessionId,
                'user_id' => $userId,
            ]);

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

                    // Reload lobby to get updated state with new initiator
                    $lobby->refresh();

                    broadcast(new InitiatorRoleTransferred(
                        $sessionId,
                        $userId,
                        $nextMember->user_id,
                        $nextMemberName,
                        $this->buildLobbyState($lobby, $request->bearerToken()),
                        1
                    ));
                }
            }

            // CRITICAL: Auto-complete lobby if no members left
            $lobby->autoCompleteIfEmpty();

            DB::commit();

            // CRITICAL: Refresh lobby to get latest member data after commit
            $lobby->refresh();

            // CRITICAL: Clear relationship cache again before broadcasting
            // Ensures buildLobbyState() gets fresh member data from database
            $lobby->unsetRelation('members');

            // Broadcast events
            broadcast(new MemberLeft($sessionId, $userId, $userName, $this->buildLobbyState($lobby, $request->bearerToken()), 1));

            if ($lobby->status === 'completed') {
                broadcast(new LobbyDeleted($sessionId, 'All members left', time()));
            } else {
                broadcast(new LobbyStateChanged($sessionId, $this->buildLobbyState($lobby, $request->bearerToken())));
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

            // Get old status before updating
            $member = $lobby->activeMembers()->where('user_id', $userId)->first();
            $oldStatus = $member ? $member->status : 'waiting';

            // Update status
            $lobby->updateMemberStatus($userId, $newStatus);

            // Reload lobby to get fresh state
            $lobby->refresh();

            // CRITICAL: Clear relationship cache to ensure fresh data
            $lobby->unsetRelation('members');

            // Broadcast events
            $lobbyState = $this->buildLobbyState($lobby, $request->bearerToken());
            broadcast(new MemberStatusUpdated($sessionId, $userId, $oldStatus, $newStatus, $lobbyState, 1));
            broadcast(new LobbyStateChanged($sessionId, $lobbyState));

            Log::info('Member status updated', [
                'session_id' => $sessionId,
                'user_id' => $userId,
                'status' => $newStatus,
            ]);

            return response()->json([
                'status' => 'success',
                'data' => [
                    'lobby_state' => $this->buildLobbyState($lobby, $request->bearerToken()),
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
     * POST /api/social/v2/lobby/{sessionId}/workout-data
     *
     * Update workout data (exercises) for the lobby
     * Only initiator can update workout data
     */
    public function updateWorkoutData(Request $request, string $sessionId): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'workout_data' => 'required|array',
            'workout_data.workout_format' => 'sometimes|string',
            'workout_data.exercises' => 'sometimes|array',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'status' => 'error',
                'message' => 'Validation failed',
                'errors' => $validator->errors()
            ], 422);
        }

        $userId = (int) $request->attributes->get('user_id');
        $workoutData = $request->input('workout_data');

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

            // Only initiator can update workout data
            if (!$lobby->isInitiator($userId)) {
                DB::rollBack();
                return response()->json([
                    'status' => 'error',
                    'message' => 'Only the lobby initiator can update workout data'
                ], 403);
            }

            // Update workout data
            $lobby->update(['workout_data' => $workoutData]);
            $lobby->refresh();

            DB::commit();

            // Broadcast lobby state change
            $lobbyState = $this->buildLobbyState($lobby, $request->bearerToken());
            broadcast(new LobbyStateChanged($sessionId, $lobbyState));

            Log::info('[WORKOUT DATA] Workout data updated', [
                'session_id' => $sessionId,
                'user_id' => $userId,
                'exercise_count' => count($workoutData['exercises'] ?? []),
            ]);

            return response()->json([
                'status' => 'success',
                'data' => [
                    'lobby_state' => $lobbyState,
                    'version' => 1
                ],
                'message' => 'Workout data updated successfully'
            ]);

        } catch (\Exception $e) {
            DB::rollBack();

            Log::error('[WORKOUT DATA] Failed to update workout data', [
                'error' => $e->getMessage(),
                'session_id' => $sessionId,
                'user_id' => $userId,
                'trace' => $e->getTraceAsString()
            ]);

            return response()->json([
                'status' => 'error',
                'message' => 'Failed to update workout data: ' . $e->getMessage()
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
                    'lobby_state' => $this->buildLobbyState($lobby, $request->bearerToken()),
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
            // ENHANCED: Proactively clean up any stale lobby memberships for invited user
            $existingLobby = WorkoutLobby::active()
                ->whereHas('activeMembers', function($q) use ($invitedUserId) {
                    $q->where('user_id', $invitedUserId);
                })
                ->first();

            if ($existingLobby) {
                // AUTO-CLEANUP: Remove invited user from stale lobby
                Log::info('[INVITE] Found invited user in another lobby, cleaning up', [
                    'invited_user_id' => $invitedUserId,
                    'existing_session_id' => $existingLobby->session_id,
                    'new_session_id' => $sessionId,
                ]);

                // Mark member as inactive
                $existingLobby->members()->where('user_id', $invitedUserId)->update([
                    'is_active' => false,
                    'status' => 'left',
                    'left_at' => now(),
                    'left_reason' => 'Auto-cleanup: Invited to new lobby'
                ]);

                // Get invited user info for events
                $invitedUserProfile = $this->authService->getUserProfile($request->bearerToken(), $invitedUserId);
                $invitedUserName = $this->getUsernameFromProfile($invitedUserProfile, $invitedUserId);

                // Handle initiator transfer or lobby deletion if needed
                if ($existingLobby->isInitiator($invitedUserId)) {
                    // Find next member to transfer initiator role
                    $nextMember = $existingLobby->activeMembers()->where('user_id', '!=', $invitedUserId)->first();

                    if ($nextMember) {
                        // Transfer initiator role
                        $existingLobby->transferInitiator($nextMember->user_id);

                        $nextMemberProfile = $this->authService->getUserProfile($request->bearerToken(), $nextMember->user_id);
                        $nextMemberName = $this->getUsernameFromProfile($nextMemberProfile, $nextMember->user_id);

                        $lobbyState = $this->buildLobbyState($existingLobby, $request->bearerToken());

                        broadcast(new InitiatorRoleTransferred(
                            $existingLobby->session_id,
                            $invitedUserId,
                            $nextMember->user_id,
                            $nextMemberName,
                            $lobbyState,
                            $existingLobby->version ?? 1
                        ))->toOthers();

                        Log::info('[INVITE] Transferred initiator role', [
                            'old_initiator_id' => $invitedUserId,
                            'new_initiator_id' => $nextMember->user_id,
                        ]);
                    } else {
                        // No other members, delete the lobby
                        $existingLobby->update(['status' => 'cancelled']);

                        broadcast(new LobbyDeleted(
                            $existingLobby->session_id,
                            'Lobby cancelled - last member invited elsewhere',
                            time()
                        ))->toOthers();

                        Log::info('[INVITE] Deleted empty lobby', [
                            'session_id' => $existingLobby->session_id,
                        ]);
                    }
                }

                // Broadcast member left event if lobby still active
                if ($existingLobby->status === 'waiting') {
                    $lobbyState = $this->buildLobbyState($existingLobby, $request->bearerToken());

                    broadcast(new MemberLeft(
                        $existingLobby->session_id,
                        $invitedUserId,
                        $invitedUserName,
                        $lobbyState,
                        $existingLobby->version ?? 1
                    ))->toOthers();

                    broadcast(new LobbyStateChanged(
                        $existingLobby->session_id,
                        $lobbyState
                    ))->toOthers();
                }

                Log::info('[INVITE] Cleanup completed, proceeding with invitation');
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
                'expires_at' => now()->addMinutes((int) config('lobby.invitation_expiry_minutes', 5)),
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
                Log::warning('Invitation not found', [
                    'invitation_id' => $invitationId,
                    'user_id' => $userId,
                ]);
                return response()->json([
                    'status' => 'error',
                    'message' => 'Invitation not found'
                ], 404);
            }

            if (!$invitation->is_pending) {
                DB::rollBack();
                Log::warning('Invitation not pending', [
                    'invitation_id' => $invitationId,
                    'user_id' => $userId,
                    'status' => $invitation->status,
                ]);
                return response()->json([
                    'status' => 'error',
                    'message' => 'Invitation is no longer valid'
                ], 410);
            }

            // Join the lobby
            $lobby = WorkoutLobby::where('session_id', $invitation->session_id)->first();

            if (!$lobby || !$lobby->is_active) {
                DB::rollBack();
                Log::warning('Lobby not active', [
                    'invitation_id' => $invitationId,
                    'session_id' => $invitation->session_id,
                    'user_id' => $userId,
                    'lobby_exists' => !!$lobby,
                    'lobby_status' => $lobby ? $lobby->status : null,
                ]);
                return response()->json([
                    'status' => 'error',
                    'message' => 'Lobby is no longer active'
                ], 410);
            }

            // CRITICAL: Check if user is already in another active lobby
            $existingLobby = WorkoutLobby::active()
                ->where('session_id', '!=', $invitation->session_id)
                ->whereHas('activeMembers', function($q) use ($userId) {
                    $q->where('user_id', $userId);
                })
                ->first();

            if ($existingLobby) {
                DB::rollBack();
                Log::warning('User already in another lobby', [
                    'invitation_id' => $invitationId,
                    'user_id' => $userId,
                    'existing_session_id' => $existingLobby->session_id,
                    'target_session_id' => $invitation->session_id,
                ]);
                return response()->json([
                    'status' => 'error',
                    'message' => 'You are already in another lobby. Please leave it first.',
                    'data' => ['existing_session_id' => $existingLobby->session_id]
                ], 409);
            }

            // Mark invitation as accepted
            $invitation->accept();

            // Get user info first
            $userProfile = $this->authService->getUserProfile($request->bearerToken(), $userId);
            $userName = $this->getUsernameFromProfile($userProfile, $userId);

            // Add member (handles both new joins and rejoins after leaving, cache username)
            $lobby->addMember($userId, 'waiting', $userName);

            // Add system message
            $lobby->addSystemMessage("{$userName} joined via invitation");

            DB::commit();

            // CRITICAL: Refresh lobby to get latest member data after commit
            // Without this, buildLobbyState() may use cached relationship data
            $lobby->refresh();

            // CRITICAL: Clear relationship cache to ensure fresh data
            $lobby->unsetRelation('members');

            // Broadcast events
            $member = ['user_id' => $userId, 'user_name' => $userName, 'status' => 'waiting'];
            broadcast(new MemberJoined($invitation->session_id, $member, $this->buildLobbyState($lobby, $request->bearerToken()), 1));
            broadcast(new LobbyStateChanged($invitation->session_id, $this->buildLobbyState($lobby, $request->bearerToken())));

            Log::info('Invitation accepted', [
                'invitation_id' => $invitationId,
                'user_id' => $userId,
                'session_id' => $invitation->session_id,
            ]);

            return response()->json([
                'status' => 'success',
                'data' => [
                    'session_id' => $invitation->session_id,
                    'lobby_state' => $this->buildLobbyState($lobby, $request->bearerToken()),
                ],
                'message' => 'Invitation accepted'
            ]);

        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Failed to accept invitation', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
                'invitation_id' => $invitationId,
                'user_id' => $userId,
            ]);

            return response()->json([
                'status' => 'error',
                'message' => 'Failed to accept invitation: ' . $e->getMessage()
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

            // CRITICAL: Clear relationship cache to prevent stale data
            $lobby->unsetRelation('members');

            // CRITICAL: Cancel any pending invitations for this user in this lobby
            // This prevents "duplicate invitation" errors when re-inviting after kick
            $cancelledCount = WorkoutInvitation::forSession($sessionId)
                ->forUser($kickedUserId)
                ->where('status', 'pending')
                ->update([
                    'status' => 'cancelled',
                    'response_reason' => 'User was removed from lobby',
                    'responded_at' => now()
                ]);

            if ($cancelledCount > 0) {
                Log::info('Cancelled pending invitations after kick', [
                    'session_id' => $sessionId,
                    'kicked_user_id' => $kickedUserId,
                    'cancelled_count' => $cancelledCount
                ]);
            }

            // Add system message
            $lobby->addSystemMessage("{$kickedUserName} was removed from the lobby");

            DB::commit();

            // CRITICAL: Refresh lobby to get latest member data after commit
            $lobby->refresh();

            // CRITICAL: Clear relationship cache to ensure fresh data
            $lobby->unsetRelation('members');

            // Broadcast to kicked user's personal channel
            broadcast(new MemberKicked($sessionId, $kickedUserId, time()));

            // Broadcast to lobby
            broadcast(new MemberLeft($sessionId, $kickedUserId, $kickedUserName, $this->buildLobbyState($lobby, $request->bearerToken()), 1));
            broadcast(new LobbyStateChanged($sessionId, $this->buildLobbyState($lobby, $request->bearerToken())));

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
     * POST /api/social/v2/lobby/{sessionId}/transfer-initiator
     *
     * Transfer initiator role to another member (initiator only)
     */
    public function transferInitiatorRole(Request $request, string $sessionId): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'new_initiator_id' => 'required|integer|min:1',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'status' => 'error',
                'message' => 'Validation failed',
                'errors' => $validator->errors()
            ], 422);
        }

        $currentInitiatorId = (int) $request->attributes->get('user_id');
        $newInitiatorId = (int) $request->input('new_initiator_id');

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

            // VALIDATION 1: Only current initiator can transfer role
            if (!$lobby->isInitiator($currentInitiatorId)) {
                DB::rollBack();
                return response()->json([
                    'status' => 'error',
                    'message' => 'Only the lobby leader can transfer the role'
                ], 403);
            }

            // VALIDATION 2: Cannot transfer to yourself
            if ($newInitiatorId === $currentInitiatorId) {
                DB::rollBack();
                return response()->json([
                    'status' => 'error',
                    'message' => 'You are already the lobby leader'
                ], 400);
            }

            // VALIDATION 3: New initiator must be in lobby
            if (!$lobby->hasMember($newInitiatorId)) {
                DB::rollBack();
                return response()->json([
                    'status' => 'error',
                    'message' => 'New leader must be a member of this lobby'
                ], 404);
            }

            // Get user names
            $oldInitiatorProfile = $this->authService->getUserProfile($request->bearerToken(), $currentInitiatorId);
            $oldInitiatorName = $this->getUsernameFromProfile($oldInitiatorProfile, $currentInitiatorId);

            $newInitiatorProfile = $this->authService->getUserProfile($request->bearerToken(), $newInitiatorId);
            $newInitiatorName = $this->getUsernameFromProfile($newInitiatorProfile, $newInitiatorId);

            // Transfer initiator role
            $lobby->transferInitiator($newInitiatorId);

            // Add system message
            $lobby->addSystemMessage("{$newInitiatorName} is now the lobby leader");

            DB::commit();

            // CRITICAL: Refresh lobby to get latest data after commit
            $lobby->refresh();

            // Build updated lobby state
            $lobbyState = $this->buildLobbyState($lobby, $request->bearerToken());

            // Broadcast role transfer event
            broadcast(new InitiatorRoleTransferred(
                $sessionId,              // sessionId (string)
                $currentInitiatorId,     // oldInitiatorId (int)
                $newInitiatorId,         // newInitiatorId (int)
                $newInitiatorName,       // newInitiatorName (string)
                $lobbyState,             // lobbyState (array)
                $lobby->version ?? 1     // version (int)
            ));

            // Also broadcast general state change
            broadcast(new LobbyStateChanged($sessionId, $lobbyState));

            Log::info('Initiator role transferred', [
                'session_id' => $sessionId,
                'old_initiator_id' => $currentInitiatorId,
                'new_initiator_id' => $newInitiatorId,
            ]);

            return response()->json([
                'status' => 'success',
                'message' => 'Leader role transferred successfully',
                'data' => [
                    'lobby_state' => $lobbyState,
                    'version' => $lobby->version
                ]
            ]);

        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Failed to transfer initiator role', [
                'error' => $e->getMessage(),
                'session_id' => $sessionId,
                'current_initiator_id' => $currentInitiatorId,
                'new_initiator_id' => $newInitiatorId,
            ]);

            return response()->json([
                'status' => 'error',
                'message' => 'Failed to transfer leader role'
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

            // VALIDATION 3: Minimum 2 members required for group workout
            $activeMemberCount = $lobby->activeMembers()->count();
            if ($activeMemberCount < 2) {
                DB::rollBack();
                Log::warning('[LOBBY START] Insufficient members for group workout', [
                    'session_id' => $sessionId,
                    'initiator_id' => $initiatorId,
                    'active_member_count' => $activeMemberCount,
                ]);
                return response()->json([
                    'status' => 'error',
                    'message' => 'Cannot start group workout alone. You need at least 2 members. Start a solo workout from the Workouts page instead.'
                ], 400);
            }

            // Mark lobby as started
            $lobby->markAsStarted();

            // CREATE SERVER-AUTHORITATIVE SESSION
            // Server is now single source of truth for timer state
            $workoutSession = WorkoutSession::create([
                'session_id' => $sessionId,
                'lobby_id' => $lobby->id,
                'initiator_id' => $initiatorId,
                'status' => 'running',
                'time_remaining' => 10, // 10 second prepare phase
                'phase' => 'prepare',
                'current_exercise' => 0,
                'current_set' => 0,
                'current_round' => 0,
                'calories_burned' => 0,
                'started_at' => now(),
                'workout_data' => $lobby->workout_data, // Store exercise data
            ]);

            DB::commit();

            // Broadcast workout start
            broadcast(new WorkoutStarted($sessionId, time()));

            Log::info('Workout started - Server-authoritative session created', [
                'session_id' => $sessionId,
                'initiator_id' => $initiatorId,
                'workout_session_id' => $workoutSession->id,
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
    private function buildLobbyState(WorkoutLobby $lobby, ?string $token = null): array
    {
        $members = $lobby->activeMembers()->get()->map(function ($member) use ($token) {
            $userProfile = $token ? $this->authService->getUserProfile($token, $member->user_id) : null;
            $userName = $this->getUsernameFromProfile($userProfile, $member->user_id);
            $fitnessLevel = $userProfile['fitness_level'] ?? 'beginner';
            $userRole = $userProfile['user_role'] ?? 'member'; // mentor or member badge

            return [
                'user_id' => $member->user_id,
                'user_name' => $userName,
                'status' => $member->status,
                'joined_at' => $member->joined_at->timestamp,
                'fitness_level' => $fitnessLevel,
                'user_role' => $userRole,
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
     * POST /api/social/v2/lobby/force-leave-all
     *
     * Force leave any active lobby for the current user
     * Useful for cleaning up stale sessions
     */
    public function forceLeaveAllLobbies(Request $request): JsonResponse
    {
        $userId = (int) $request->attributes->get('user_id');

        try {
            Log::info('[FORCE LEAVE] User attempting to force leave all lobbies', [
                'user_id' => $userId
            ]);

            // Strategy 1: Find via member entries (direct table query)
            // Use same status check as activeMembers relationship
            $activeMemberEntries = WorkoutLobbyMember::where('user_id', $userId)
                ->whereIn('status', ['waiting', 'ready'])
                ->get();

            // Strategy 2: Also check via Lobby model (in case of inconsistency)
            $activeLobbiesViaModel = WorkoutLobby::active()
                ->whereHas('activeMembers', function($q) use ($userId) {
                    $q->where('user_id', $userId);
                })
                ->get();

            // Merge both strategies to ensure we catch everything
            $allLobbyIds = $activeMemberEntries->pluck('lobby_id')
                ->merge($activeLobbiesViaModel->pluck('id'))
                ->unique();

            Log::info('[FORCE LEAVE] Found lobbies via different strategies', [
                'user_id' => $userId,
                'member_entries_count' => $activeMemberEntries->count(),
                'model_query_count' => $activeLobbiesViaModel->count(),
                'merged_unique_count' => $allLobbyIds->count()
            ]);

            if ($allLobbyIds->isEmpty()) {
                Log::info('[FORCE LEAVE] No active lobbies found for user', [
                    'user_id' => $userId
                ]);

                return response()->json([
                    'status' => 'success',
                    'message' => 'No active lobbies found',
                    'data' => [
                        'lobbies_left' => 0
                    ]
                ]);
            }

            // Get all unique member entries for these lobbies
            $activeMemberEntries = WorkoutLobbyMember::where('user_id', $userId)
                ->whereIn('lobby_id', $allLobbyIds)
                ->whereIn('status', ['waiting', 'ready'])
                ->get();

            $lobbiesLeft = 0;
            $errors = [];

            // Get user info once
            $userProfile = $this->authService->getUserProfile($request->bearerToken(), $userId);
            $userName = $this->getUsernameFromProfile($userProfile, $userId);

            DB::beginTransaction();

            foreach ($activeMemberEntries as $memberEntry) {
                try {
                    $lobby = WorkoutLobby::find($memberEntry->lobby_id);

                    if (!$lobby || $lobby->status === 'completed') {
                        // Lobby doesn't exist or is completed, just mark member as left
                        $memberEntry->update([
                            'status' => 'left',
                            'left_reason' => 'force_cleanup'
                        ]);
                        continue;
                    }

                    // Remove member from lobby
                    $lobby->removeMember($userId, 'force_cleanup');

                    // Add system message
                    $lobby->addSystemMessage("{$userName} left the lobby (cleanup)");

                    // If initiator leaves, transfer role
                    if ($lobby->isInitiator($userId)) {
                        $nextMember = $lobby->activeMembers()->first();

                        if ($nextMember) {
                            $lobby->transferInitiator($nextMember->user_id);
                            $nextMemberProfile = $this->authService->getUserProfile($request->bearerToken(), $nextMember->user_id);
                            $nextMemberName = $this->getUsernameFromProfile($nextMemberProfile, $nextMember->user_id);

                            $lobby->addSystemMessage("{$nextMemberName} is now the lobby leader");

                            $lobby->refresh();

                            // FIXED: Pass all 6 required parameters to InitiatorRoleTransferred event
                            // Constructor signature: sessionId, oldInitiatorId, newInitiatorId, newInitiatorName, lobbyState, version
                            $lobbyState = $this->buildLobbyState($lobby, $request->bearerToken());
                            broadcast(new InitiatorRoleTransferred(
                                $lobby->session_id,
                                $userId,                    // oldInitiatorId
                                $nextMember->user_id,       // newInitiatorId
                                $nextMemberName,            // newInitiatorName
                                $lobbyState,                // lobbyState
                                $lobby->version ?? 1        // version
                            ))->toOthers();
                        } else {
                            // No other members, delete the lobby
                            $lobby->delete();
                            broadcast(new LobbyDeleted(
                                $lobby->session_id,
                                'All members left',
                                time()
                            ))->toOthers();
                        }
                    }

                    // Broadcast member left event
                    if ($lobby->exists) {
                        // Build lobby state for events
                        $lobbyState = $this->buildLobbyState($lobby, $request->bearerToken());

                        broadcast(new MemberLeft(
                            $lobby->session_id,
                            $userId,
                            $userName,
                            $lobbyState,
                            $lobby->version ?? 1
                        ))->toOthers();

                        // Broadcast lobby state change
                        broadcast(new LobbyStateChanged(
                            $lobby->session_id,
                            $lobbyState
                        ))->toOthers();
                    }

                    $lobbiesLeft++;

                    Log::info('[FORCE LEAVE] Successfully left lobby', [
                        'user_id' => $userId,
                        'session_id' => $lobby->session_id
                    ]);

                } catch (\Exception $e) {
                    Log::error('[FORCE LEAVE] Error leaving lobby', [
                        'user_id' => $userId,
                        'lobby_id' => $memberEntry->lobby_id,
                        'error' => $e->getMessage()
                    ]);
                    $errors[] = [
                        'lobby_id' => $memberEntry->lobby_id,
                        'error' => $e->getMessage()
                    ];
                }
            }

            DB::commit();

            return response()->json([
                'status' => 'success',
                'message' => "Successfully left {$lobbiesLeft} lobby(ies)",
                'data' => [
                    'lobbies_left' => $lobbiesLeft,
                    'errors' => $errors
                ]
            ]);

        } catch (\Exception $e) {
            DB::rollBack();

            Log::error('[FORCE LEAVE] Force leave failed', [
                'user_id' => $userId,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            return response()->json([
                'status' => 'error',
                'message' => 'Failed to force leave lobbies: ' . $e->getMessage()
            ], 500);
        }
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
