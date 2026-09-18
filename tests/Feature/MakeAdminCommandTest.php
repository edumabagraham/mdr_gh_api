<?php

use App\Access\AccountStatus;
use App\Access\Role;
use App\Models\AuditEntry;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;

uses(RefreshDatabase::class);

it('creates the first admin and records that nobody authorised it', function () {
    $this->artisan('registry:make-admin', [
        'email' => 'admin1@mdr.kath.org',
        '--name' => 'Registry Administrator',
        '--password' => 'bootstrap-password',
        '--verified' => true,
    ])->assertSuccessful();

    $admin = User::sole();

    expect($admin->role)->toBe(Role::ADMIN)
        ->and($admin->status)->toBe(AccountStatus::ACTIVE)
        ->and($admin->hasVerifiedEmail())->toBeTrue()
        ->and(Hash::check('bootstrap-password', $admin->password))->toBeTrue();

    $entry = AuditEntry::where('action', 'user.bootstrapped')->sole();

    expect($entry->actor_id)->toBeNull()
        ->and($entry->subject_id)->toBe($admin->id);
});

it('refuses to run once an active admin exists', function () {
    User::factory()->admin()->create();

    $this->artisan('registry:make-admin', ['email' => 'admin2@mdr.kath.org'])
        ->assertFailed();

    expect(User::where('email', 'admin2@mdr.kath.org')->exists())->toBeFalse();
});

it('creates a second admin when forced', function () {
    User::factory()->admin()->create();

    $this->artisan('registry:make-admin', [
        'email' => 'admin2@mdr.kath.org',
        '--password' => 'bootstrap-password',
        '--force' => true,
    ])->assertSuccessful();

    expect(User::where('role', Role::ADMIN)->count())->toBe(2);
});

it('promotes an existing account rather than creating a duplicate', function () {
    $clinician = User::factory()->create(['email' => 'a.owusu@mdr.kath.org']);

    $this->artisan('registry:make-admin', ['email' => 'a.owusu@mdr.kath.org'])
        ->assertSuccessful();

    expect(User::count())->toBe(1)
        ->and($clinician->fresh()->role)->toBe(Role::ADMIN);
});

it('reactivates a departed account it is asked to promote', function () {
    User::factory()->deactivated()->create(['email' => 'returning@mdr.kath.org']);

    $this->artisan('registry:make-admin', ['email' => 'returning@mdr.kath.org'])
        ->assertSuccessful();

    $user = User::sole();

    expect($user->status)->toBe(AccountStatus::ACTIVE)
        ->and($user->deactivated_at)->toBeNull();
});

it('rejects a password that does not meet the policy', function () {
    $this->artisan('registry:make-admin', [
        'email' => 'admin1@mdr.kath.org',
        '--password' => 'short',
    ])->assertFailed();

    expect(User::count())->toBe(0);
});
