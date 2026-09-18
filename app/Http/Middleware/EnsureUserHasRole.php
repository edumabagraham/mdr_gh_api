<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureUserHasRole
{
    /**
     * Route guard for whole areas that belong to one role, such as /admin.
     * Anything finer-grained goes through a Gate and the `can:` middleware.
     */
    public function handle(Request $request, Closure $next, string ...$roles): Response
    {
        if (! $request->user() || ! in_array($request->user()->role, $roles, true)) {
            return response()->json([
                'message' => 'This area is not available to your role.',
                'code' => 'role_forbidden',
            ], 403);
        }

        return $next($request);
    }
}
