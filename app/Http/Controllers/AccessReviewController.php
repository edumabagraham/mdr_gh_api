<?php

namespace App\Http\Controllers;

use App\Access\AccountStatus;
use App\Http\Resources\AdminUserResource;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The screen that actually catches people who left.
 *
 * Nobody emails IT when a registrar rotates out, so an account list sorted by
 * staleness is the only control that finds them. Never-logged-in first: an
 * account created and never used is the most suspicious thing on the list.
 */
class AccessReviewController extends Controller
{
    private const STALE_DAYS = 90;

    /** A certificate expiring inside this window needs chasing now. */
    private const CREDENTIAL_WARNING_DAYS = 60;

    public function __invoke(Request $request): JsonResponse
    {
        $users = User::where('status', AccountStatus::ACTIVE)
            ->orderByRaw('last_login_at ASC NULLS FIRST')
            ->get();

        $staleThreshold = now()->subDays(self::STALE_DAYS);

        return response()->json([
            'generated_at' => now()->toIso8601String(),
            'stale_after_days' => self::STALE_DAYS,
            'never_logged_in' => $users->whereNull('last_login_at')->count(),
            'stale_over_90_days' => $users
                ->filter(fn (User $user) => $user->last_login_at && $user->last_login_at->lt($staleThreshold))
                ->count(),
            'credentials_expiring' => $users
                ->filter(fn (User $user) => $this->credentialNeedsAttention($user))
                ->count(),
            'users' => $users->map(function (User $user): array {
                return AdminUserResource::make($user)->resolve() + [
                    'credential_needs_attention' => $this->credentialNeedsAttention($user),
                ];
            })->values()->all(),
        ]);
    }

    /** Expired, or close enough to expiry that it should be chased. */
    private function credentialNeedsAttention(User $user): bool
    {
        return $user->mdc_expires_on !== null
            && $user->mdc_expires_on->lte(now()->addDays(self::CREDENTIAL_WARNING_DAYS));
    }
}
