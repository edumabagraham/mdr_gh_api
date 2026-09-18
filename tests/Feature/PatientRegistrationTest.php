<?php

use App\Models\AuditEntry;
use App\Models\Patient;
use App\Models\PatientIdentifier;
use App\Models\User;
use App\Registry\RegistryNumber;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->withHeader('Origin', 'http://localhost:3000');
    $this->clinician = User::factory()->create();
    $this->actingAs($this->clinician);
});

/**
 * The proposed patient, as the SPA would send it.
 *
 * @return array<string, mixed>
 */
function proposedPatient(array $overrides = []): array
{
    return array_merge([
        'family_name' => 'Mensah',
        'given_name' => 'Kwame',
        'sex' => 'male',
        'date_of_birth' => '1964-03-02',
        'identifiers' => [['system' => 'folder', 'value' => '227845']],
    ], $overrides);
}

/** Run the duplicate check and return the whole response. */
function runDuplicateCheck(array $patient): array
{
    return test()->postJson('/api/patients/duplicate-check', $patient)
        ->assertOk()
        ->json();
}

it('registers a patient after a clear duplicate check', function () {
    $patient = proposedPatient();
    $check = runDuplicateCheck($patient);

    expect($check['verdict'])->toBe('clear');

    $response = $this->postJson('/api/patients', [
        ...$patient,
        'client_ref' => (string) Str::uuid(),
        'dob_estimated' => false,
        'duplicate_check_token' => $check['duplicate_check_token'],
        'duplicate_decision' => 'no_match',
    ])->assertCreated();

    $registryNo = $response->json('registry_no');

    expect(RegistryNumber::isValid($registryNo))->toBeTrue()
        ->and(Patient::count())->toBe(1)
        ->and(PatientIdentifier::where('value', '227845')->exists())->toBeTrue()
        ->and(Patient::sole()->name_normalised)->toBe('kwame mensah')
        ->and(Patient::sole()->enrolled_by)->toBe($this->clinician->id);
});

it('refuses to register without a duplicate check token', function () {
    $this->postJson('/api/patients', [
        ...proposedPatient(),
        'client_ref' => (string) Str::uuid(),
        'dob_estimated' => false,
        'duplicate_decision' => 'no_match',
    ])->assertStatus(422)->assertJsonValidationErrorFor('duplicate_check_token');

    expect(Patient::count())->toBe(0);
});

it('refuses a token that was issued for a different patient', function () {
    $check = runDuplicateCheck(proposedPatient());

    $this->postJson('/api/patients', [
        ...proposedPatient(['family_name' => 'Boateng', 'given_name' => 'Akosua']),
        'client_ref' => (string) Str::uuid(),
        'dob_estimated' => false,
        'duplicate_check_token' => $check['duplicate_check_token'],
        'duplicate_decision' => 'no_match',
    ])->assertStatus(422)->assertJsonValidationErrorFor('duplicate_check_token');

    expect(Patient::count())->toBe(0);
});

it('blocks registration when a hospital identifier already exists', function () {
    $existing = Patient::factory()->named('Mensah', 'Kwame')->create();
    PatientIdentifier::factory()->for($existing)->create(['system' => 'folder', 'value' => '227845']);

    $check = runDuplicateCheck(proposedPatient());

    expect($check['verdict'])->toBe('block')
        ->and($check['candidates'][0]['patient']['registry_no'])->toBe($existing->registry_no)
        ->and($check['candidates'][0]['reasons'])->toContain('identifier_match');

    $this->postJson('/api/patients', [
        ...proposedPatient(),
        'client_ref' => (string) Str::uuid(),
        'dob_estimated' => false,
        'duplicate_check_token' => $check['duplicate_check_token'],
        'duplicate_decision' => 'new_person_despite_match',
    ])->assertStatus(422);

    expect(Patient::count())->toBe(1);
});

