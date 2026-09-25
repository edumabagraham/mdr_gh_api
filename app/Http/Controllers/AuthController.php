<?php

namespace App\Http\Controllers;

use App\Http\Resources\UserResource;
use App\Models\AuditEntry;
use App\Models\User;
use Illuminate\Auth\Events\Registered;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\ValidationException;

class AuthController extends Controller
{
    /**
     * Create an account.
     *
     * No longer routed: anyone with an email address could otherwise create an
     * account on a system that holds patient records. It is kept because
     * invitation acceptance performs exactly these steps once it has checked
     * the token.
     */
    public function register(Request $request)
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'unique:users,email'],
            'password' => ['required', 'confirmed', Password::defaults()],
        ]);

        $user = User::create([
            'name' => $data['name'],
            'email' => $data['email'],
            'password' => Hash::make($data['password']),
        ]);

        // Mails the six-digit verification code: the framework's Registered
        // listener calls User::sendEmailVerificationNotification(), which this
        // application overrides to send a code instead of a signed link.
        event(new Registered($user));

        // The account is usable but unverified. The SPA reads the null
        // email_verified_at off this response and routes to /verify-email.
        Auth::login($user);
        $request->session()->regenerate();

        return response()->json($user, 201);
    }

    public function login(Request $request)
    {
        $data = $request->validate([
            'email' => ['required', 'email'],
            'password' => ['required'],
        ]);

        if (! Auth::attempt($data, $request->boolean('remember'))) {
            // The address attempted and the address only: never the password,
            // not even a fragment of it.
            AuditEntry::record($request, 'auth.login_failed', 'user', null, [
                'email' => $data['email'],
            ]);

            throw ValidationException::withMessages([
                'email' => 'These credentials do not match our records.',
            ]);
        }

        $request->session()->regenerate();

        // What the access review is conducted against.
        Auth::user()->forceFill(['last_login_at' => now()])->saveQuietly();

        AuditEntry::record($request, 'auth.login_succeeded', 'user', Auth::id());

        return response()->json(UserResource::make(Auth::user())->resolve());
    }

    public function logout(Request $request)
    {
        Auth::guard('web')->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return response()->noContent();
    }
}
