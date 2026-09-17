<?php

use App\Models\User;
use App\Notifications\ResetPasswordWithCode;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;

uses(RefreshDatabase::class);

/**
 * Ask for a reset code for the given user and return the code that was mailed.
 */
function requestResetCode(User $user): string
{
    $code = null;

    test()->postJson('/api/forgot-password', ['email' => $user->email])->assertOk();

    Notification::assertSentTo(
        $user,
        ResetPasswordWithCode::class,
        function (ResetPasswordWithCode $notification) use (&$code) {
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

it('mails a six digit code to a registered address', function () {
    $user = User::factory()->create(['email' => 'kofi@example.com']);

    expect(requestResetCode($user))->toMatch('/^\d{6}$/');

    expect(DB::table('password_reset_tokens')->where('email', $user->email)->exists())->toBeTrue();
});

it('gives the same answer for an unknown address and mails nothing', function () {
    $this->postJson('/api/forgot-password', ['email' => 'nobody@example.com'])
        ->assertOk()
        ->assertJsonPath('message', 'If that address has an account, a reset code is on its way to it.');

    Notification::assertNothingSent();
});

it('does not mail a second code while the throttle window is open', function () {
    $user = User::factory()->create();

    requestResetCode($user);
    $this->postJson('/api/forgot-password', ['email' => $user->email])->assertOk();

    Notification::assertSentToTimes($user, ResetPasswordWithCode::class, 1);
});

it('resets the password when the correct code is submitted', function () {
    $user = User::factory()->create(['password' => Hash::make('old-password')]);
    $code = requestResetCode($user);

    $this->postJson('/api/reset-password', [
        'email' => $user->email,
        'code' => $code,
        'password' => 'brand-new-password',
        'password_confirmation' => 'brand-new-password',
    ])->assertOk();

    expect(Hash::check('brand-new-password', $user->fresh()->password))->toBeTrue()
        ->and(DB::table('password_reset_tokens')->where('email', $user->email)->exists())->toBeFalse();
});

it('lets the user log in with the new password afterwards', function () {
    $user = User::factory()->create(['password' => Hash::make('old-password')]);
    $code = requestResetCode($user);

    $this->postJson('/api/reset-password', [
        'email' => $user->email,
        'code' => $code,
        'password' => 'brand-new-password',
        'password_confirmation' => 'brand-new-password',
    ])->assertOk();

    $this->postJson('/api/login', ['email' => $user->email, 'password' => 'old-password'])
        ->assertStatus(422);

    $this->postJson('/api/login', ['email' => $user->email, 'password' => 'brand-new-password'])
        ->assertOk();
});

it('rejects a wrong code and leaves the password alone', function () {
    $user = User::factory()->create(['password' => Hash::make('old-password')]);
    $code = requestResetCode($user);

    $this->postJson('/api/reset-password', [
        'email' => $user->email,
        'code' => $code === '000000' ? '111111' : '000000',
        'password' => 'brand-new-password',
        'password_confirmation' => 'brand-new-password',
    ])->assertStatus(422)->assertJsonValidationErrorFor('code');

    expect(Hash::check('old-password', $user->fresh()->password))->toBeTrue();
});

it('rejects an expired code', function () {
    $user = User::factory()->create(['password' => Hash::make('old-password')]);
    $code = requestResetCode($user);

    DB::table('password_reset_tokens')
        ->where('email', $user->email)
        ->update(['created_at' => now()->subMinutes(config('auth.passwords.users.expire') + 1)]);

    $this->postJson('/api/reset-password', [
        'email' => $user->email,
        'code' => $code,
        'password' => 'brand-new-password',
        'password_confirmation' => 'brand-new-password',
    ])->assertStatus(422);

    expect(Hash::check('old-password', $user->fresh()->password))->toBeTrue();
});

it('locks the address after repeated wrong codes', function () {
    // The route throttle would answer 429 first; this test is about the
    // application's own per-address lockout, so the throttle stands aside.
    $this->withoutMiddleware(ThrottleRequests::class);

    $user = User::factory()->create(['password' => Hash::make('old-password')]);
    $code = requestResetCode($user);

    $wrongCode = $code === '000000' ? '111111' : '000000';

    foreach (range(1, 5) as $ignored) {
        $this->postJson('/api/reset-password', [
            'email' => $user->email,
            'code' => $wrongCode,
            'password' => 'brand-new-password',
            'password_confirmation' => 'brand-new-password',
        ])->assertStatus(422);
    }

    // The correct code is refused too, until the lockout expires.
    $this->postJson('/api/reset-password', [
        'email' => $user->email,
        'code' => $code,
        'password' => 'brand-new-password',
        'password_confirmation' => 'brand-new-password',
    ])->assertStatus(422);

    expect(Hash::check('old-password', $user->fresh()->password))->toBeTrue();
});

it('throttles repeated requests for a code', function () {
    $user = User::factory()->create();

    foreach (range(1, 5) as $ignored) {
        $this->postJson('/api/forgot-password', ['email' => $user->email])->assertOk();
    }

    $this->postJson('/api/forgot-password', ['email' => $user->email])->assertStatus(429);
});