it('warns on a similar name with a nearby date of birth and needs an explicit decision', function () {
    Patient::factory()->named('Mensa', 'Kwame')->create(['date_of_birth' => '1964-08-11']);

    $proposed = proposedPatient(['identifiers' => [['system' => 'folder', 'value' => '998877']]]);
    $check = runDuplicateCheck($proposed);

    expect($check['verdict'])->toBe('warn')
        ->and($check['candidates'][0]['reasons'])->toContain('name_similarity');

    $body = [
        ...$proposed,
        'client_ref' => (string) Str::uuid(),
        'dob_estimated' => false,
        'duplicate_check_token' => $check['duplicate_check_token'],
    ];

    // Saying "no match" in the face of a warning is not a decision the server
    // accepts: the clerk has to state that this is a different person.
    $this->postJson('/api/patients', [...$body, 'duplicate_decision' => 'no_match'])
        ->assertStatus(422)
        ->assertJsonValidationErrorFor('duplicate_decision');

    $this->postJson('/api/patients', [...$body, 'duplicate_decision' => 'new_person_despite_match'])
        ->assertCreated();

    expect(Patient::count())->toBe(2)
        ->and(Patient::latest('id')->first()->duplicate_check)
        ->toMatchArray(['verdict' => 'warn', 'decision' => 'new_person_despite_match']);
});

it('creates exactly one patient when the same client_ref is sent twice', function () {
    $patient = proposedPatient();
    $check = runDuplicateCheck($patient);
    $clientRef = (string) Str::uuid();

    $body = [
        ...$patient,
        'client_ref' => $clientRef,
        'dob_estimated' => false,
        'duplicate_check_token' => $check['duplicate_check_token'],
        'duplicate_decision' => 'no_match',
    ];

    $first = $this->postJson('/api/patients', $body)->assertCreated()->json('registry_no');
    $second = $this->postJson('/api/patients', $body)->assertCreated()->json('registry_no');

    expect($second)->toBe($first)
        ->and(Patient::count())->toBe(1);
});

it('accepts a registration with no date of birth when an age is given', function () {
    $proposed = proposedPatient(['date_of_birth' => null, 'age' => 62]);
    $check = runDuplicateCheck($proposed);

    $this->postJson('/api/patients', [
        ...$proposed,
        'client_ref' => (string) Str::uuid(),
        'dob_estimated' => true,
        'duplicate_check_token' => $check['duplicate_check_token'],
        'duplicate_decision' => 'no_match',
    ])->assertCreated()->assertJsonPath('dob_estimated', true);

    $stored = Patient::sole();

    // Stored as a date, flagged estimated — never as a number that rots.
    expect($stored->dob_estimated)->toBeTrue()
        ->and($stored->date_of_birth->year)->toBe(now()->subYears(62)->year)
        ->and($stored->date_of_birth->format('m-d'))->toBe('01-01');
});

it('accepts a missing folder number when a coded reason is given', function () {
    $proposed = proposedPatient(['identifiers' => []]);
    $check = runDuplicateCheck($proposed);

    $body = [
        ...$proposed,
        'client_ref' => (string) Str::uuid(),
        'dob_estimated' => false,
        'duplicate_check_token' => $check['duplicate_check_token'],
        'duplicate_decision' => 'no_match',
    ];

    $this->postJson('/api/patients', $body)
        ->assertStatus(422)
        ->assertJsonValidationErrorFor('folder_absent_reason');

    $this->postJson('/api/patients', [...$body, 'folder_absent_reason' => 'not_yet_issued'])
        ->assertCreated();

    expect(Patient::sole()->folder_absent_reason)->toBe('not_yet_issued');
});

it('writes an audit entry when a patient record is read', function () {
    $patient = Patient::factory()->create();

    $this->getJson("/api/patients/{$patient->registry_no}")->assertOk();

    $entry = AuditEntry::where('action', 'patient.viewed')->sole();

    expect($entry->subject_id)->toBe($patient->id)
        ->and($entry->actor_id)->toBe($this->clinician->id);
});

it('keeps patient endpoints closed to guests and unverified accounts', function () {
    auth()->logout();
    $this->postJson('/api/patients/duplicate-check', proposedPatient())->assertUnauthorized();

    // Laravel's EnsureEmailIsVerified answers a JSON request with 403.
    $this->actingAs(User::factory()->unverified()->create());
    $this->postJson('/api/patients/duplicate-check', proposedPatient())->assertForbidden();
});
