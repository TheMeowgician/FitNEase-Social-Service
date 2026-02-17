<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\GroupController;
use App\Http\Controllers\GroupMemberController;
use App\Http\Controllers\GroupWorkoutController;
use App\Http\Controllers\JoinRequestController;
use App\Http\Controllers\LobbyController;
use App\Http\Controllers\AgoraController;
use App\Http\Controllers\ServiceTestController;
use App\Http\Controllers\ServiceCommunicationTestController;
use App\Http\Controllers\ServiceIntegrationDemoController;
use Illuminate\Support\Facades\Broadcast;
use App\Http\Middleware\ValidateApiToken;

// Broadcasting Authentication Route - Must use ValidateApiToken middleware
Route::post('/broadcasting/auth', function (Request $request) {
    return Broadcast::auth($request);
})->middleware(ValidateApiToken::class);

Route::get('/user', function (Request $request) {
    return response()->json($request->attributes->get('user'));
})->middleware(ValidateApiToken::class);

Route::get('/health', function () {
    return response()->json(['status' => 'OK', 'service' => 'fitnease-social']);
});

// Service-to-service endpoints (no auth required)
Route::prefix('')->group(function () {
    // Called by tracking service after workout completion to broadcast updated stats
    Route::post('/groups/{groupId}/broadcast-stats', [GroupController::class, 'broadcastGroupStats']);
});

Route::prefix('')->middleware([ValidateApiToken::class, 'throttle:api'])->group(function () {

    // Group Management
    Route::apiResource('groups', GroupController::class);
    Route::get('group/{groupId}', [GroupController::class, 'show']);
    Route::put('group/{groupId}', [GroupController::class, 'update']);
    Route::delete('group/{groupId}', [GroupController::class, 'destroy']);

    // Group Membership
    Route::post('groups/{groupId}/join', [GroupMemberController::class, 'joinGroup']);
    Route::post('groups/join-with-code', [GroupMemberController::class, 'joinGroupWithCode']);
    Route::post('groups/{groupId}/invite', [GroupMemberController::class, 'inviteUser']);
    Route::delete('groups/{groupId}/leave', [GroupMemberController::class, 'leaveGroup']);
    Route::get('user-groups/{userId}', [GroupMemberController::class, 'getUserGroups']);
    Route::put('groups/{groupId}/members/{userId}/role', [GroupMemberController::class, 'updateMemberRole']);
    Route::delete('groups/{groupId}/members/{userId}', [GroupMemberController::class, 'removeMember']);
    Route::get('groups/{groupId}/members', [GroupMemberController::class, 'getGroupMembers']);

    // Join Requests (approval system)
    Route::post('groups/{groupId}/join-requests', [JoinRequestController::class, 'createJoinRequest']);
    Route::get('groups/{groupId}/join-requests', [JoinRequestController::class, 'getJoinRequests']);
    Route::get('groups/{groupId}/join-requests/count', [JoinRequestController::class, 'getJoinRequestCount']);
    Route::post('groups/{groupId}/join-requests/{requestId}/approve', [JoinRequestController::class, 'approveJoinRequest']);
    Route::post('groups/{groupId}/join-requests/{requestId}/reject', [JoinRequestController::class, 'rejectJoinRequest']);
    Route::delete('groups/{groupId}/join-requests', [JoinRequestController::class, 'cancelJoinRequest']);
    Route::get('user/join-requests', [JoinRequestController::class, 'getUserJoinRequests']);

    // Group Workouts
    Route::get('group-workouts/{groupId}', [GroupWorkoutController::class, 'getGroupWorkouts']);
    Route::post('group-evaluation', [GroupWorkoutController::class, 'createWorkoutEvaluation']);
    Route::get('group-leaderboard/{groupId}', [GroupWorkoutController::class, 'getGroupLeaderboard']);
    Route::get('group-challenges/{groupId}', [GroupWorkoutController::class, 'getGroupChallenges']);
    Route::post('groups/{groupId}/workouts/{workoutId}/join', [GroupWorkoutController::class, 'joinGroupWorkout']);
    Route::get('groups/{groupId}/workouts/{workoutId}/evaluations', [GroupWorkoutController::class, 'getWorkoutEvaluations']);
    Route::get('groups/{groupId}/popular-workouts', [GroupWorkoutController::class, 'getPopularWorkouts']);
    Route::post('groups/{groupId}/initiate-workout', [GroupController::class, 'initiateGroupWorkout']);

    // Workout Session Control
    Route::post('session/{sessionId}/pause', [GroupController::class, 'pauseWorkout']);
    Route::post('session/{sessionId}/resume', [GroupController::class, 'resumeWorkout']);
    Route::post('session/{sessionId}/stop', [GroupController::class, 'stopWorkout']);
    Route::post('session/{sessionId}/finish', [GroupController::class, 'finishWorkout']);

    // Social Discovery
    Route::get('discover-groups', [GroupController::class, 'discoverGroups']);
    Route::get('group-search', [GroupController::class, 'searchGroups']);
    Route::get('group-activity/{groupId}', [GroupController::class, 'getGroupActivity']);

    // ============================================================================
    // LOBBY MANAGEMENT
    // ============================================================================

    // Create lobby - Rate limited to prevent spam
    Route::post('lobby/create', [LobbyController::class, 'createLobby'])
        ->middleware('throttle:lobby_creation');

    // Join lobby - No extra rate limit (users accept invitations)
    Route::post('lobby/{sessionId}/join', [LobbyController::class, 'joinLobby']);

    // Leave lobby - No rate limit (users should be able to leave freely)
    Route::post('lobby/{sessionId}/leave', [LobbyController::class, 'leaveLobby']);

    // Get lobby state - Standard API rate limit
    Route::get('lobby/{sessionId}', [LobbyController::class, 'getLobbyState']);

    // ============================================================================
    // MEMBER STATUS & CONTROL
    // ============================================================================

    // Update member status (waiting/ready) - Rate limited to prevent spam
    Route::post('lobby/{sessionId}/status', [LobbyController::class, 'updateStatus'])
        ->middleware('throttle:status_updates');

    // Start workout - Only initiator, no extra rate limit
    Route::post('lobby/{sessionId}/start', [LobbyController::class, 'startWorkout']);

    // ============================================================================
    // CHAT MESSAGES
    // ============================================================================

    // Send chat message - Rate limited to prevent spam
    Route::post('lobby/{sessionId}/message', [LobbyController::class, 'sendMessage'])
        ->middleware('throttle:chat_messages');

    // Get chat messages (paginated) - Standard API rate limit
    Route::get('lobby/{sessionId}/messages', [LobbyController::class, 'getChatMessages']);

    // ============================================================================
    // INVITATIONS
    // ============================================================================

    // Send invitation - Rate limited to prevent spam
    Route::post('lobby/{sessionId}/invite', [LobbyController::class, 'inviteMember'])
        ->middleware('throttle:invitations');

    // Accept invitation - No extra rate limit
    Route::post('invitations/{invitationId}/accept', [LobbyController::class, 'acceptInvitation']);

    // Decline invitation - No extra rate limit
    Route::post('invitations/{invitationId}/decline', [LobbyController::class, 'declineInvitation']);

    // Get pending invitations - Standard API rate limit
    Route::get('invitations/pending', [LobbyController::class, 'getPendingInvitations']);

    // ============================================================================
    // MODERATION
    // ============================================================================

    // Kick member - Only initiator, moderate rate limit to prevent abuse
    Route::post('lobby/{sessionId}/kick', [LobbyController::class, 'kickMember'])
        ->middleware('throttle:moderation_actions');

    // ============================================================================
    // AGORA VIDEO CONFERENCING
    // ============================================================================

    // Generate Agora token for video call
    Route::post('agora/token', [AgoraController::class, 'generateToken']);

    // Revoke Agora token
    Route::delete('agora/token', [AgoraController::class, 'revokeToken']);

    // Get channel info and participants
    Route::get('agora/channel/{sessionId}', [AgoraController::class, 'getChannelInfo']);

    // Update media status (camera/mic on/off)
    Route::patch('agora/media-status', [AgoraController::class, 'updateMediaStatus']);

});

