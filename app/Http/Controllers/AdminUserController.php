<?php

namespace App\Http\Controllers;

use App\Access\AccountStatus;
use App\Access\DeactivateUser;
use App\Access\LastAdminException;
use App\Access\ReactivateUser;
use App\Access\Role;
use App\Http\Resources\AdminUserResource;
use App\Models\AuditEntry;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class AdminUserController extends Controller
{
    public function __construct(
        private readonly DeactivateUser $deactivate,
        private readonly ReactivateUser $reactivate,
    ) {}

    /**
     * Everyone, including departed accounts: a list that hides the people who
     * left cannot answer "who has ever had access to this registry".
     */
    public function index(Request $request): JsonResponse
    {
        $filters = $request->validate([
            'role' => ['nullable', Rule::in(Role::all())],
            'status' => ['nullable', Rule::in(AccountStatus::all())],
            'stale_days' => ['nullable', 'integer', 'min:1', 'max:3650'],
            'never_logged_in' => ['nullable', 'boolean'],
        ]);

        $users = User::with('invitedBy')
            ->when($filters['role'] ?? null, fn ($query, $role) => $query->where('role', $role))
            ->when($filters['status'] ?? null, fn ($query, $status) => $query->where('status', $status))
            ->when(
                $filters['stale_days'] ?? null,
                fn ($query, $days) => $query->where(fn ($inner) => $inner
                    ->whereNull('last_login_at')
                    ->orWhere('last_login_at', '<', now()->subDays($days))),
            )
            ->when(
                filter_var($filters['never_logged_in'] ?? false, FILTER_VALIDATE_BOOLEAN),
                fn ($query) => $query->whereNull('last_login_at'),
            )
            ->orderByRaw('last_login_at ASC NULLS FIRST')
            ->get();

        return response()->json(['users' => AdminUserResource::collection($users)->resolve()]);
    }

    /**
     * Role and professional details.
     *
     * `credential_sighted_on` is settable here and nowhere else: it means an
     * administrator has seen the certificate, which is a different claim from
     * the number the person typed in at sign-up.
     */
    public function update(Request $request, User $user): JsonResponse
    {
        $data = $request->validate([
            'role' => ['sometimes', Rule::in(Role::all())],
            'specialty' => ['nullable', 'string', 'max:80'],
            'grade' => ['nullable', 'string', 'max:60'],
            'department' => ['nullable', 'string', 'max:80'],
            'mdc_number' => ['nullable', 'string', 'max:40'],
            'mdc_expires_on' => ['nullable', 'date'],
            'credential_sighted_on' => ['nullable', 'date', 'before_or_equal:today'],
        ]);

        $actor = $request->user();

        if (isset($data['role']) && $data['role'] !== $user->role) {
            // Criterion 7: nobody promotes themselves. An admin who needs a
            // different role asks another admin, which leaves two people in
            // the audit trail instead of one.
            if ($user->is($actor)) {
                throw ValidationException::withMessages([
                    'role' => 'You cannot change your own role. Ask another administrator.',
                ]);
            }

            if ($this->wouldRemoveTheLastAdmin($user, $data['role'])) {
                throw ValidationException::withMessages([
                    'role' => 'This is the only active administrator. Appoint another before changing this role.',
                ]);
            }

            AuditEntry::record($request, 'user.role_changed', 'user', $user->getKey(), [
                'before' => ['role' => $user->role],
                'after' => ['role' => $data['role']],
            ]);
        }

        if (! empty($data['credential_sighted_on'])) {
            $user->credential_sighted_by = $actor->getKey();
        }

        $user->forceFill($data)->save();

        return response()->json(AdminUserResource::make($user->fresh('invitedBy'))->resolve());
    }

    public function deactivate(Request $request, User $user): JsonResponse
    {
        $data = $request->validate([
            'status' => ['required', Rule::in([AccountStatus::SUSPENDED, AccountStatus::DEPARTED])],
            // Long enough to be a sentence: this reason is read months later in
            // an access review, by someone who was not in the room.
            'reason' => ['required', 'string', 'min:10', 'max:255'],
        ]);

        try {
            $this->deactivate->__invoke($user, $data['status'], $data['reason'], $request->user(), $request->ip());
        } catch (LastAdminException $exception) {
            throw ValidationException::withMessages(['status' => $exception->getMessage()]);
        }

        return response()->json(AdminUserResource::make($user->fresh('invitedBy'))->resolve());
    }

    public function reactivate(Request $request, User $user): JsonResponse
    {
        $data = $request->validate([
            'reason' => ['required', 'string', 'min:10', 'max:255'],
        ]);

        if ($user->isActive()) {
            throw ValidationException::withMessages(['reason' => 'That account is already active.']);
        }

        $this->reactivate->__invoke($user, $data['reason'], $request->user(), $request->ip());

        return response()->json(AdminUserResource::make($user->fresh('invitedBy'))->resolve());
    }

    private function wouldRemoveTheLastAdmin(User $user, string $newRole): bool
    {
        if ($user->role !== Role::ADMIN || $newRole === Role::ADMIN || ! $user->isActive()) {
            return false;
        }

        return User::where('role', Role::ADMIN)
            ->where('status', AccountStatus::ACTIVE)
            ->whereKeyNot($user->getKey())
            ->doesntExist();
    }
}
