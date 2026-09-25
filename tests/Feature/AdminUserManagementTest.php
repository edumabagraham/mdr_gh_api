<?php

use App\Access\AccountStatus;
use App\Access\Role;
use App\Http\Middleware\TouchLastActive;
use App\Models\AuditEntry;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->withHeader('Origin', 'http://localhost:3000');
    $this->admin = User::factory()->admin()->create();
});

it('lists every account, including the ones that left', function () {
    User::factory()->create(['last_login_at' => now()->subDays(3)]);
    User::factory()->deactivated()->create();

    $body = $this->actingAs($this->admin)->getJson('/api/admin/users')->assertOk()->json();

    expect($body['users'])->toHaveCount(3)
        ->and(collect($body['users'])->pluck('status'))->toContain(AccountStatus::DEPARTED);
});

it('orders the list by staleness, never-logged-in first', function () {
    // Criterion 12.
    $recent = User::factory()->create(['last_login_at' => now()->subDay()]);
    $stale = User::factory()->create(['last_login_at' => now()->subDays(200)]);
    $never = User::factory()->create(['last_login_at' => null]);

    $ids = collect($this->actingAs($this->admin)->getJson('/api/admin/access-review')->json('users'))
        ->pluck('id')
        ->all();

    expect(array_slice($ids, 0, 2))->toContain($never->id)
        ->and(array_search($stale->id, $ids, true))->toBeLessThan(array_search($recent->id, $ids, true));
});

it('counts what an access review is looking for', function () {
    User::factory()->create(['last_login_at' => null]);
    User::factory()->create(['last_login_at' => now()->subDays(120)]);
    User::factory()->create(['last_login_at' => now()->subDay(), 'mdc_expires_on' => now()->addDays(20)]);

    $this->actingAs($this->admin)->getJson('/api/admin/access-review')
        ->assertOk()
        ->assertJsonPath('never_logged_in', 2)   // the seeded admin has never logged in either
        ->assertJsonPath('stale_over_90_days', 1)
        ->assertJsonPath('credentials_expiring', 1);
});

it('filters by role, status and staleness', function () {
    User::factory()->dataManager()->create(['last_login_at' => now()->subDays(200)]);
    User::factory()->create(['last_login_at' => now()]);

    $this->actingAs($this->admin)->getJson('/api/admin/users?role='.Role::DATA_MANAGER)
        ->assertOk()->assertJsonCount(1, 'users');

    $this->actingAs($this->admin)->getJson('/api/admin/users?stale_days=90')
        ->assertOk()->assertJsonCount(2, 'users'); // the stale data manager and the never-seen admin
});

it('changes a role and records both sides of the change', function () {
    // Criterion 10.
    $user = User::factory()->create();

    $this->actingAs($this->admin)
        ->patchJson("/api/admin/users/{$user->id}", ['role' => Role::DATA_MANAGER])
        ->assertOk()
        ->assertJsonPath('role', Role::DATA_MANAGER);

    $entry = AuditEntry::where('action', 'user.role_changed')->sole();

    expect($entry->context['before']['role'])->toBe(Role::CLINICIAN)
        ->and($entry->context['after']['role'])->toBe(Role::DATA_MANAGER)
        ->and($entry->actor_id)->toBe($this->admin->id);
});

it('refuses to let anyone change their own role', function () {
    // Criterion 7.
    User::factory()->admin()->create();

    $this->actingAs($this->admin)
        ->patchJson("/api/admin/users/{$this->admin->id}", ['role' => Role::CLINICIAN])
        ->assertStatus(422)
        ->assertJsonValidationErrorFor('role');

    expect($this->admin->fresh()->role)->toBe(Role::ADMIN);
});

it('refuses to demote the last administrator', function () {
    $second = User::factory()->admin()->create();

    // With two admins the demotion is allowed...
    $this->actingAs($this->admin)
        ->patchJson("/api/admin/users/{$second->id}", ['role' => Role::CLINICIAN])
        ->assertOk();

    // ...but now there is only one left, and another admin cannot be conjured
    // to do it, so the remaining admin is stuck by design.
    $this->actingAs($this->admin)
        ->patchJson("/api/admin/users/{$this->admin->id}", ['role' => Role::CLINICIAN])
        ->assertStatus(422);
});