// ============================================================================
// V2 API ROUTES (Event Sourced)
// ============================================================================
Route::prefix('v2')->middleware([ValidateApiToken::class, 'throttle:api'])->group(function () {

    // ============================================================================
    // LOBBY MANAGEMENT (V2)
    // ============================================================================

    // Create lobby - Rate limited to prevent spam
    Route::post('lobby/create', [LobbyController::class, 'createLobby'])
        ->middleware('throttle:lobby_creation');

    // Join lobby - No extra rate limit (users accept invitations)
    Route::post('lobby/{sessionId}/join', [LobbyController::class, 'joinLobby']);

    // Leave lobby - No rate limit (users should be able to leave freely)
    Route::post('lobby/{sessionId}/leave', [LobbyController::class, 'leaveLobby']);

    // Force leave ALL active lobbies (for cleanup) - No rate limit
    Route::post('lobby/force-leave-all', [LobbyController::class, 'forceLeaveAllLobbies']);

    // Get lobby state - Standard API rate limit
    Route::get('lobby/{sessionId}', [LobbyController::class, 'getLobbyState']);

    // ============================================================================
    // MEMBER STATUS & CONTROL (V2)
    // ============================================================================

    // Update member status (waiting/ready) - Rate limited to prevent spam
    Route::post('lobby/{sessionId}/status', [LobbyController::class, 'updateStatus'])
        ->middleware('throttle:status_updates');

    // Update workout data (exercises) - Rate limited to prevent spam
    Route::post('lobby/{sessionId}/workout-data', [LobbyController::class, 'updateWorkoutData'])
        ->middleware('throttle:status_updates');

    // Start workout - Only initiator, no extra rate limit
    Route::post('lobby/{sessionId}/start', [LobbyController::class, 'startWorkout']);

    // ============================================================================
    // CHAT MESSAGES (V2)
    // ============================================================================

    // Send chat message - Rate limited to prevent spam
    Route::post('lobby/{sessionId}/message', [LobbyController::class, 'sendMessage'])
        ->middleware('throttle:chat_messages');

    // Get chat messages (paginated) - Standard API rate limit
    Route::get('lobby/{sessionId}/messages', [LobbyController::class, 'getChatMessages']);

    // ============================================================================
    // INVITATIONS (V2)
    // ============================================================================

    // Send invitation - Rate limited to prevent spam
    Route::post('lobby/{sessionId}/invite', [LobbyController::class, 'inviteMember'])
        ->middleware('throttle:invitations');

    // Accept invitation - No extra rate limit
    Route::post('invitations/{invitationId}/accept', [LobbyController::class, 'acceptInvitation']);

    // Decline invitation - No extra rate limit
    Route::post('invitations/{invitationId}/decline', [LobbyController::class, 'declineInvitation']);

    // Get pending invitations - Standard API rate limit
    Route::get('invitations/pending', [LobbyController::class, 'getPendingInvitations']);

    // ============================================================================
    // MODERATION (V2)
    // ============================================================================

    // Kick member - Only initiator, moderate rate limit to prevent abuse
    Route::post('lobby/{sessionId}/kick', [LobbyController::class, 'kickMember'])
        ->middleware('throttle:moderation_actions');

    // Transfer initiator role - Only initiator, moderate rate limit to prevent abuse
    Route::post('lobby/{sessionId}/transfer-initiator', [LobbyController::class, 'transferInitiatorRole'])
        ->middleware('throttle:moderation_actions');

    // ============================================================================
    // READY CHECK (V2)
    // ============================================================================

    // Start ready check - Only initiator, rate limited
    Route::post('lobby/{sessionId}/ready-check/start', [LobbyController::class, 'startReadyCheck'])
        ->middleware('throttle:moderation_actions');

    // Respond to ready check - Any lobby member
    Route::post('lobby/{sessionId}/ready-check/respond', [LobbyController::class, 'respondToReadyCheck']);

    // Cancel ready check - Only initiator
    Route::post('lobby/{sessionId}/ready-check/cancel', [LobbyController::class, 'cancelReadyCheck']);

    // ============================================================================
    // VOTING (V2)
    // ============================================================================

    // Start voting - After exercises generated, auto-triggered or manual
    Route::post('lobby/{sessionId}/voting/start', [LobbyController::class, 'startVoting']);

    // Submit vote - Any lobby member (accept or customize)
    Route::post('lobby/{sessionId}/voting/submit', [LobbyController::class, 'submitVote']);

    // Force complete voting - Called on timeout
    Route::post('lobby/{sessionId}/voting/complete', [LobbyController::class, 'forceCompleteVoting']);

    // ============================================================================
    // EXERCISE CUSTOMIZATION (V2)
    // ============================================================================

    // Swap exercise - Customizer only, after voting "customize"
    Route::post('lobby/{sessionId}/exercises/swap', [LobbyController::class, 'swapExercise']);

    // Reorder exercises - Customizer only, after voting "customize"
    Route::post('lobby/{sessionId}/exercises/reorder', [LobbyController::class, 'reorderExercises']);

    // ============================================================================
    // AGORA VIDEO CONFERENCING (V2)
    // ============================================================================

    // Generate Agora token for video call
    Route::post('agora/token', [AgoraController::class, 'generateToken']);

    // Revoke Agora token
    Route::delete('agora/token', [AgoraController::class, 'revokeToken']);

    // Get channel info and participants
    Route::get('agora/channel/{sessionId}', [AgoraController::class, 'getChannelInfo']);

    // Update media status (camera/mic on/off)
    Route::patch('agora/media-status', [AgoraController::class, 'updateMediaStatus']);

});

