<?php

namespace App\Console\Commands;

use App\Access\AccountStatus;
use App\Access\Role;
use App\Models\AuditEntry;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rules\Password;

/**
 * Chicken and egg: invitations require an admin, and a fresh database has none.
 *
 * This is the only way an account is created without an invitation, which is
 * why it runs from a shell on the server rather than over HTTP, and why it
 * refuses to run once a working admin exists.
 */
class MakeAdmin extends Command
{
    protected $signature = 'registry:make-admin
        {email : The address the admin will sign in with}
        {--name= : Their name, if the account is being created}
        {--password= : Set the password directly instead of emailing a reset code}
        {--verified : Mark the address verified without emailing a code}
        {--force : Create another admin even though an active one already exists}';

    protected $description = 'Create or promote the first administrator of the registry';

    public function handle(): int
    {
        $email = (string) $this->argument('email');

        $validator = Validator::make(
            ['email' => $email, 'password' => $this->option('password')],
            [
                'email' => ['required', 'email'],
                'password' => ['nullable', Password::defaults()],
            ],
        );

        if ($validator->fails()) {
            foreach ($validator->errors()->all() as $error) {
                $this->error($error);
            }

            return self::FAILURE;
        }

        $existingAdmin = User::where('role', Role::ADMIN)
            ->where('status', AccountStatus::ACTIVE)
            ->first();

        if ($existingAdmin && ! $this->option('force')) {
            $this->error("An active admin already exists ({$existingAdmin->email}).");
            $this->line('Invite further admins through the application, or pass --force if you are certain.');

            return self::FAILURE;
        }

        $user = User::where('email', $email)->first();
        $promoted = $user !== null;

        $user = DB::transaction(function () use ($user, $email, $promoted): User {
            $user ??= new User([
                'name' => $this->option('name') ?: 'Registry Administrator',
                'email' => $email,
            ]);

            $user->role = Role::ADMIN;
            $user->status = AccountStatus::ACTIVE;
            $user->deactivated_at = null;
            $user->deactivated_by = null;
            $user->deactivation_reason = null;

            if ($password = $this->option('password')) {
                $user->password = Hash::make($password);
            } elseif (! $promoted) {
                // No usable password: the account cannot be signed into until
                // one is set through the forgotten-password flow.
                $user->password = Hash::make(bin2hex(random_bytes(32)));
            }

            if ($this->option('verified')) {
                $user->email_verified_at ??= now();
            }

            $user->save();

            // Actor is null: nobody was signed in, which is the point of a
            // bootstrap and is exactly what an auditor needs to see.
            AuditEntry::create([
                'actor_id' => null,
                'action' => 'user.bootstrapped',
                'subject_type' => 'user',
                'subject_id' => $user->id,
                'context' => [
                    'email' => $user->email,
                    'promoted_existing_account' => $promoted,
                    'password_set_directly' => $this->option('password') !== null,
                ],
                'created_at' => now(),
            ]);

            return $user;
        });

        $this->info($promoted
            ? "Promoted {$user->email} to admin."
            : "Created admin {$user->email}.");

        if (! $this->option('password')) {
            $this->line('No password was set. Use the forgotten-password flow to choose one.');
        }

        if (! $user->hasVerifiedEmail()) {
            $user->sendEmailVerificationNotification();
            $this->line('A verification code has been emailed; the account cannot use the registry until it is entered.');
        }

        $this->newLine();
        $this->warn('Appoint a second admin before go-live. One admin who leaves, loses their phone,');
        $this->warn('or is on leave when someone needs removing is a real operational problem.');

        return self::SUCCESS;
    }
}
