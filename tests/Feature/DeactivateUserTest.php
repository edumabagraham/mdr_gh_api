<?php

use App\Access\AccountStatus;
use App\Access\DeactivateUser;
use App\Access\LastAdminException;
use App\Models\AuditEntry;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

it('ends the live session so the next request is refused', function () {
    // Criterion 5. Without this the user keeps working until the cookie
    // expires, which on a 120-minute session is most of a clinic.
    $admin = User::factory()->admin()->create();
    $clinician = User::factory()->create();

    DB::table('sessions')->insert([
        'id' => 'session-under-test',
        'user_id' => $clinician->id,
        'payload' => '',
        'last_activity' => now()->timestamp,
    ]);

    app(DeactivateUser::class)($clinician, AccountStatus::DEPARTED, 'Rotated to another hospital', $admin);

    expect(DB::table('sessions')->where('user_id', $clinician->id)->count())->toBe(0);

    $this->withHeader('Origin', 'http://localhost:3000')
        ->actingAs($clinician->fresh())
        ->getJson('/api/me')
        ->assertForbidden()
        ->assertJsonPath('code', 'account_inactive');
});

it('revokes any outstanding invitation for that address', function () {
    $admin = User::factory()->admin()->create();
    $clinician = User::factory()->create(['email' => 'a.owusu@mdr.kath.org']);

    DB::table('invitations')->insert([
        'email' => 'A.Owusu@mdr.kath.org',
        'role' => 'clinician',
        'token_hash' => str_repeat('a', 64),
        'invited_by' => $admin->id,
        'expires_at' => now()->addDays(7),
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    app(DeactivateUser::class)($clinician, AccountStatus::DEPARTED, 'Rotated to another hospital', $admin);

    expect(DB::table('invitations')->whereNotNull('revoked_at')->count())->toBe(1);
});

it('writes exactly one audit row carrying the before and after values', function () {
    // Criterion 10.
    $admin = User::factory()->admin()->create();
    $clinician = User::factory()->create();

    app(DeactivateUser::class)($clinician, AccountStatus::SUSPENDED, 'Practising certificate lapsed', $admin);

    $entry = AuditEntry::where('action', 'user.deactivated')->sole();

    expect($entry->actor_id)->toBe($admin->id)
        ->and($entry->subject_id)->toBe($clinician->id)
        ->and($entry->context['before']['status'])->toBe(AccountStatus::ACTIVE)
        ->and($entry->context['after']['status'])->toBe(AccountStatus::SUSPENDED)
        ->and($entry->context['reason'])->toBe('Practising certificate lapsed');
});

it('refuses to deactivate the only active administrator', function () {
    // Criterion 6. A registry with no admins needs database surgery to recover.
    $onlyAdmin = User::factory()->admin()->create();

    expect(fn () => app(DeactivateUser::class)($onlyAdmin, AccountStatus::DEPARTED, 'Leaving the hospital'))
        ->toThrow(LastAdminException::class, 'Appoint another administrator');

    expect($onlyAdmin->fresh()->isActive())->toBeTrue();
});

it('allows an administrator to be deactivated once a second one exists', function () {
    $first = User::factory()->admin()->create();
    User::factory()->admin()->create();

    app(DeactivateUser::class)($first, AccountStatus::DEPARTED, 'Handed over to the new administrator');

    expect($first->fresh()->status)->toBe(AccountStatus::DEPARTED);
});

it('notes that there is no unfinished work to flag yet', function () {
    $admin = User::factory()->admin()->create();
    $clinician = User::factory()->create();

    app(DeactivateUser::class)($clinician, AccountStatus::DEPARTED, 'Rotated to another hospital', $admin);

    $entry = AuditEntry::where('action', 'user.deactivated')->sole();

    expect($entry->context['unfinished_work_flagged'])->toBe('administrations table not present');
})->skip(fn () => Schema::hasTable('administrations'), 'administrations exists; assert reassignment instead');
