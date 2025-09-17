<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class UserResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'user_id' => $this->user_id ?? $this->id,
            'name' => $this->name ?? $this->username,
            'email' => $this->when($this->shouldShowEmail($request), $this->email),
            'profile_image' => $this->profile_image ?? null,
            'created_at' => $this->created_at?->toISOString(),
        ];
    }

    private function shouldShowEmail(Request $request): bool
    {
        $currentUser = $request->user();
        return $currentUser && ($currentUser->id === $this->id || $currentUser->user_id === $this->user_id);
    }
}