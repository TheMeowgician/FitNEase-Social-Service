<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class GroupResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'group_id' => $this->group_id,
            'group_name' => $this->group_name,
            'description' => $this->description,
            'created_by' => $this->created_by,
            'max_members' => $this->max_members,
            'current_member_count' => $this->current_member_count,
            'is_private' => $this->is_private,
            'group_code' => $this->when($this->shouldShowGroupCode($request), $this->group_code),
            'group_image' => $this->group_image,
            'is_active' => $this->is_active,
            'created_at' => $this->created_at?->toISOString(),
            'updated_at' => $this->updated_at?->toISOString(),
            'creator' => new UserResource($this->whenLoaded('creator')),
            'members' => GroupMemberResource::collection($this->whenLoaded('activeMembers')),
            'member_count' => $this->when($this->relationLoaded('activeMembers'), $this->activeMembers->count()),
            'user_membership' => $this->when(isset($this->user_membership), $this->user_membership),
            'activity_level' => $this->when(isset($this->activity_level), $this->activity_level)
        ];
    }

    private function shouldShowGroupCode(Request $request): bool
    {
        $user = $request->user();
        if (!$user) {
            return false;
        }

        if ($this->relationLoaded('activeMembers')) {
            return $this->activeMembers->contains('user_id', $user->id);
        }

        return false;
    }
}