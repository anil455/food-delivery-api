<?php

declare(strict_types=1);

namespace App\Http\Resources\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin \App\Models\User
 */
class UserResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'phone' => $this->phone,
            'email' => $this->email,
            'type' => $this->type->value,
            'is_super_admin' => $this->is_super_admin,
            'status' => $this->status,
            'phone_verified' => $this->phone_verified_at !== null,
            'selected_restaurant_id' => $this->selected_restaurant_id,
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
