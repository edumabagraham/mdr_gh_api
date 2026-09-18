<?php

use App\Models\User;
use App\Models\Visit;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->withHeader('Origin', 'http://localhost:3000');
    $this->clinician = User::factory()->create();
    $this->actingAs($this->clinician);
});

it('walks a visit through the clinic and stamps each step', function () {
    $visit = Visit::factory()->create();

    $this->patchJson("/api/visits/{$visit->id}", ['status' => 'arrived'])
        ->assertOk()
        ->assertJsonPath('status', 'arrived');

    expect($visit->fresh()->arrived_at)->not->toBeNull();

    $this->patchJson("/api/visits/{$visit->id}", ['status' => 'in_progress'])->assertOk();

    expect($visit->fresh()->started_at)->not->toBeNull()
        ->and($visit->fresh()->clinician_id)->toBe($this->clinician->id);

    $this->patchJson("/api/visits/{$visit->id}", ['status' => 'complete'])->assertOk();

    expect($visit->fresh()->completed_at)->not->toBeNull()
        ->and($visit->fresh()->patient->date_last_seen->toDateString())->toBe(today()->toDateString());
});

it('refuses to skip a step', function () {
    $visit = Visit::factory()->create();

    $this->patchJson("/api/visits/{$visit->id}", ['status' => 'complete'])
        ->assertStatus(422)
        ->assertJsonValidationErrorFor('status');

    expect($visit->fresh()->status)->toBe('scheduled');
});

it('refuses to reopen a finished visit', function () {
    $visit = Visit::factory()->completedBy($this->clinician->id)->create();

    $this->patchJson("/api/visits/{$visit->id}", ['status' => 'arrived'])->assertStatus(422);

    expect($visit->fresh()->status)->toBe('complete');
});

it('refuses a status that is not a status', function () {
    $visit = Visit::factory()->create();

    $this->patchJson("/api/visits/{$visit->id}", ['status' => 'seen_ish'])->assertStatus(422);
});
