<?php

namespace Database\Factories;

use App\Access\AccountStatus;
use App\Access\Role;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * @extends Factory<User>
 */
class UserFactory extends Factory
{
    /**
     * The current password being used by the factory.
     */
    protected static ?string $password;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->name(),
            'email' => fake()->unique()->safeEmail(),
            'email_verified_at' => now(),
            'role' => Role::CLINICIAN,
            'status' => AccountStatus::ACTIVE,
            'password' => static::$password ??= Hash::make('password'),
            'remember_token' => Str::random(10),
        ];
    }

    public function admin(): static
    {
        return $this->state(fn () => ['role' => Role::ADMIN]);
    }

    public function researchAssistant(): static
    {
        return $this->state(fn () => ['role' => Role::RESEARCH_ASSISTANT]);
    }

    public function dataManager(): static
    {
        return $this->state(fn () => ['role' => Role::DATA_MANAGER]);
    }

    public function role(string $role): static
    {
        return $this->state(fn () => ['role' => $role]);
    }

    /**
     * A deactivated account. The database will not accept a non-active status
     * without a deactivation timestamp, so the state sets both.
     */
    public function deactivated(string $status = AccountStatus::DEPARTED): static
    {
        return $this->state(fn () => [
            'status' => $status,
            'deactivated_at' => now(),
            'deactivation_reason' => 'Rotated to another hospital',
        ]);
    }

    /**
     * Indicate that the model's email address should be unverified.
     */
    public function unverified(): static
    {
        return $this->state(fn (array $attributes) => [
            'email_verified_at' => null,
        ]);
    }
}
