<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class GroupMemberResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'group_member_id' => $this->group_member_id,
            'group_id' => $this->group_id,
            'user_id' => $this->user_id,
            'member_role' => $this->member_role,
            'joined_at' => $this->joined_at?->toISOString(),
            'is_active' => $this->is_active,
            'user' => new UserResource($this->whenLoaded('user')),
            'group' => new GroupResource($this->whenLoaded('group')),
            'can_manage_group' => $this->canManageGroup(),
            'is_admin' => $this->isAdmin(),
            'is_moderator' => $this->isModerator()
        ];
    }
}