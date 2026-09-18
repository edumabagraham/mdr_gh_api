<?php

declare(strict_types=1);

namespace App\Access;

use App\Models\AuditEntry;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Ends someone's access to the registry.
 *
 * Flipping the status column is not deactivation. The application authenticates
 * with cookie sessions, so a clinician who was removed this morning keeps
 * working until their cookie expires unless their session rows go too. Every
 * step below is part of the same transaction for that reason.
 */
final class DeactivateUser
{
    /**
     * @param  string  $status  AccountStatus::SUSPENDED or ::DEPARTED
     *
     * @throws LastAdminException
     */
    public function __invoke(
        User $user,
        string $status,
        string $reason,
        ?User $actor = null,
        ?string $ipAddress = null,
    ): User {
        if ($this->isLastActiveAdmin($user)) {
            throw LastAdminException::make();
        }

        return DB::transaction(function () use ($user, $status, $reason, $actor, $ipAddress): User {
            $before = ['status' => $user->status, 'role' => $user->role];

            $user->forceFill([
                'status' => $status,
                'deactivated_at' => now(),
                'deactivated_by' => $actor?->getKey(),
                'deactivation_reason' => $reason,
            ])->save();

            // The live session is the difference between "cannot log in again"
            // and "cannot make another request".
            $sessionsEnded = DB::table('sessions')->where('user_id', $user->getKey())->delete();

            $user->tokens()->delete();

            $invitationsRevoked = DB::table('invitations')
                ->whereRaw('lower(email) = ?', [mb_strtolower($user->email)])
                ->whereNull('accepted_at')
                ->whereNull('revoked_at')
                ->update(['revoked_at' => now(), 'revoked_by' => $actor?->getKey(), 'updated_at' => now()]);

            AuditEntry::create([
                'actor_id' => $actor?->getKey(),
                'action' => 'user.deactivated',
                'subject_type' => 'user',
                'subject_id' => $user->getKey(),
                'context' => [
                    'before' => $before,
                    'after' => ['status' => $status, 'role' => $user->role],
                    'reason' => $reason,
                    'sessions_ended' => $sessionsEnded,
                    'invitations_revoked' => $invitationsRevoked,
                    'unfinished_work_flagged' => $this->flagUnfinishedWork($user),
                ],
                'ip_address' => $ipAddress,
                'created_at' => now(),
            ]);

            return $user;
        });
    }

    private function isLastActiveAdmin(User $user): bool
    {
        if ($user->role !== Role::ADMIN || ! $user->isActive()) {
            return false;
        }

        return User::where('role', Role::ADMIN)
            ->where('status', AccountStatus::ACTIVE)
            ->whereKeyNot($user->getKey())
            ->doesntExist();
    }

    /**
     * A departing clinician may have draft assessments open. They must not
     * vanish and must not be silently completed, so they go to a queue for a
     * data manager to reassign or void.
     *
     * The instrument tables arrive in a later slice; until then there is
     * nothing to flag, and saying so in the audit row is better than a silent
     * gap nobody notices when the table does appear.
     */
    private function flagUnfinishedWork(User $user): int|string
    {
        if (! Schema::hasTable('administrations')) {
            return 'administrations table not present';
        }

        return DB::table('administrations')
            ->where('administered_by', $user->getKey())
            ->where('status', 'draft')
            ->update(['needs_reassignment' => true, 'updated_at' => now()]);
    }
}
