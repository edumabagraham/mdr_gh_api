<?php

namespace Database\Factories;

use App\Models\Patient;
use App\Models\Visit;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Visit>
 */
class VisitFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'patient_id' => Patient::factory(),
            'type' => 'routine',
            'scheduled_for' => today(),
            'status' => 'scheduled',
            'contact_attempts' => 0,
        ];
    }

    public function arrived(): static
    {
        return $this->state(fn () => ['status' => 'arrived', 'arrived_at' => now()->subMinutes(23)]);
    }

    public function completedBy(int $clinicianId): static
    {
        return $this->state(fn () => [
            'status' => 'complete',
            'clinician_id' => $clinicianId,
            'arrived_at' => now()->subHours(2),
            'started_at' => now()->subHour(),
            'completed_at' => now()->subMinutes(30),
        ]);
    }

    public function overdueBy(int $days): static
    {
        return $this->state(fn () => [
            'status' => 'scheduled',
            'scheduled_for' => today()->subDays($days),
        ]);
    }
}
