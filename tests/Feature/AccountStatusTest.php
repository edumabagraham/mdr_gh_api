<?php

use App\Access\AccountStatus;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->withHeader('Origin', 'http://localhost:3000');
});

it('stops a deactivated account on its next request', function (string $status) {
    // Criterion 5's second half: ending the session closes the door, this
    // checks the door on every request afterwards.
    $user = User::factory()->deactivated($status)->create();

    $this->actingAs($user)
        ->getJson('/api/me')
        ->assertForbidden()
        ->assertJsonPath('code', 'account_inactive')
        ->assertJsonPath('status', $status);
})->with([AccountStatus::SUSPENDED, AccountStatus::DEPARTED]);

it('strips every permission from an account that is not active', function () {
    $suspended = User::factory()->deactivated(AccountStatus::SUSPENDED)->create();

    expect($suspended->permissions())->toBe([])
        ->and($suspended->isActive())->toBeFalse();
});

it('lets an active account through', function () {
    $this->actingAs(User::factory()->create())->getJson('/api/me')->assertOk();
});

it('applies the active check to every authenticated route', function () {
    // The common failure is not broken middleware but a route added six months
    // from now that forgets it. Iterating the route table catches that.
    $missing = collect(Route::getRoutes()->getRoutes())
        ->filter(fn ($route) => in_array('auth:sanctum', $route->gatherMiddleware(), true))
        ->reject(fn ($route) => in_array('active', $route->gatherMiddleware(), true))
        ->map(fn ($route) => $route->methods()[0].' '.$route->uri())
        ->values()
        ->all();

    expect($missing)->toBe([]);
});
