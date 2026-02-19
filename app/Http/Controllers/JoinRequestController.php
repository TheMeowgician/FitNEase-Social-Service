<?php

namespace App\Http\Controllers;

use App\Events\GroupMemberUpdated;
use App\Models\Group;
use App\Models\GroupMember;
use App\Models\GroupJoinRequest;
use App\Services\AuthService;
use GuzzleHttp\Client;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;

class JoinRequestController extends Controller
{
    private AuthService $authService;

    public function __construct(AuthService $authService)
    {
        $this->authService = $authService;
    }

    /**
     * Create a join request for a group
     * POST /api/groups/{groupId}/join-requests
     */
    public function createJoinRequest(Request $request, string $groupId): JsonResponse
    {
        $userId = (int) $request->attributes->get('user_id');
        $groupIdInt = (int) $groupId;

        $validator = Validator::make($request->all(), [
            'message' => 'nullable|string|max:500'
        ]);

        if ($validator->fails()) {
            return response()->json([
                'status' => 'error',
                'message' => 'Validation failed',
                'errors' => $validator->errors()
            ], 422);
        }

        // Find the group
        $group = Group::where('group_id', $groupIdInt)->active()->first();

        if (!$group) {
            return response()->json([
                'status' => 'error',
                'message' => 'Group not found'
            ], 404);
        }

        // Check if group is full
        if ($group->current_member_count >= $group->max_members) {
            return response()->json([
                'status' => 'error',
                'message' => 'Group is full'
            ], 400);
        }

        // Check if user is already a member
        $existingMembership = GroupMember::where('group_id', $groupIdInt)
            ->where('user_id', $userId)
            ->where('is_active', true)
            ->first();

        if ($existingMembership) {
            return response()->json([
                'status' => 'error',
                'message' => 'Already a member of this group'
            ], 400);
        }

        // Check if user already has a pending request
        $existingRequest = GroupJoinRequest::where('group_id', $groupIdInt)
            ->where('user_id', $userId)
            ->pending()
            ->first();

        if ($existingRequest) {
            return response()->json([
                'status' => 'error',
                'message' => 'You already have a pending request for this group'
            ], 400);
        }

        try {
            DB::beginTransaction();

            // Create join request
            $joinRequest = GroupJoinRequest::create([
                'group_id' => $groupIdInt,
                'user_id' => $userId,
                'status' => 'pending',
                'message' => $request->message,
                'requested_at' => now(),
            ]);

            DB::commit();

            // Notify group owner via comms service
            $this->notifyOwnerOfJoinRequest($group, $userId, $request->bearerToken());

            Log::info('Join request created', [
                'request_id' => $joinRequest->request_id,
                'group_id' => $groupIdInt,
                'user_id' => $userId
            ]);

            return response()->json([
                'status' => 'success',
                'message' => 'Join request submitted successfully',
                'data' => [
                    'request_id' => $joinRequest->request_id,
                    'status' => 'pending'
                ]
            ], 201);

        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Failed to create join request', [
                'group_id' => $groupIdInt,
                'user_id' => $userId,
                'error' => $e->getMessage()
            ]);

