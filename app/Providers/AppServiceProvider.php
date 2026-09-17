<?php

namespace App\Providers;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Str;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->configureRateLimiting();
    }

    /**
     * Named rate limiters for the auth endpoints.
     *
     * Named limiters each get their own bucket. An unnamed `throttle:6,1`
     * would key on domain plus IP alone, so every unnamed throttle in the
     * application would share one counter and burn each other's budget.
     */
    private function configureRateLimiting(): void
    {
        RateLimiter::for('auth', fn (Request $request) => Limit::perMinute(10)->by($request->ip()));

        // Endpoints that mail a code or check one. Limited per IP, and again
        // per address so that one mailbox cannot be flooded from many IPs.
        RateLimiter::for('codes', fn (Request $request) => [
            Limit::perMinute(12)->by($request->ip()),
            Limit::perMinute(5)->by(Str::lower(
                (string) ($request->user()?->email ?? $request->input('email') ?? $request->ip())
            )),
        ]);
    }
}
