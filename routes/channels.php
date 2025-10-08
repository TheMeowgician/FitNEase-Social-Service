<?php

use Illuminate\Support\Facades\Broadcast;
use Illuminate\Support\Facades\Log;

Broadcast::channel('App.Models.User.{id}', function ($user, $id) {
    return (int) $user->id === (int) $id;
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