            return response()->json([
                'status' => 'error',
                'message' => 'Failed to submit join request'
            ], 500);
        }
    }

    /**
     * Get pending join requests for a group (owner/moderator only)
     * GET /api/groups/{groupId}/join-requests
     */
    public function getJoinRequests(Request $request, string $groupId): JsonResponse
    {
        $userId = (int) $request->attributes->get('user_id');
        $groupIdInt = (int) $groupId;
        $token = $request->bearerToken();

        Log::info('getJoinRequests called', [
            'group_id' => $groupIdInt,
            'user_id' => $userId,
            'raw_group_id' => $groupId
        ]);

        // Find the group
        $group = Group::where('group_id', $groupIdInt)->active()->first();

        if (!$group) {
            return response()->json([
                'status' => 'error',
                'message' => 'Group not found'
            ], 404);
        }

        // Check if user is owner or moderator
        $membership = GroupMember::where('group_id', $groupIdInt)
            ->where('user_id', $userId)
            ->where('is_active', true)
            ->whereIn('member_role', ['admin', 'owner', 'moderator'])
            ->first();

        Log::info('Membership check result', [
            'group_id' => $groupIdInt,
            'user_id' => $userId,
            'found_membership' => $membership ? true : false,
            'membership_role' => $membership?->member_role
        ]);

        if (!$membership) {
            // Debug: Check what memberships exist for this group
            $allMemberships = GroupMember::where('group_id', $groupIdInt)
                ->where('is_active', true)
                ->get(['user_id', 'member_role']);

            Log::warning('Permission denied - no owner/mod membership found', [
                'group_id' => $groupIdInt,
                'user_id' => $userId,
                'existing_memberships' => $allMemberships->toArray()
            ]);

            return response()->json([
                'status' => 'error',
                'message' => 'Only group owner or moderators can view join requests'
            ], 403);
        }

        // Get pending requests
        $requests = GroupJoinRequest::where('group_id', $groupIdInt)
            ->pending()
            ->orderBy('requested_at', 'desc')
            ->get();

        // Enrich with user details
        $enrichedRequests = $requests->map(function ($joinRequest) use ($token) {
            $userProfile = $this->authService->getUserProfile($token, $joinRequest->user_id);

            return [
                'request_id' => $joinRequest->request_id,
                'user_id' => $joinRequest->user_id,
                'username' => $userProfile['username'] ?? 'User ' . $joinRequest->user_id,
                'user_role' => $userProfile['user_role'] ?? 'member',
                'message' => $joinRequest->message,
                'requested_at' => $joinRequest->requested_at->toISOString(),
            ];
        });

        return response()->json([
            'status' => 'success',
            'data' => [
                'requests' => $enrichedRequests,
                'total' => $enrichedRequests->count()
            ]
        ]);
    }

    /**
     * Get pending request count for a group (for badge display)
     * GET /api/groups/{groupId}/join-requests/count
     */
    public function getJoinRequestCount(Request $request, string $groupId): JsonResponse
    {
        $userId = (int) $request->attributes->get('user_id');
        $groupIdInt = (int) $groupId;

        // Check if user is owner or moderator
        $membership = GroupMember::where('group_id', $groupIdInt)
            ->where('user_id', $userId)
            ->where('is_active', true)
            ->whereIn('member_role', ['admin', 'owner', 'moderator'])
            ->first();

        if (!$membership) {
            return response()->json([
                'status' => 'success',
                'data' => ['count' => 0]
            ]);
        }

        $count = GroupJoinRequest::where('group_id', $groupIdInt)
            ->pending()
            ->count();

        return response()->json([
            'status' => 'success',
            'data' => ['count' => $count]
        ]);
    }

    /**
     * Approve a join request
     * POST /api/groups/{groupId}/join-requests/{requestId}/approve
     */
    public function approveJoinRequest(Request $request, string $groupId, string $requestId): JsonResponse
    {
        $userId = (int) $request->attributes->get('user_id');
        $groupIdInt = (int) $groupId;
        $requestIdInt = (int) $requestId;
        $token = $request->bearerToken();

        // Check if user is owner or moderator
        $membership = GroupMember::where('group_id', $groupIdInt)
            ->where('user_id', $userId)
            ->where('is_active', true)
            ->whereIn('member_role', ['admin', 'owner', 'moderator'])
            ->first();

        if (!$membership) {
            return response()->json([
                'status' => 'error',
                'message' => 'Only group owner or moderators can approve requests'
            ], 403);
        }

        // Find the request
        $joinRequest = GroupJoinRequest::where('request_id', $requestIdInt)
            ->where('group_id', $groupIdInt)
            ->pending()
            ->first();

        if (!$joinRequest) {
            return response()->json([
                'status' => 'error',
                'message' => 'Join request not found or already processed'
            ], 404);
        }

        // Get the group
        $group = Group::where('group_id', $groupIdInt)->first();

        // Check if group is full
        if ($group->current_member_count >= $group->max_members) {
            return response()->json([
                'status' => 'error',
                'message' => 'Group is full, cannot approve request'
            ], 400);
        }

        try {
            DB::beginTransaction();

            // Update request status
            $joinRequest->update([
                'status' => 'approved',
                'responded_by' => $userId,
                'responded_at' => now(),
            ]);

            // Check if user has an existing inactive membership (was previously removed)
            $existingMembership = GroupMember::where('group_id', $groupIdInt)
                ->where('user_id', $joinRequest->user_id)
                ->first();

            if ($existingMembership) {
                // Reactivate existing membership
                $existingMembership->update([
                    'is_active' => true,
                    'member_role' => 'member',
                    'joined_at' => now()
                ]);
                $newMembership = $existingMembership;
            } else {
                // Create new membership
                $newMembership = GroupMember::create([
                    'group_id' => $groupIdInt,
                    'user_id' => $joinRequest->user_id,
                    'member_role' => 'member'
                ]);
            }

            DB::commit();

            // Notify the requester
            $this->notifyRequesterApproved($group, $joinRequest->user_id, $token);

            // Broadcast member update
            $this->broadcastGroupMemberUpdate($groupIdInt, $token);

            Log::info('Join request approved', [
                'request_id' => $requestIdInt,
                'group_id' => $groupIdInt,
                'approved_by' => $userId,
                'new_member' => $joinRequest->user_id
            ]);

            return response()->json([
                'status' => 'success',
                'message' => 'Join request approved',
                'data' => [
                    'member' => $newMembership
                ]
            ]);

        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Failed to approve join request', [
                'request_id' => $requestIdInt,
                'error' => $e->getMessage()
            ]);

            return response()->json([
                'status' => 'error',
                'message' => 'Failed to approve request'
            ], 500);
        }
    }

    /**
     * Reject a join request
     * POST /api/groups/{groupId}/join-requests/{requestId}/reject
     */
    public function rejectJoinRequest(Request $request, string $groupId, string $requestId): JsonResponse
    {
        $userId = (int) $request->attributes->get('user_id');
        $groupIdInt = (int) $groupId;
        $requestIdInt = (int) $requestId;
        $token = $request->bearerToken();

        $validator = Validator::make($request->all(), [
            'reason' => 'nullable|string|max:500'
        ]);

        // Check if user is owner or moderator
        $membership = GroupMember::where('group_id', $groupIdInt)
            ->where('user_id', $userId)
            ->where('is_active', true)
            ->whereIn('member_role', ['admin', 'owner', 'moderator'])
            ->first();

        if (!$membership) {
            return response()->json([
                'status' => 'error',
                'message' => 'Only group owner or moderators can reject requests'
            ], 403);
        }

        // Find the request
        $joinRequest = GroupJoinRequest::where('request_id', $requestIdInt)
            ->where('group_id', $groupIdInt)
            ->pending()
            ->first();

        if (!$joinRequest) {
            return response()->json([
                'status' => 'error',
                'message' => 'Join request not found or already processed'
            ], 404);
        }

        // Get the group
        $group = Group::where('group_id', $groupIdInt)->first();

        try {
            // Update request status
            $joinRequest->update([
                'status' => 'rejected',
                'rejection_reason' => $request->reason,
                'responded_by' => $userId,
                'responded_at' => now(),
            ]);

            // Notify the requester
            $this->notifyRequesterRejected($group, $joinRequest->user_id, $request->reason, $token);

            Log::info('Join request rejected', [
                'request_id' => $requestIdInt,
                'group_id' => $groupIdInt,
                'rejected_by' => $userId
            ]);

            return response()->json([
                'status' => 'success',
                'message' => 'Join request rejected'
            ]);

        } catch (\Exception $e) {
            Log::error('Failed to reject join request', [
                'request_id' => $requestIdInt,
                'error' => $e->getMessage()
            ]);

            return response()->json([
                'status' => 'error',
                'message' => 'Failed to reject request'
            ], 500);
        }
    }

    /**
     * Get user's own join requests (to see status)
     * GET /api/user/join-requests
     */
    public function getUserJoinRequests(Request $request): JsonResponse
    {
        $userId = (int) $request->attributes->get('user_id');

        $requests = GroupJoinRequest::where('user_id', $userId)
            ->with('group')
            ->orderBy('requested_at', 'desc')
            ->get()
            ->map(function ($joinRequest) {
                return [
                    'request_id' => $joinRequest->request_id,
                    'group_id' => $joinRequest->group_id,
                    'group_name' => $joinRequest->group->group_name ?? 'Unknown Group',
                    'status' => $joinRequest->status,
                    'message' => $joinRequest->message,
                    'rejection_reason' => $joinRequest->rejection_reason,
                    'requested_at' => $joinRequest->requested_at->toISOString(),
                    'responded_at' => $joinRequest->responded_at?->toISOString(),
                ];
            });

        return response()->json([
            'status' => 'success',
            'data' => $requests
        ]);
    }

    /**
     * Cancel a pending join request
     * DELETE /api/groups/{groupId}/join-requests
     */
    public function cancelJoinRequest(Request $request, string $groupId): JsonResponse
    {
        $userId = (int) $request->attributes->get('user_id');
        $groupIdInt = (int) $groupId;

        $joinRequest = GroupJoinRequest::where('group_id', $groupIdInt)
            ->where('user_id', $userId)
            ->pending()
            ->first();

        if (!$joinRequest) {
            return response()->json([
                'status' => 'error',
                'message' => 'No pending request found'
            ], 404);
        }

        $joinRequest->delete();

        Log::info('Join request cancelled', [
            'group_id' => $groupIdInt,
            'user_id' => $userId
        ]);

        return response()->json([
            'status' => 'success',
            'message' => 'Join request cancelled'
        ]);
    }

    /**
     * Notify group owner of new join request
     */
    private function notifyOwnerOfJoinRequest(Group $group, int $requesterId, ?string $token): void
    {
        try {
            // Get requester's username
            $requesterProfile = $this->authService->getUserProfile($token, $requesterId);
            $requesterUsername = $requesterProfile['username'] ?? 'Someone';
            $requesterRole = $requesterProfile['user_role'] ?? 'member';

            // Get group owner (admin is the owner role in this database)
            $owner = GroupMember::where('group_id', $group->group_id)
                ->whereIn('member_role', ['admin', 'owner'])
                ->where('is_active', true)
                ->first();

            if (!$owner) {
                Log::warning('No owner found for group', ['group_id' => $group->group_id]);
                return;
            }

            $client = new Client();
            $response = $client->post(env('COMMS_SERVICE_URL') . '/api/comms/group-join-request', [
                'json' => [
                    'owner_user_id' => $owner->user_id,
                    'requester_user_id' => $requesterId,
                    'requester_username' => $requesterUsername,
                    'requester_role' => $requesterRole,
                    'group_id' => $group->group_id,
                    'group_name' => $group->group_name,
                ],
                'timeout' => 5
            ]);

            Log::info('Owner notified of join request', [
                'owner_id' => $owner->user_id,
                'requester_id' => $requesterId,
                'group_id' => $group->group_id
            ]);

        } catch (\Exception $e) {
            Log::warning('Failed to notify owner of join request', [
                'group_id' => $group->group_id,
                'error' => $e->getMessage()
            ]);
        }
    }

    /**
     * Notify requester that their request was approved
     */
    private function notifyRequesterApproved(Group $group, int $requesterId, ?string $token): void
    {
        try {
            $client = new Client();
            $client->post(env('COMMS_SERVICE_URL') . '/api/comms/group-join-request-approved', [
                'json' => [
                    'requester_user_id' => $requesterId,
                    'group_id' => $group->group_id,
                    'group_name' => $group->group_name,
                ],
                'timeout' => 5
            ]);

            Log::info('Requester notified of approval', [
                'requester_id' => $requesterId,
                'group_id' => $group->group_id
            ]);

        } catch (\Exception $e) {
            Log::warning('Failed to notify requester of approval', [
                'requester_id' => $requesterId,
                'error' => $e->getMessage()
            ]);
        }
    }

    /**
     * Notify requester that their request was rejected
     */
    private function notifyRequesterRejected(Group $group, int $requesterId, ?string $reason, ?string $token): void
    {
        try {
            $client = new Client();
            $client->post(env('COMMS_SERVICE_URL') . '/api/comms/group-join-request-rejected', [
                'json' => [
                    'requester_user_id' => $requesterId,
                    'group_id' => $group->group_id,
                    'group_name' => $group->group_name,
                    'reason' => $reason,
                ],
                'timeout' => 5
            ]);

            Log::info('Requester notified of rejection', [
                'requester_id' => $requesterId,
                'group_id' => $group->group_id
            ]);

        } catch (\Exception $e) {
            Log::warning('Failed to notify requester of rejection', [
                'requester_id' => $requesterId,
                'error' => $e->getMessage()
            ]);
        }
    }

    /**
     * Broadcast group member update via WebSocket so all group members see the
     * updated member list in real-time (e.g. after a join request is approved).
     */
    private function broadcastGroupMemberUpdate(int $groupId, ?string $token): void
    {
        try {
            $memberCount = GroupMember::where('group_id', $groupId)
                ->where('is_active', true)
                ->count();

            $groupMembers = GroupMember::where('group_id', $groupId)
                ->where('is_active', true)
                ->orderBy('joined_at', 'desc')
                ->get();

            $userIds = $groupMembers->pluck('user_id')->toArray();
            $usernamesMap = $this->batchFetchUsernames($userIds, $token);

            $members = $groupMembers->map(function ($member) use ($usernamesMap) {
                $userData = $usernamesMap[$member->user_id] ?? null;
                return [
                    'id'       => $member->group_member_id,
                    'userId'   => (string) $member->user_id,
                    'username' => is_array($userData)
                        ? ($userData['username'] ?? 'User ' . $member->user_id)
                        : ($userData ?? 'User ' . $member->user_id),
                    'role'     => $member->member_role,
                    'userRole' => is_array($userData) ? ($userData['user_role'] ?? 'member') : 'member',
                    'joinedAt' => $member->joined_at ? $member->joined_at->toISOString() : null,
                ];
            })->toArray();

            broadcast(new GroupMemberUpdated($groupId, $memberCount, $members));

            Log::info('Group member update broadcasted after join request action', [
                'group_id'   => $groupId,
                'member_count' => $memberCount,
            ]);
        } catch (\Exception $e) {
            Log::error('Failed to broadcast group member update', [
                'group_id' => $groupId,
                'error'    => $e->getMessage(),
            ]);
        }
    }

    /**
     * Batch-fetch usernames + user_role from the auth service for a list of user IDs.
     * Returns array keyed by user_id.
     */
    private function batchFetchUsernames(array $userIds, ?string $token): array
    {
        if (empty($userIds)) {
            return [];
        }

        try {
            $client = new Client();
            $response = $client->post(env('AUTH_SERVICE_URL') . '/api/batch-user-profiles', [
                'json'    => ['user_ids' => $userIds],
                'timeout' => 5,
                'headers' => ['Accept' => 'application/json'],
            ]);

            if ($response->getStatusCode() === 200) {
                $data     = json_decode($response->getBody(), true);
                $profiles = $data['data'] ?? [];
                $map      = [];
                foreach ($profiles as $profile) {
                    $uid = $profile['id'] ?? $profile['user_id'] ?? null;
                    if ($uid) {
                        $map[$uid] = [
                            'username'  => $profile['username'] ?? 'User ' . $uid,
                            'user_role' => $profile['user_role'] ?? 'member',
                        ];
                    }
                }
                return $map;
            }
        } catch (\Exception $e) {
            Log::warning('batchFetchUsernames failed in JoinRequestController', [
                'error' => $e->getMessage(),
            ]);
        }

        // Fall back to generic names — broadcast still goes out
        return [];
    }
}
