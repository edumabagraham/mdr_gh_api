<?php

namespace App\Http\Resources;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A user as an administrator sees them.
 *
 * Wider than {@see UserResource}, which is what an account sees of itself:
 * this one carries the lifecycle and credential fields an access review is
 * conducted against.
 *
 * @mixin User
 */
class AdminUserResource extends JsonResource
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
            'grade' => $this->grade,
            'department' => $this->department,
            'mdc_number' => $this->mdc_number,
            'mdc_expires_on' => $this->mdc_expires_on?->toDateString(),
            'credential_sighted_on' => $this->credential_sighted_on?->toDateString(),
            'email_verified_at' => $this->email_verified_at?->toIso8601String(),
            'last_login_at' => $this->last_login_at?->toIso8601String(),

            // Null rather than a large number when they have never signed in:
            // "never" and "a long time ago" are different problems.
            'days_since_login' => $this->last_login_at
                ? (int) $this->last_login_at->diffInDays(now())
                : null,

            'deactivated_at' => $this->deactivated_at?->toIso8601String(),
            'deactivation_reason' => $this->deactivation_reason,
            'invited_by' => $this->whenLoaded('invitedBy', fn () => $this->invitedBy?->name),
        ];
    }
}
