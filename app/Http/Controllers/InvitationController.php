<?php

namespace App\Http\Controllers;

use App\Access\AccountStatus;
use App\Access\Role;
use App\Models\AuditEntry;
use App\Models\Invitation;
use App\Models\User;
use App\Notifications\InvitationIssued;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Administering invitations. The accept half lives in
 * {@see AcceptInvitationController} because it is the one part that has to be
 * reachable without an account.
 */
class InvitationController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $data = $request->validate([
            'status' => ['nullable', Rule::in(['pending', 'accepted', 'revoked', 'expired'])],
        ]);

        $invitations = Invitation::with(['invitedBy', 'user'])
            ->latest('id')
            ->get()
            ->when(
                isset($data['status']),
                fn ($collection) => $collection->filter(fn (Invitation $i) => $i->status() === $data['status']),
            );

        return response()->json([
            'invitations' => $invitations->map(fn (Invitation $invitation) => $this->present($invitation))
                ->values()
                ->all(),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'email' => ['required', 'email', 'max:255'],
            'role' => ['required', Rule::in(Role::all())],
        ]);

        $email = mb_strtolower($data['email']);

        $existing = User::whereRaw('lower(email) = ?', [$email])->first();

        if ($existing && $existing->status === AccountStatus::ACTIVE) {
            throw ValidationException::withMessages([
                'email' => 'That address already has an active account.',
            ]);
        }

        $token = Invitation::generateToken();

        $invitation = DB::transaction(function () use ($data, $email, $token, $request): Invitation {
            // Only one invitation per address may be live, so re-inviting
            // replaces rather than duplicates. The old token stops working.
            Invitation::pending()
                ->whereRaw('lower(email) = ?', [$email])
                ->update(['revoked_at' => now(), 'revoked_by' => $request->user()->getKey()]);

            $invitation = Invitation::create([
                'email' => $data['email'],
                'role' => $data['role'],
                'token_hash' => Invitation::hashToken($token),
                'invited_by' => $request->user()->getKey(),
                'expires_at' => now()->addDays(Invitation::LIFETIME_DAYS),
            ]);

            AuditEntry::record($request, 'user.invited', 'invitation', $invitation->id, [
                'email' => $data['email'],
                'role' => $data['role'],
            ]);

            return $invitation;
        });

        Notification::route('mail', $data['email'])
            ->notify(new InvitationIssued($invitation, $token, $request->user()->name));

        // The token is deliberately absent from this response: the email is the
        // only place it exists.
        return response()->json($this->present($invitation->fresh(['invitedBy'])), 201);
    }

    /**
     * Send the invitation again, to someone who lost the email or never opened
     * it.
     *
     * A reminder cannot repeat the original token — only its hash was kept —
     * so this issues a fresh one and restarts the seven days. The previous
     * link stops working, which is the right outcome for a link that has been
     * sitting in an inbox for a week.
     */
    public function resend(Request $request, Invitation $invitation): JsonResponse
    {
        if ($invitation->accepted_at !== null || $invitation->revoked_at !== null) {
            throw ValidationException::withMessages([
                'invitation' => 'That invitation has already been '.$invitation->status().'.',
            ]);
        }

        $token = Invitation::generateToken();

        $invitation->update([
            'token_hash' => Invitation::hashToken($token),
            'expires_at' => now()->addDays(Invitation::LIFETIME_DAYS),
        ]);

        AuditEntry::record($request, 'invitation.resent', 'invitation', $invitation->id, [
            'email' => $invitation->email,
        ]);

        Notification::route('mail', $invitation->email)
            ->notify(new InvitationIssued($invitation, $token, $request->user()->name));

        return response()->json($this->present($invitation->fresh(['invitedBy'])));
    }

    public function destroy(Request $request, Invitation $invitation): JsonResponse
    {
        if (! $invitation->isPending()) {
            throw ValidationException::withMessages([
                'invitation' => 'That invitation has already been '.$invitation->status().'.',
            ]);
        }

        $invitation->update([
            'revoked_at' => now(),
            'revoked_by' => $request->user()->getKey(),
        ]);

        AuditEntry::record($request, 'invitation.revoked', 'invitation', $invitation->id, [
            'email' => $invitation->email,
        ]);

        return response()->json($this->present($invitation->fresh(['invitedBy'])));
    }

    /**
     * @return array<string, mixed>
     */
    private function present(Invitation $invitation): array
    {
        return [
            'id' => $invitation->id,
            'email' => $invitation->email,
            'role' => $invitation->role,
            'status' => $invitation->status(),
            'invited_by' => $invitation->invitedBy?->name,
            'expires_at' => $invitation->expires_at->toIso8601String(),
            'accepted_at' => $invitation->accepted_at?->toIso8601String(),
            'revoked_at' => $invitation->revoked_at?->toIso8601String(),
            'created_at' => $invitation->created_at?->toIso8601String(),
        ];
    }
}
