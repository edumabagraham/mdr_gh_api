<?php

namespace Database\Factories;

use App\Models\Patient;
use App\Registry\RegistryNumber;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Patient>
 */
class PatientFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            // Factories do not draw from registry_no_seq: the tests that care
            // about allocation exercise the registrar directly, and spending
            // sequence values from every factory call would make them unreadable.
            'registry_no' => RegistryNumber::format(fake()->unique()->numberBetween(1, 999999)),
            'family_name' => fake()->lastName(),
            'given_name' => fake()->firstName(),
            'other_names' => null,
            'date_of_birth' => fake()->dateTimeBetween('-90 years', '-20 years')->format('Y-m-d'),
            'dob_estimated' => false,
            'sex' => fake()->randomElement(['male', 'female']),
            'phone_primary' => null,
            'status' => 'active',
            'enrolled_at' => now(),
        ];
    }

    public function named(string $familyName, string $givenName): static
    {
        return $this->state(fn () => ['family_name' => $familyName, 'given_name' => $givenName]);
    }

    /** A record that was merged away and must still resolve to its survivor. */
    public function mergedInto(Patient $survivor): static
    {
        return $this->state(fn () => [
            'status' => 'merged',
            'merged_into_id' => $survivor->id,
            'merged_at' => now(),
        ]);
    }
}
