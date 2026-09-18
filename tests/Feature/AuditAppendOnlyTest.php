<?php

use App\Models\AuditEntry;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('records who did what, with the request context', function () {
    $user = User::factory()->create();

    $this->withHeader('Origin', 'http://localhost:3000')
        ->actingAs($user)
        ->getJson('/api/patients/search?q=mensah')
        ->assertOk();

    $entry = AuditEntry::where('action', 'patient.searched')->sole();

    expect($entry->actor_id)->toBe($user->id)
        ->and($entry->context['query'])->toBe('mensah')
        ->and($entry->ip_address)->not->toBeNull();
});

it('refuses to update an audit entry', function () {
    // Criterion 14: there is no code path that updates one of these rows, and
    // the model makes sure nobody writes one by accident.
    $entry = AuditEntry::create([
        'action' => 'user.invited',
        'subject_type' => 'user',
        'created_at' => now(),
    ]);

    expect(fn () => $entry->update(['action' => 'something.else']))
        ->toThrow(RuntimeException::class, 'append-only');
});

it('refuses to delete an audit entry', function () {
    $entry = AuditEntry::create(['action' => 'user.invited', 'created_at' => now()]);

    expect(fn () => $entry->delete())->toThrow(RuntimeException::class, 'append-only');

    expect(AuditEntry::count())->toBe(1);
});
