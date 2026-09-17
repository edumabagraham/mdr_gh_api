<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Notifications\ResetPasswordWithCode;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\ValidationException;

/**
 * Forgotten-password flow, built on the `password_reset_tokens` table Laravel
 * ships with. The stored token is a hash of a six-digit code rather than a
 * random link token, to match the email verification flow: the user stays in
 * the tab they started in and types the code.
 */
class PasswordResetController extends Controller
{
    /**
     * Wrong codes tolerated per email address before the flow locks up.
     */
    private const MAX_CODE_ATTEMPTS = 5;

    /**
     * How long that lockout lasts, in seconds.
     */
    private const LOCKOUT_SECONDS = 900;

    /**
     * Mail a reset code.
     *
     * The response is deliberately identical whether or not the address is
     * registered — otherwise this endpoint becomes a way to enumerate which
     * addresses have accounts.
     */
    public function sendCode(Request $request): JsonResponse
    {
        $data = $request->validate([
            'email' => ['required', 'email'],
        ]);

        $user = User::where('email', $data['email'])->first();

        if ($user && ! $this->codeIssuedRecentlyTo($user->email)) {
            $code = (string) random_int(100000, 999999);

            DB::table('password_reset_tokens')->updateOrInsert(
                ['email' => $user->email],
                ['token' => Hash::make($code), 'created_at' => now()],
            );

            $user->notify(new ResetPasswordWithCode($code, $this->codeLifetimeInMinutes()));
        }

        return response()->json([
            'message' => 'If that address has an account, a reset code is on its way to it.',
        ]);
    }

    /**
     * Set a new password, given the emailed code.
     */
    public function reset(Request $request): JsonResponse
    {
        $data = $request->validate([
            'email' => ['required', 'email'],
            'code' => ['required', 'string', 'digits:6'],
            'password' => ['required', 'confirmed', Password::defaults()],
        ]);

        $throttleKey = 'reset-password:'.Str::lower($data['email']);

        if (RateLimiter::tooManyAttempts($throttleKey, self::MAX_CODE_ATTEMPTS)) {
            throw ValidationException::withMessages([
                'code' => 'Too many incorrect codes. Request a new one in a few minutes.',
            ]);
        }

        $user = User::where('email', $data['email'])->first();
        $record = DB::table('password_reset_tokens')->where('email', $data['email'])->first();

        if (! $user || ! $record || ! Hash::check($data['code'], $record->token)) {
            RateLimiter::hit($throttleKey, self::LOCKOUT_SECONDS);

            throw ValidationException::withMessages([
                'code' => 'That code is not correct.',
            ]);
        }

        if (Carbon::parse($record->created_at)->addMinutes($this->codeLifetimeInMinutes())->isPast()) {
            throw ValidationException::withMessages([
                'code' => 'That code has expired. Request a new one.',
            ]);
        }

        $user->forceFill([
            'password' => $data['password'],
            'remember_token' => Str::random(60),
        ])->save();

        DB::table('password_reset_tokens')->where('email', $data['email'])->delete();
        RateLimiter::clear($throttleKey);

        // Whoever knew the old password is logged out along with everyone else:
        // if the reset was prompted by a compromise, leaving those sessions
        // alive would defeat the point.
        DB::table('sessions')->where('user_id', $user->getKey())->delete();

        event(new PasswordReset($user));

        return response()->json(['message' => 'Your password has been reset. You can log in with it now.']);
    }

    /**
     * Whether a code was mailed to this address within the throttle window,
     * which keeps a repeated "forgot password" click from mailbombing someone.
     */
    private function codeIssuedRecentlyTo(string $email): bool
    {
        $throttleSeconds = (int) config('auth.passwords.users.throttle');

        if ($throttleSeconds <= 0) {
            return false;
        }

        $record = DB::table('password_reset_tokens')->where('email', $email)->first();

        return $record !== null
            && Carbon::parse($record->created_at)->addSeconds($throttleSeconds)->isFuture();
    }

    private function codeLifetimeInMinutes(): int
    {
        return (int) config('auth.passwords.users.expire');
    }
}
