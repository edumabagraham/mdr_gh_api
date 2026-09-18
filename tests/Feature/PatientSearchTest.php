<?php

use App\Models\Patient;
use App\Models\PatientIdentifier;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->withHeader('Origin', 'http://localhost:3000');
    $this->actingAs(User::factory()->create());
});

it('finds a patient by registry number, however loosely it is typed', function () {
    $patient = Patient::factory()->create();
    $loose = strtolower(str_replace('-', '', $patient->registry_no));

    $this->getJson('/api/patients/search?q='.$loose)
        ->assertOk()
        ->assertJsonPath('matched_by', 'registry_no')
        ->assertJsonPath('results.0.registry_no', $patient->registry_no);
});

it('finds a patient by hospital folder number', function () {
    $patient = Patient::factory()->create();
    PatientIdentifier::factory()->for($patient)->create(['system' => 'folder', 'value' => '227845']);

    $this->getJson('/api/patients/search?q=227845')
        ->assertOk()
        ->assertJsonPath('matched_by', 'identifier')
        ->assertJsonPath('results.0.id', $patient->id);
});

it('finds a patient whose name was given in the other order', function () {
    $patient = Patient::factory()->named('Mensah', 'Kwame')->create();

    // Criterion 8: the clerk typed family name first, the record has it second.
    $this->getJson('/api/patients/search?q=mensah+kwame')
        ->assertOk()
        ->assertJsonPath('matched_by', 'name')
        ->assertJsonPath('results.0.id', $patient->id);
});

it('finds a patient by a near-miss spelling of the name', function () {
    $patient = Patient::factory()->named('Mensah', 'Kwame')->create();

    $this->getJson('/api/patients/search?q=kwame+mensa')
        ->assertOk()
        ->assertJsonPath('matched_by', 'name')
        ->assertJsonPath('results.0.id', $patient->id)
        ->assertJsonPath('results.0.similarity', fn ($similarity) => $similarity >= 0.6);
});

it('finds a patient by phone number when nothing else matches', function () {
    $patient = Patient::factory()->create(['phone_primary' => '+233201234567']);

    $this->getJson('/api/patients/search?q=%2B233201234567')
        ->assertOk()
        ->assertJsonPath('matched_by', 'phone')
        ->assertJsonPath('results.0.id', $patient->id);
});

it('still resolves a merged patient by their original registry number', function () {
    $survivor = Patient::factory()->named('Mensah', 'Kwame')->create();
    $merged = Patient::factory()->named('Mensah', 'Kwame')->mergedInto($survivor)->create();

    // Criterion 9: a number printed on a paper form years ago must not stop
    // resolving just because the record behind it was merged away.
    $this->getJson('/api/patients/search?q='.$merged->registry_no)
        ->assertOk()
        ->assertJsonPath('results.0.status', 'merged')
        ->assertJsonPath('results.0.merged_into.registry_no', $survivor->registry_no);
});

it('returns nothing rather than guessing when there is no match', function () {
    Patient::factory()->named('Mensah', 'Kwame')->create();

    $this->getJson('/api/patients/search?q=zzzzzzzz')
        ->assertOk()
        ->assertJsonPath('matched_by', null)
        ->assertJsonCount(0, 'results');
});

it('lets a clinician open a record enrolled by a different clinician', function () {
    // Criterion 13: access is not scoped by who enrolled the patient.
    $otherClinician = User::factory()->create();
    $patient = Patient::factory()->create(['enrolled_by' => $otherClinician->id]);

    $this->getJson("/api/patients/{$patient->registry_no}")
        ->assertOk()
        ->assertJsonPath('registry_no', $patient->registry_no);
});
