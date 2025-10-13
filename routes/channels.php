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

// Workout lobby channel - Anyone with valid session can join
Broadcast::channel('lobby.{sessionId}', function ($user, $sessionId) {
    Log::info('Broadcasting auth for lobby channel', [
        'user_id' => $user->id ?? 'null',
        'session_id' => $sessionId
    ]);

    // Allow any authenticated user to join lobby
    // In production, you might want to verify they were invited
    return true;
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
