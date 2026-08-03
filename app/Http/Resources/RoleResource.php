<?php

namespace App\Http\Resources;

use App\Services\Access\RoleService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Roles are referenced by `name` throughout the API — spatie/laravel-permission
 * keys on the name, and there is no public_id on the roles table.
 */
class RoleResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'name' => $this->name,
            // Built-in roles the app keys behaviour off: read-only, and the UI
            // should hide edit/delete controls for them.
            'isSystem' => in_array($this->name, RoleService::PROTECTED_ROLES, true),
            'usersCount' => $this->whenCounted('users', fn ($count) => (int) $count),
            'permissions' => $this->whenLoaded(
                'permissions',
                fn () => $this->permissions->pluck('name'),
            ),
        ];
    }
}
