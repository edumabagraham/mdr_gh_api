<?php

use App\Models\EmailVerificationCode;
use App\Models\User;
use App\Notifications\VerifyEmailWithCode;
use Illuminate\Auth\Events\Registered;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;

uses(RefreshDatabase::class);

/**
 * Create an unverified account and return the code that was mailed to it.
 *
 * Fires Registered rather than posting to a route: self-registration is gone,
 * and this is the path invitation acceptance takes once it has checked a token.
 */
function registerAndCaptureCode(): string
{
    $code = null;

    $user = User::factory()->unverified()->create([
        'name' => 'Ama Mensah',
        'email' => 'ama@example.com',
    ]);

    event(new Registered($user));

    Notification::assertSentTo(
        $user,
        VerifyEmailWithCode::class,
        function (VerifyEmailWithCode $notification) use (&$code) {
            $code = $notification->code;

            return true;
        },
    );

    return $code;
}

beforeEach(function () {
    Notification::fake();

    // Sanctum only attaches a session to requests that look like they came
    // from the SPA, and these endpoints log the user in through that session.
    $this->withHeader('Origin', 'http://localhost:3000');
});

it('mails a six digit code on registration and leaves the account unverified', function () {
    $code = registerAndCaptureCode();

    expect($code)->toMatch('/^\d{6}$/');

    $user = User::where('email', 'ama@example.com')->sole();

    expect($user->hasVerifiedEmail())->toBeFalse()
        ->and($user->emailVerificationCode)->not->toBeNull()
        ->and($user->emailVerificationCode->code_hash)->not->toBe($code);
});

it('verifies the account when the correct code is submitted', function () {
    $code = registerAndCaptureCode();
    $user = User::where('email', 'ama@example.com')->sole();

    $this->actingAs($user)
        ->postJson('/api/email/verify', ['code' => $code])
        ->assertOk()
        ->assertJsonPath('email', 'ama@example.com');

    expect($user->fresh()->hasVerifiedEmail())->toBeTrue()
        ->and(EmailVerificationCode::count())->toBe(0);
});

it('rejects a wrong code and counts the attempt', function () {
    $code = registerAndCaptureCode();
    $user = User::where('email', 'ama@example.com')->sole();

    $this->actingAs($user)
        ->postJson('/api/email/verify', ['code' => $code === '000000' ? '111111' : '000000'])
        ->assertStatus(422)
        ->assertJsonValidationErrorFor('code');

    expect($user->fresh()->hasVerifiedEmail())->toBeFalse()
        ->and($user->emailVerificationCode->attempts)->toBe(1);
});

it('stops accepting codes once the attempt limit is reached', function () {
    $code = registerAndCaptureCode();
    $user = User::where('email', 'ama@example.com')->sole();

    $user->emailVerificationCode->update(['attempts' => EmailVerificationCode::MAX_ATTEMPTS]);

    $this->actingAs($user)
        ->postJson('/api/email/verify', ['code' => $code])
        ->assertStatus(422);

    expect($user->fresh()->hasVerifiedEmail())->toBeFalse();
});

it('rejects an expired code', function () {
    $code = registerAndCaptureCode();
    $user = User::where('email', 'ama@example.com')->sole();

    $user->emailVerificationCode->update(['expires_at' => now()->subMinute()]);

    $this->actingAs($user)
        ->postJson('/api/email/verify', ['code' => $code])
        ->assertStatus(422);

    expect($user->fresh()->hasVerifiedEmail())->toBeFalse();
});

it('replaces the outstanding code when a new one is requested', function () {
    $firstCode = registerAndCaptureCode();
    $user = User::where('email', 'ama@example.com')->sole();

    $this->actingAs($user)->postJson('/api/email/resend')->assertOk();

    $codes = [];

    Notification::assertSentTo($user, VerifyEmailWithCode::class, function ($notification) use (&$codes) {
        $codes[] = $notification->code;

        return true;
    });

    $secondCode = end($codes);

    expect($secondCode)->not->toBe($firstCode);

    $this->actingAs($user)
        ->postJson('/api/email/verify', ['code' => $firstCode])
        ->assertStatus(422);

    $this->actingAs($user)
        ->postJson('/api/email/verify', ['code' => $secondCode])
        ->assertOk();
});

it('refuses the verification endpoints to guests', function () {
    $this->postJson('/api/email/verify', ['code' => '123456'])->assertUnauthorized();
    $this->postJson('/api/email/resend')->assertUnauthorized();
});

it('no longer offers a public registration route', function () {
    // Criterion 1: an account on a system holding patient records is created
    // by invitation, never by whoever finds the URL.
    $this->postJson('/api/register', [
        'name' => 'Uninvited Person',
        'email' => 'uninvited@example.com',
        'password' => 'correct-horse-battery',
        'password_confirmation' => 'correct-horse-battery',
    ])->assertNotFound();

    expect(User::where('email', 'uninvited@example.com')->exists())->toBeFalse();
});
