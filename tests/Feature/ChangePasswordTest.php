<?php

use App\Models\AuditEntry;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->withHeader('Origin', 'http://localhost:3000');
    $this->user = User::factory()->create(['password' => Hash::make('the-old-password')]);
    $this->actingAs($this->user);
});

it('changes the password when the current one is given', function () {
    $this->postJson('/api/password', [
        'current_password' => 'the-old-password',
        'password' => 'a-brand-new-password',
        'password_confirmation' => 'a-brand-new-password',
    ])->assertOk();

    expect(Hash::check('a-brand-new-password', $this->user->fresh()->password))->toBeTrue()
        ->and(AuditEntry::where('action', 'auth.password_changed')->count())->toBe(1);
});

it('refuses without the current password', function () {
    $this->postJson('/api/password', [
        'current_password' => 'not-the-old-password',
        'password' => 'a-brand-new-password',
        'password_confirmation' => 'a-brand-new-password',
    ])->assertStatus(422)->assertJsonValidationErrorFor('current_password');

    expect(Hash::check('the-old-password', $this->user->fresh()->password))->toBeTrue();
});

it('refuses to set the same password again', function () {
    $this->postJson('/api/password', [
        'current_password' => 'the-old-password',
        'password' => 'the-old-password',
        'password_confirmation' => 'the-old-password',
    ])->assertStatus(422)->assertJsonValidationErrorFor('password');
});

it('is closed to guests', function () {
    auth()->guard('web')->logout();
    app('auth')->forgetGuards();

    $this->postJson('/api/password', [
        'current_password' => 'the-old-password',
        'password' => 'a-brand-new-password',
        'password_confirmation' => 'a-brand-new-password',
    ])->assertUnauthorized();
});
