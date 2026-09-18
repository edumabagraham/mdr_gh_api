<?php

namespace App\Http\Controllers;

use App\Access\AccountStatus;
use App\Http\Resources\UserResource;
use App\Models\AuditEntry;
use App\Models\Invitation;
use App\Models\User;
use Illuminate\Auth\Events\Registered;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\ValidationException;

/**
 * Turning an invitation into an account. The only endpoint in the application
 * that creates a user over HTTP, and the only one in this area reachable
 * without being signed in.
 */
class AcceptInvitationController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        $data = $request->validate([
            'token' => ['required', 'string'],
            'title' => ['nullable', Rule::in(User::TITLES)],
            'name' => ['required', 'string', 'max:255'],
            'password' => ['required', 'confirmed', Password::defaults()],
            'specialty' => ['nullable', 'string', 'max:80'],
            'grade' => ['nullable', 'string', 'max:60'],
            'department' => ['nullable', 'string', 'max:80'],
            'mdc_number' => ['nullable', 'string', 'max:40'],
        ]);

        $invitation = Invitation::where('token_hash', Invitation::hashToken($data['token']))->first();

        // One message for every failure: a caller probing tokens learns only
        // that this one does not work, not whether it ever existed.
        if (! $invitation || ! $invitation->isUsable()) {
            throw ValidationException::withMessages([
                'token' => 'This invitation is not valid. It may have expired, been used already, or been withdrawn.',
            ]);
        }

        $user = DB::transaction(function () use ($invitation, $data, $request): User {
            $user = User::create([
                'name' => $data['name'],
                'email' => $invitation->email,
                'password' => Hash::make($data['password']),
            ]);

            // Role comes from the invitation, never from the payload: the
            // person accepting does not get to choose what they are.
            $user->forceFill([
                'title' => $data['title'] ?? null,
                'role' => $invitation->role,
                'status' => AccountStatus::ACTIVE,
                'specialty' => $data['specialty'] ?? null,
                'grade' => $data['grade'] ?? null,
                'department' => $data['department'] ?? null,
                // Self-declared: credential_sighted_on is set by an admin who
                // has seen the certificate. A number typed into a form is a
                // record, not a verification.
                'mdc_number' => $data['mdc_number'] ?? null,
                'invited_by' => $invitation->invited_by,
                'invited_at' => $invitation->created_at,
            ])->save();

            $invitation->update(['accepted_at' => now(), 'user_id' => $user->getKey()]);

            AuditEntry::record($request, 'invitation.accepted', 'user', $user->getKey(), [
                'invitation_id' => $invitation->id,
                'role' => $invitation->role,
            ]);

            return $user;
        });

        // Accepting proves someone had the emailed link. Entering the code
        // proves they control the mailbox, which is a different claim, so the
        // account stays unverified until they do.
        event(new Registered($user));

        // Named explicitly: an authenticated request earlier in this process
        // leaves `sanctum` as the default guard, and a RequestGuard has no
        // concept of logging someone in.
        Auth::guard('web')->login($user);
        $request->session()->regenerate();

        return response()->json(UserResource::make($user)->resolve(), 201);
    }
}
