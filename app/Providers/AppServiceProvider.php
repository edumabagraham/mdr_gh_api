<?php

namespace App\Providers;

use App\Access\Permission;
use App\Models\User;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\Gate;
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
        $this->configurePermissions();

        // Resources return the object itself rather than nesting it under a
        // "data" key. Every endpoint built so far answers unwrapped, and one
        // endpoint shaped differently from the rest is a bug waiting to be
        // written in the client.
        JsonResource::withoutWrapping();
    }

    /**
     * One gate per permission, all resolved from the matrix in App\Access\
     * Permission. Nothing else in the application decides what a role may do.
     */
    private function configurePermissions(): void
    {
        foreach (Permission::all() as $permission) {
            Gate::define(
                $permission,
                // A suspended or departed account keeps its role but loses
                // every permission that came with it.
                fn (User $user): bool => $user->isActive()
                    && in_array($permission, Permission::for($user->role), true),
            );
        }
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
