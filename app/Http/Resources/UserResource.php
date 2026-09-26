<?php

namespace App\Http\Resources;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin User */
class UserResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'email' => $this->email,
            'is_active' => $this->is_active,
            'role' => $this->whenLoaded('roles', fn () => $this->roles->first()?->name),
            'warehouse_ids' => $this->whenLoaded('warehouses', fn () => $this->warehouses->pluck('id')),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
