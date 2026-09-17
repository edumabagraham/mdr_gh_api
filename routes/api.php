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
use App\Http\Controllers\EmailVerificationController;
use App\Http\Controllers\PasswordResetController;
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

    // Routes that should be closed to unverified users belong in a
    // ->middleware('verified') group, which answers them with a 409.
});
