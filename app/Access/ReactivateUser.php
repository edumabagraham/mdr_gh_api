<?php

declare(strict_types=1);

namespace App\Access;

use App\Models\AuditEntry;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Restores access to someone who left and came back, or whose suspension is
 * over.
 *
 * A reason is required in both directions. "Why was this person removed" and
 * "why were they let back in" are the two questions an access review asks, and
 * neither is answerable from a status column alone.
 */
final class ReactivateUser
{
    public function __invoke(User $user, string $reason, ?User $actor = null, ?string $ipAddress = null): User
    {
        return DB::transaction(function () use ($user, $reason, $actor, $ipAddress): User {
            $before = ['status' => $user->status, 'reason' => $user->deactivation_reason];

            $user->forceFill([
                'status' => AccountStatus::ACTIVE,
                'deactivated_at' => null,
                'deactivated_by' => null,
                'deactivation_reason' => null,
            ])->save();

            AuditEntry::create([
                'actor_id' => $actor?->getKey(),
                'action' => 'user.reactivated',
                'subject_type' => 'user',
                'subject_id' => $user->getKey(),
                'context' => [
                    'before' => $before,
                    'after' => ['status' => AccountStatus::ACTIVE],
                    'reason' => $reason,
                ],
                'ip_address' => $ipAddress,
                'created_at' => now(),
            ]);

            return $user;
        });
    }
}
