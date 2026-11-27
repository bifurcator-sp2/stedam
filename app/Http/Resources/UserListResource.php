<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Элемент списка пользователей (лёгкая проекция).
 *
 * @property \App\Models\User $resource
 */
class UserListResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $userData = $this->whenLoaded('userData');

        return [
            'id' => $this->id,
            'first_name' => $userData?->first_name,
            'last_name' => $userData?->last_name,
            'middle_name' => $userData?->middle_name,
            'country' => $userData?->relationLoaded('country') && $userData->country !== null
                ? [
                    'id' => $userData->country->id,
                    'name' => $userData->country->name,
                ]
                : null,
            'roles' => $this->whenLoaded('roles', fn () => $this->roles->pluck('name')->values()->all()),
        ];
    }
}
