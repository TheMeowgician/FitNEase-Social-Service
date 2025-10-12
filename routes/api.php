<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\GroupController;
use App\Http\Controllers\GroupMemberController;
use App\Http\Controllers\GroupWorkoutController;
use App\Http\Controllers\ServiceTestController;
use App\Http\Controllers\ServiceCommunicationTestController;
use App\Http\Controllers\ServiceIntegrationDemoController;
use Illuminate\Support\Facades\Broadcast;

// Broadcasting Authentication Route - Must use auth.api middleware
Route::post('/broadcasting/auth', function (Request $request) {
    return Broadcast::auth($request);
})->middleware('auth.api');

Route::get('/user', function (Request $request) {
    return response()->json($request->attributes->get('user'));
})->middleware('auth.api');

Route::get('/health', function () {
    return response()->json(['status' => 'OK', 'service' => 'fitnease-social']);
});


Route::prefix('social')->middleware('auth.api')->group(function () {

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

    // Group Workouts
    Route::get('group-workouts/{groupId}', [GroupWorkoutController::class, 'getGroupWorkouts']);
    Route::post('group-evaluation', [GroupWorkoutController::class, 'createWorkoutEvaluation']);
    Route::get('group-leaderboard/{groupId}', [GroupWorkoutController::class, 'getGroupLeaderboard']);
    Route::get('group-challenges/{groupId}', [GroupWorkoutController::class, 'getGroupChallenges']);
    Route::post('groups/{groupId}/workouts/{workoutId}/join', [GroupWorkoutController::class, 'joinGroupWorkout']);
    Route::get('groups/{groupId}/workouts/{workoutId}/evaluations', [GroupWorkoutController::class, 'getWorkoutEvaluations']);
    Route::get('groups/{groupId}/popular-workouts', [GroupWorkoutController::class, 'getPopularWorkouts']);
    Route::post('groups/{groupId}/initiate-workout', [GroupController::class, 'initiateGroupWorkout']);

    // Workout Lobby
    Route::post('lobby/{sessionId}/status', [GroupController::class, 'updateLobbyStatus']);
    Route::post('lobby/{sessionId}/start', [GroupController::class, 'startWorkout']);
    Route::post('lobby/{sessionId}/invite', [GroupController::class, 'inviteMemberToLobby']);
    Route::post('lobby/{sessionId}/broadcast-exercises', [GroupController::class, 'broadcastExercises']);
    Route::post('lobby/{sessionId}/message', [GroupController::class, 'sendLobbyMessage']);
    Route::post('lobby/{sessionId}/pass-initiator', [GroupController::class, 'passInitiatorRole']);
    Route::post('lobby/{sessionId}/kick', [GroupController::class, 'kickUserFromLobby']);

    // Workout Session Control
    Route::post('session/{sessionId}/pause', [GroupController::class, 'pauseWorkout']);
    Route::post('session/{sessionId}/resume', [GroupController::class, 'resumeWorkout']);
    Route::post('session/{sessionId}/stop', [GroupController::class, 'stopWorkout']);

    // Social Discovery
    Route::get('discover-groups', [GroupController::class, 'discoverGroups']);
    Route::get('group-search', [GroupController::class, 'searchGroups']);
    Route::get('group-activity/{groupId}', [GroupController::class, 'getGroupActivity']);

});

// Service Communication Testing Routes (Protected)
Route::middleware('auth.api')->group(function () {
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