// Service Communication Testing Routes (Protected)
Route::middleware(ValidateApiToken::class)->group(function () {
    Route::get('/test-services', [ServiceTestController::class, 'testAllServices']);
    Route::get('/test-service/{serviceName}', [ServiceTestController::class, 'testSpecificService']);
    Route::get('/service-test/connectivity', [ServiceCommunicationTestController::class, 'testServiceConnectivity']);
    Route::get('/service-test/communications', [ServiceCommunicationTestController::class, 'testIncomingCommunications']);
    Route::get('/service-test/token-validation', [ServiceCommunicationTestController::class, 'testSocialTokenValidation']);
});

// Service Integration Demo Routes (Public - No Auth Required)
Route::prefix('demo')->group(function () {
    Route::get('/integrations', [ServiceIntegrationDemoController::class, 'getServiceIntegrationOverview']);
    Route::get('/tracking-service', [ServiceIntegrationDemoController::class, 'demoTrackingServiceCall']);
    Route::get('/content-service', [ServiceIntegrationDemoController::class, 'demoContentServiceCall']);
    Route::get('/communications-service', [ServiceIntegrationDemoController::class, 'demoCommsServiceCall']);
    Route::get('/engagement-service', [ServiceIntegrationDemoController::class, 'demoEngagementServiceCall']);
});