it('records who sighted a practising certificate', function () {
    $user = User::factory()->create();

    $this->actingAs($this->admin)
        ->patchJson("/api/admin/users/{$user->id}", [
            'mdc_number' => 'MDC/RN/12345',
            'credential_sighted_on' => today()->toDateString(),
        ])->assertOk();

    expect($user->fresh()->credential_sighted_by)->toBe($this->admin->id);
});

it('deactivates and reactivates over HTTP, each with a reason', function () {
    $user = User::factory()->create();

    $this->actingAs($this->admin)
        ->postJson("/api/admin/users/{$user->id}/deactivate", ['status' => AccountStatus::DEPARTED])
        ->assertStatus(422)
        ->assertJsonValidationErrorFor('reason');

    $this->actingAs($this->admin)
        ->postJson("/api/admin/users/{$user->id}/deactivate", [
            'status' => AccountStatus::DEPARTED,
            'reason' => 'Rotated to another hospital, September 2026',
        ])->assertOk()->assertJsonPath('status', AccountStatus::DEPARTED);

    $this->actingAs($this->admin)
        ->postJson("/api/admin/users/{$user->id}/reactivate", ['reason' => 'Returned to the service'])
        ->assertOk()
        ->assertJsonPath('status', AccountStatus::ACTIVE)
        ->assertJsonPath('deactivated_at', null);

    expect(AuditEntry::where('action', 'user.reactivated')->count())->toBe(1);
});

it('refuses to deactivate the last administrator over HTTP', function () {
    // Criterion 6, through the endpoint rather than the service.
    $this->actingAs($this->admin)
        ->postJson("/api/admin/users/{$this->admin->id}/deactivate", [
            'status' => AccountStatus::DEPARTED,
            'reason' => 'Leaving the hospital at the end of the month',
        ])
        ->assertStatus(422)
        ->assertJsonValidationErrorFor('status');

    expect($this->admin->fresh()->isActive())->toBeTrue();
});

it('keeps user administration to admins', function () {
    $clinician = User::factory()->create();

    $this->actingAs($clinician)->getJson('/api/admin/users')->assertForbidden();
    $this->actingAs($clinician)->getJson('/api/admin/access-review')->assertForbidden();
    $this->actingAs($clinician)
        ->patchJson("/api/admin/users/{$this->admin->id}", ['role' => Role::CLINICIAN])
        ->assertForbidden();
});

it('stamps last_login_at and audits the attempt', function () {
    $user = User::factory()->create(['password' => Hash::make('the-password')]);

    $this->postJson('/api/login', ['email' => $user->email, 'password' => 'wrong'])
        ->assertStatus(422);

    $this->postJson('/api/login', ['email' => $user->email, 'password' => 'the-password'])
        ->assertOk();

    expect($user->fresh()->last_login_at)->not->toBeNull()
        ->and(AuditEntry::where('action', 'auth.login_failed')->sole()->context['email'])->toBe($user->email)
        ->and(AuditEntry::where('action', 'auth.login_succeeded')->count())->toBe(1);
});

it('writes last_active_at at most once per interval', function () {
    // Criterion 13.
    $user = User::factory()->create();

    $this->actingAs($user)->getJson('/api/me')->assertOk();

    $first = $user->fresh()->last_active_at;

    expect($first)->not->toBeNull();

    $this->travel(5)->minutes();
    $this->actingAs($user)->getJson('/api/me')->assertOk();

    expect($user->fresh()->last_active_at->eq($first))->toBeTrue();

    $this->travel(TouchLastActive::INTERVAL_MINUTES + 1)->minutes();
    $this->actingAs($user)->getJson('/api/me')->assertOk();

    expect($user->fresh()->last_active_at->gt($first))->toBeTrue();
});
