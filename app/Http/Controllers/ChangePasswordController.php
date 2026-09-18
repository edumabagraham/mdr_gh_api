<?php

namespace App\Http\Controllers;

use App\Models\AuditEntry;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rules\Password;

/**
 * Changing your own password, knowing the current one.
 *
 * Distinct from the forgotten-password flow, which proves control of the
 * mailbox instead. Requiring the current password here is what stops an
 * unattended logged-in machine becoming a permanent account takeover.
 */
class ChangePasswordController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        $data = $request->validate([
            'current_password' => ['required', 'current_password'],
            'password' => ['required', 'confirmed', Password::defaults(), 'different:current_password'],
        ]);

        $user = $request->user();

        $user->forceFill(['password' => Hash::make($data['password'])])->save();

        // Every other device signed in as this person is dropped; this one
        // stays, because the person doing the changing is standing here.
        Auth::guard('web')->logoutOtherDevices($data['password']);

        $user->tokens()->delete();

        AuditEntry::record($request, 'auth.password_changed', 'user', $user->getKey());

        return response()->json(['message' => 'Your password has been changed.']);
    }
}
