<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\GroupController;
use App\Http\Controllers\GroupMemberController;
use App\Http\Controllers\GroupWorkoutController;

Route::get('/user', function (Request $request) {
    return $request->user();
})->middleware('auth:sanctum');

Route::prefix('social')->middleware('auth:sanctum')->group(function () {

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

    // Social Discovery
    Route::get('discover-groups', [GroupController::class, 'discoverGroups']);
    Route::get('group-search', [GroupController::class, 'searchGroups']);
    Route::get('group-activity/{groupId}', [GroupController::class, 'getGroupActivity']);

});
