<?php

namespace App\Http\Controllers;

use App\Models\EmailVerificationCode;
use Illuminate\Auth\Events\Verified;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class EmailVerificationController extends Controller
{
    /**
     * Confirm an email address with the six-digit code that was mailed out.
     *
     * Failures are returned as 422 validation errors keyed by `code`, so the
     * SPA renders them the same way it renders any other field error.
     */
    public function verify(Request $request): JsonResponse
    {
        $data = $request->validate([
            'code' => ['required', 'string', 'digits:6'],
        ]);

        $user = $request->user();

        if ($user->hasVerifiedEmail()) {
            return response()->json($user);
        }

        $verificationCode = $user->emailVerificationCode;

        if (! $verificationCode || $verificationCode->isExpired()) {
            throw ValidationException::withMessages([
                'code' => 'That code has expired. Send yourself a new one.',
            ]);
        }

        if ($verificationCode->hasTooManyAttempts()) {
            throw ValidationException::withMessages([
                'code' => 'Too many incorrect attempts. Send yourself a new code.',
            ]);
        }

        if (! $verificationCode->matches($data['code'])) {
            $verificationCode->increment('attempts');

            $remaining = EmailVerificationCode::MAX_ATTEMPTS - $verificationCode->attempts;

            throw ValidationException::withMessages([
                'code' => $remaining > 0
                    ? "That code is not correct. {$remaining} attempts left."
                    : 'Too many incorrect attempts. Send yourself a new code.',
            ]);
        }

        if ($user->markEmailAsVerified()) {
            event(new Verified($user));
        }

        $verificationCode->delete();

        return response()->json($user->fresh());
    }

    /**
     * Mail a fresh code, invalidating whichever code was outstanding.
     */
    public function resend(Request $request): JsonResponse
    {
        $user = $request->user();

        if ($user->hasVerifiedEmail()) {
            return response()->json(['message' => 'Your email address is already verified.']);
        }

        $user->sendEmailVerificationNotification();

        return response()->json(['message' => 'We sent a new code to '.$user->email.'.']);
    }
}
