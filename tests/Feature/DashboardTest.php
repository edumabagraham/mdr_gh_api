<?php

use App\Models\Patient;
use App\Models\User;
use App\Models\Visit;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->withHeader('Origin', 'http://localhost:3000');
    $this->clinician = User::factory()->create();
    $this->actingAs($this->clinician);
});

it('returns all four sections in one request', function () {
    $this->getJson('/api/dashboard')
        ->assertOk()
        ->assertJsonStructure([
            'generated_at',
            'todays_clinic' => ['count', 'waiting', 'items'],
            'unfinished_work' => ['count', 'items'],
            'overdue_followup' => ['count', 'items'],
            'recently_seen' => ['count', 'items'],
        ]);
});

it('counts who is booked today and how long the waiting have waited', function () {
    Visit::factory()->count(2)->create();
    Visit::factory()->arrived()->create();
    Visit::factory()->create(['scheduled_for' => today()->addDay()]);

    $response = $this->getJson('/api/dashboard')->assertOk();

    expect($response->json('todays_clinic.count'))->toBe(3)
        ->and($response->json('todays_clinic.waiting'))->toBe(1);

    $waiting = collect($response->json('todays_clinic.items'))->firstWhere('status', 'arrived');

    expect($waiting['waiting_minutes'])->toBeGreaterThanOrEqual(22);
});

it('lists overdue follow-ups oldest first', function () {
    Visit::factory()->overdueBy(10)->create();
    Visit::factory()->overdueBy(138)->create();

    $items = $this->getJson('/api/dashboard')->assertOk()->json('overdue_followup.items');

    expect($items[0]['days_overdue'])->toBe(138)
        ->and($items[1]['days_overdue'])->toBe(10);
});

it('shows only this clinician in recently seen', function () {
    $someoneElse = User::factory()->create();

    Visit::factory()->completedBy($this->clinician->id)->create();
    Visit::factory()->completedBy($someoneElse->id)->create();

    $response = $this->getJson('/api/dashboard')->assertOk();

    expect($response->json('recently_seen.count'))->toBe(1);
});

it('ships an empty unfinished-work section until the instrument tables exist', function () {
    $this->getJson('/api/dashboard')
        ->assertOk()
        ->assertJsonPath('unfinished_work.count', 0)
        ->assertJsonPath('unfinished_work.items', []);
});

it('reports the signed-in user and their role', function () {
    $this->getJson('/api/me')
        ->assertOk()
        ->assertJsonPath('id', $this->clinician->id)
        ->assertJsonPath('role', 'clinician');
});

it('seeds the offline cache with a compact patient list', function () {
    $patient = Patient::factory()->create();
    $patient->identifiers()->create(['system' => 'folder', 'value' => '227845', 'is_primary' => true]);

    $this->getJson('/api/sync/patients')
        ->assertOk()
        ->assertJsonPath('patients.0.registry_no', $patient->registry_no)
        ->assertJsonPath('patients.0.primary_identifier', '227845')
        ->assertJsonPath('has_more', false);
});
