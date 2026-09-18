<?php

namespace Database\Factories;

use App\Models\Patient;
use App\Models\PatientIdentifier;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<PatientIdentifier>
 */
class PatientIdentifierFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'patient_id' => Patient::factory(),
            'system' => 'folder',
            'value' => (string) fake()->unique()->numberBetween(100000, 999999),
            'is_primary' => true,
        ];
    }
}
