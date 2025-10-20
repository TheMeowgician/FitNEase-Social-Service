<?php

use Illuminate\Support\Facades\Broadcast;
use Illuminate\Support\Facades\Log;

Broadcast::channel('App.Models.User.{id}', function ($user, $id) {
    return (int) $user->id === (int) $id;
});

// User notification channel - For real-time notifications
Broadcast::channel('user.{userId}', function ($user, $userId) {
    Log::info('Broadcasting auth for user notification channel', [
        'authenticated_user_id' => $user->id ?? 'null',
        'requested_user_id' => $userId
    ]);

    // User can only subscribe to their own notification channel
    return (int) $user->id === (int) $userId;
});

// Group private channel - Only group members can subscribe
Broadcast::channel('group.{groupId}', function ($user, $groupId) {
    Log::info('Broadcasting auth for group channel', [
        'user_id' => $user->id ?? 'null',
        'group_id' => $groupId,
        'user_object' => get_class($user)
    ]);

    // Check if user is a member of this group
    $membership = \App\Models\GroupMember::where('group_id', $groupId)
        ->where('user_id', $user->id)
        ->where('is_active', true)
        ->first();

    $result = $membership !== null;

    Log::info('Broadcasting auth result', [
        'user_id' => $user->id,
        'group_id' => $groupId,
        'is_member' => $result,
        'membership_found' => $membership ? 'yes' : 'no'
    ]);

    return $result;
});

// Workout lobby channel - Only lobby members can subscribe
Broadcast::channel('lobby.{sessionId}', function ($user, $sessionId) {
    Log::info('Broadcasting auth for lobby channel', [
        'user_id' => $user->id ?? 'null',
        'session_id' => $sessionId
    ]);

    // Check if user is a member of this lobby
    $lobby = \App\Models\WorkoutLobby::where('session_id', $sessionId)->first();

    if (!$lobby) {
        Log::warning('Lobby not found for channel auth', ['session_id' => $sessionId]);
        return false;
    }

    $isMember = $lobby->hasMember($user->id);

    Log::info('Lobby channel auth result', [
        'user_id' => $user->id,
        'session_id' => $sessionId,
        'is_member' => $isMember
    ]);

    return $isMember;
});

// Workout session channel - For real-time workout control (pause/resume)
Broadcast::channel('session.{sessionId}', function ($user, $sessionId) {
    Log::info('Broadcasting auth for session channel', [
        'user_id' => $user->id ?? 'null',
        'session_id' => $sessionId
    ]);

    // Allow any authenticated user to join session
    return true;
});

// GLOBAL online users presence channel - All logged-in users join this channel
Broadcast::channel('online-users', function ($user) {
    // The $user object already has username populated by ValidateApiToken middleware
    $username = $user->username ?? 'User';

    Log::info('Broadcasting auth for GLOBAL online-users presence channel', [
        'user_id' => $user->id ?? 'null',
        'username' => $username,
        'email' => $user->email ?? 'null'
    ]);

    // Return user info for presence channel
    // The 'id' will be used as the presence member identifier
    return [
        'id' => $user->id,
        'username' => $username,
    ];
});
