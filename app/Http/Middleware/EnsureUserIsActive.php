<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Stops a deactivated account mid-session.
 *
 * The application authenticates with cookie sessions, so without this a
 * clinician who was deactivated this morning keeps working until their cookie
 * expires. Ending the session at deactivation closes the door; this checks the
 * door on every request afterwards.
 *
 * The response carries a code rather than only a message so the client can say
 * "your account has been deactivated" instead of a generic failure.
 */
class EnsureUserIsActive
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user && ! $user->isActive()) {
            return response()->json([
                'message' => 'This account is no longer active. Contact the registry administrator.',
                'code' => 'account_inactive',
                'status' => $user->status,
            ], 403);
        }

        return $next($request);
    }
}
