<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Symfony\Component\HttpFoundation\Response;

/**
 * Records that an account is still in use.
 *
 * Throttled hard on purpose: a clinic tablet polling the dashboard would
 * otherwise turn every request into a write to the users table, and knowing
 * someone was active within the last quarter hour is as precise as an access
 * review ever needs.
 */
class TouchLastActive
{
    public const INTERVAL_MINUTES = 15;

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user) {
            $key = "last-active:{$user->getKey()}";

            // add() only succeeds when the key is absent, so exactly one
            // request per window does the write.
            if (Cache::add($key, true, now()->addMinutes(self::INTERVAL_MINUTES))) {
                $user->forceFill(['last_active_at' => now()])->saveQuietly();
            }
        }

        return $next($request);
    }
}
