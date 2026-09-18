<?php

use App\Access\Permission;
use App\Access\Role;
use App\Models\Patient;
use App\Models\User;
use App\Models\Visit;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->withHeader('Origin', 'http://localhost:3000');
});

it('grants each role exactly the permissions in the matrix', function (string $role, array $expected) {
    $user = User::factory()->role($role)->create();

    foreach (Permission::all() as $permission) {
        $allowed = Gate::forUser($user)->allows($permission);

        expect($allowed)->toBe(
            in_array($permission, $expected, true),
            "{$role} / {$permission}",
        );
    }
})->with([
    'admin' => [Role::ADMIN, [
        Permission::INVITE_USERS, Permission::CHANGE_ROLES, Permission::VIEW_AUDIT,
    ]],
    'clinician' => [Role::CLINICIAN, [
        Permission::READ_PATIENTS, Permission::REGISTER_PATIENTS, Permission::RECORD_ASSESSMENTS,
        Permission::ADMINISTER_QUESTIONNAIRES, Permission::CORRECT_DATA,
    ]],
    'research assistant' => [Role::RESEARCH_ASSISTANT, [
        Permission::READ_PATIENTS, Permission::REGISTER_PATIENTS, Permission::ADMINISTER_QUESTIONNAIRES,
    ]],
    'data manager' => [Role::DATA_MANAGER, [
        Permission::VIEW_AUDIT, Permission::READ_PATIENTS, Permission::CORRECT_DATA, Permission::EXPORT_DATA,
    ]],
]);

it('refuses an admin every patient endpoint', function () {
    // Criterion 8. Separation of duties: administering accounts carries no
    // clinical reason to read the registry.
    $patient = Patient::factory()->create();
    $visit = Visit::factory()->create();

    $this->actingAs(User::factory()->admin()->create());

    $this->getJson('/api/dashboard')->assertForbidden();
    $this->getJson('/api/patients/search?q=mensah')->assertForbidden();
    $this->getJson("/api/patients/{$patient->registry_no}")->assertForbidden();
    $this->getJson('/api/sync/patients')->assertForbidden();
    $this->postJson('/api/patients/duplicate-check', [
        'family_name' => 'Mensah', 'given_name' => 'Kwame',
    ])->assertForbidden();
    $this->patchJson("/api/visits/{$visit->id}", ['status' => 'arrived'])->assertForbidden();
});

it('lets a research assistant register a patient but not record an assessment', function () {
    // Criterion 9. The assessment endpoints belong to a later slice, so the
    // half that exists is asserted over HTTP and the half that does not is
    // asserted at the gate that will guard it.
    $assistant = User::factory()->researchAssistant()->create();
    $this->actingAs($assistant);

    $this->postJson('/api/patients/duplicate-check', [
        'family_name' => 'Mensah', 'given_name' => 'Kwame',
    ])->assertOk();

    expect(Gate::forUser($assistant)->allows(Permission::REGISTER_PATIENTS))->toBeTrue()
        ->and(Gate::forUser($assistant)->allows(Permission::RECORD_ASSESSMENTS))->toBeFalse();
});

it('lets a data manager read the registry but not register into it', function () {
    $this->actingAs(User::factory()->dataManager()->create());

    $this->getJson('/api/dashboard')->assertOk();

    $this->postJson('/api/patients/duplicate-check', [
        'family_name' => 'Mensah', 'given_name' => 'Kwame',
    ])->assertForbidden();
});

it('tells the client who it is dealing with', function () {
    $user = User::factory()->researchAssistant()->create();

    $this->actingAs($user)->getJson('/api/me')
        ->assertOk()
        ->assertJsonPath('role', Role::RESEARCH_ASSISTANT);
});
