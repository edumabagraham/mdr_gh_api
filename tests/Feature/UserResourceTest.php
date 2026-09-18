<?php

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

$expected = ['id', 'title', 'name', 'display_name', 'email', 'role', 'status', 'specialty', 'email_verified_at'];

it('returns only the account fields the client needs, on login', function () use ($expected) {
    $user = User::factory()->create([
        'email' => 'clinician1@mdr.kath.org',
        'password' => Hash::make('correct-horse-battery'),
        'specialty' => 'Neurology',
        'mdc_number' => 'MDC/RN/12345',
    ]);

    $body = $this->withHeader('Origin', 'http://localhost:3000')
        ->postJson('/api/login', [
            'email' => $user->email,
            'password' => 'correct-horse-battery',
        ])->assertOk()->json();

    expect(array_keys($body))->toEqualCanonicalizing($expected)
        ->and($body['specialty'])->toBe('Neurology');
});

it('returns the same shape from /api/user', function () use ($expected) {
    $user = User::factory()->create(['specialty' => 'Neurology']);

    $body = $this->withHeader('Origin', 'http://localhost:3000')
        ->actingAs($user)
        ->getJson('/api/user')->assertOk()->json();

    expect(array_keys($body))->toEqualCanonicalizing($expected);
});

it('never ships the access-control columns to the browser', function () {
    $user = User::factory()->create(['mdc_number' => 'MDC/RN/12345']);

    $body = $this->withHeader('Origin', 'http://localhost:3000')
        ->actingAs($user)
        ->getJson('/api/user')->assertOk();

    foreach (['password', 'remember_token', 'mdc_number', 'invited_by', 'deactivated_by', 'deactivation_reason', 'last_active_at'] as $field) {
        $body->assertJsonMissingPath($field);
    }
});
