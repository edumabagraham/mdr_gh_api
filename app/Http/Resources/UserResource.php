<?php

namespace App\Http\Resources;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * The account, as the client is allowed to see it.
 *
 * Returning the model directly leaked every access-control column added in
 * slice 0 — invited_by, deactivated_by, deactivation_reason, mdc_number,
 * last_active_at. None of it is the client's business, and shipping the schema
 * to the browser only makes it harder to change later.
 *
 * @mixin User
 */
class UserResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'title' => $this->title,
            'name' => $this->name,
            'display_name' => $this->displayName(),
            'email' => $this->email,
            'role' => $this->role,
            'status' => $this->status,
            'specialty' => $this->specialty,
            'email_verified_at' => $this->email_verified_at?->toIso8601String(),
        ];
    }
}
