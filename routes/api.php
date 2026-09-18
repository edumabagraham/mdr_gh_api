<?php

/*
|--------------------------------------------------------------------------
| API routes
|--------------------------------------------------------------------------
|
| Every route here is automatically prefixed with /api, so the register
| endpoint below is reachable at http://localhost:8000/api/register.
|
| The /sanctum/csrf-cookie route is NOT defined here — Sanctum registers it
| itself, outside the /api prefix. That is why lib/auth.ts calls it without
| the prefix.
|
| The named throttles come from AppServiceProvider. `codes` is the tighter of
| the two and covers every endpoint that mails a code or checks one, since
| those are the ones worth brute forcing or abusing to mailbomb someone.
|
*/

/*
 * There is no public registration route. An account on a system holding patient
 * records is created by invitation only; AuthController::register survives as
 * the account-creation logic the invitation acceptance endpoint calls.
 */

use App\Access\Permission;
use App\Access\Role;
use App\Http\Controllers\AcceptInvitationController;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\ChangePasswordController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\EmailVerificationController;
use App\Http\Controllers\InvitationController;
use App\Http\Controllers\MeController;
use App\Http\Controllers\PasswordResetController;
use App\Http\Controllers\PatientController;
use App\Http\Controllers\SyncController;
use App\Http\Controllers\VisitController;
use App\Http\Resources\UserResource;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

Route::post('/login', [AuthController::class, 'login'])->middleware('throttle:auth');

// The one public way to gain an account, and only with a token that was
// emailed to the address an admin nominated.
Route::post('/invitations/accept', AcceptInvitationController::class)->middleware('throttle:codes');

Route::post('/forgot-password', [PasswordResetController::class, 'sendCode'])->middleware('throttle:codes');
Route::post('/reset-password', [PasswordResetController::class, 'reset'])->middleware('throttle:codes');

// `active` sits alongside auth on every authenticated route: a user
// deactivated five minutes ago must not be able to make a sixth request.
Route::middleware(['auth:sanctum', 'active'])->group(function () {
    Route::get('/user', fn (Request $request) => UserResource::make($request->user()));
    Route::post('/logout', [AuthController::class, 'logout']);

    // Reachable while unverified — this is how a user becomes verified.
    // Outside the verified group on purpose: someone who has not yet entered
    // their code may still need to change a password they mistyped at setup.
    Route::post('/password', ChangePasswordController::class)->middleware('throttle:codes');

    Route::post('/email/verify', [EmailVerificationController::class, 'verify'])->middleware('throttle:codes');
    Route::post('/email/resend', [EmailVerificationController::class, 'resend'])->middleware('throttle:codes');

    /*
     * Everything below touches patient data, so it is closed to accounts that
     * have not confirmed their email address. Laravel answers an unverified
     * user with a 403 on a JSON request.
     *
     * Access is deliberately not scoped by who enrolled a patient: people here
     * are seen by whoever is in clinic that day, and hiding records from
     * colleagues is what produces duplicate registrations. Every read is
     * written to the audit log instead.
     */
    Route::middleware('verified')->group(function () {
        // Available to every active, verified account whatever its role: this
        // is how the client learns which role it is dealing with.
        Route::get('/me', [MeController::class, 'show']);

        /*
         * Patient data. Each route carries the permission from the matrix in
         * App\Access\Permission rather than a role name, so a change of policy
         * is a change to that table and nothing else.
         *
         * An admin holds none of these: administering accounts carries no
         * clinical reason to read the registry.
         */
        Route::middleware('can:'.Permission::READ_PATIENTS)->group(function () {
            Route::get('/dashboard', [DashboardController::class, 'index']);
            Route::get('/patients/search', [PatientController::class, 'search']);
            Route::get('/patients/{patient:registry_no}', [PatientController::class, 'show']);
            Route::get('/sync/patients', [SyncController::class, 'patients']);

            // Clinic flow rather than clinical assessment: whoever is running
            // the waiting area marks arrivals.
            Route::patch('/visits/{visit}', [VisitController::class, 'update']);
        });

        /*
         * Account administration. Admins hold no clinical permissions, so this
         * is the whole of what the role can reach.
         */
        Route::middleware('role:'.Role::ADMIN)->prefix('admin')->group(function () {
            Route::get('/invitations', [InvitationController::class, 'index']);
            Route::post('/invitations', [InvitationController::class, 'store']);
            Route::post('/invitations/{invitation}/resend', [InvitationController::class, 'resend']);
            Route::delete('/invitations/{invitation}', [InvitationController::class, 'destroy']);
        });

        Route::middleware('can:'.Permission::REGISTER_PATIENTS)->group(function () {
            Route::post('/patients/duplicate-check', [PatientController::class, 'duplicateCheck']);
            Route::post('/patients', [PatientController::class, 'store']);
        });
    });
});
