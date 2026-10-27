<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin \App\Models\User */
class UserResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'email' => $this->email,
            'phone' => $this->phone,
            'document_number' => $this->document_number,
            'avatar' => $this->avatar,
            'avatar_url' => $this->avatarUrl(),
            'is_active' => $this->is_active,
            'last_login_at' => $this->last_login_at,
            'roles' => RoleResource::collection($this->whenLoaded('roles')),
            'permissions' => $this->when(
                $this->relationLoaded('roles') || $this->relationLoaded('permissions'),
                fn () => $this->getAllPermissions()->pluck('name')->values()
            ),
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
