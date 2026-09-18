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

use App\Http\Controllers\AuthController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\EmailVerificationController;
use App\Http\Controllers\MeController;
use App\Http\Controllers\PasswordResetController;
use App\Http\Controllers\PatientController;
use App\Http\Controllers\SyncController;
use App\Http\Controllers\VisitController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

Route::post('/register', [AuthController::class, 'register'])->middleware('throttle:auth');
Route::post('/login', [AuthController::class, 'login'])->middleware('throttle:auth');

Route::post('/forgot-password', [PasswordResetController::class, 'sendCode'])->middleware('throttle:codes');
Route::post('/reset-password', [PasswordResetController::class, 'reset'])->middleware('throttle:codes');

Route::middleware('auth:sanctum')->group(function () {
    Route::get('/user', fn (Request $request) => $request->user());
    Route::post('/logout', [AuthController::class, 'logout']);

    // Reachable while unverified — this is how a user becomes verified.
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
        Route::get('/me', [MeController::class, 'show']);
        Route::get('/dashboard', [DashboardController::class, 'index']);

        Route::get('/patients/search', [PatientController::class, 'search']);
        Route::post('/patients/duplicate-check', [PatientController::class, 'duplicateCheck']);
        Route::post('/patients', [PatientController::class, 'store']);

        // Bound by registry number: that is what appears in a URL, on a consent
        // form and in a referral letter. patients.id stays internal.
        Route::get('/patients/{patient:registry_no}', [PatientController::class, 'show']);

        Route::patch('/visits/{visit}', [VisitController::class, 'update']);

        Route::get('/sync/patients', [SyncController::class, 'patients']);
    });
});
