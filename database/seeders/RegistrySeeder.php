<?php

namespace Database\Seeders;

use App\Models\User;
use App\Registry\NameNormaliser;
use App\Registry\RegistryNumber;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * The volume acceptance criterion 11 asks for: 5,000 patients and 20,000
 * visits, so the dashboard is measured against a realistic registry rather
 * than a handful of rows.
 *
 * Rows are inserted in bulk, so name_normalised is computed here — the model's
 * saving hook never runs for a bulk insert.
 */
class RegistrySeeder extends Seeder
{
    private const PATIENTS = 5000;

    private const VISITS = 20000;

    private const CHUNK = 500;

    public function run(): void
    {
        $clinicians = User::query()->limit(5)->pluck('id');

        if ($clinicians->isEmpty()) {
            $clinicians = collect([User::factory()->create()->id]);
        }

        $families = ['Mensah', 'Boateng', 'Asante', 'Owusu', 'Appiah', 'Osei', 'Agyeman', 'Nkrumah', 'Darko', 'Amoah'];
        $givens = ['Kwame', 'Akosua', 'Yaw', 'Ama', 'Kofi', 'Abena', 'Kojo', 'Adwoa', 'Kwabena', 'Afua'];

        $this->command?->info('Seeding '.self::PATIENTS.' patients…');

        foreach (array_chunk(range(1, self::PATIENTS), self::CHUNK) as $chunk) {
            $rows = [];

            foreach ($chunk as $index) {
                $family = $families[$index % count($families)];
                $given = $givens[($index * 7) % count($givens)];

                $rows[] = [
                    'registry_no' => RegistryNumber::format($index),
                    'family_name' => $family,
                    'given_name' => $given,
                    'other_names' => null,
                    'name_normalised' => NameNormaliser::normalise($family, $given),
                    'date_of_birth' => now()->subYears(30 + ($index % 50))->subDays($index % 365)->toDateString(),
                    'dob_estimated' => false,
                    'sex' => $index % 2 === 0 ? 'male' : 'female',
                    'status' => 'active',
                    'date_last_seen' => now()->subDays($index % 400)->toDateString(),
                    'enrolled_at' => now()->subDays($index % 400),
                    'enrolled_by' => $clinicians->random(),
                    'client_ref' => (string) Str::uuid(),
                    'created_at' => now(),
                    'updated_at' => now(),
                ];
            }

            DB::table('patients')->insert($rows);
        }

        // Keep the sequence ahead of the numbers just seeded, or the next real
        // registration collides with a seeded registry_no.
        DB::statement('SELECT setval(?, ?)', ['registry_no_seq', self::PATIENTS]);

        $patientIds = DB::table('patients')->pluck('id');

        $this->command?->info('Seeding '.self::VISITS.' visits…');

        foreach (array_chunk(range(1, self::VISITS), self::CHUNK) as $chunk) {
            $rows = [];

            foreach ($chunk as $index) {
                $patientId = $patientIds[$index % $patientIds->count()];

                // A realistic spread. A clinic session is tens of patients, not
                // thousands: seeding 15% of all visits into today would measure
                // a day that never happens.
                [$status, $scheduledFor, $completedAt] = match (true) {
                    $index <= 24 => ['scheduled', now()->toDateString(), null],
                    $index <= 32 => ['arrived', now()->toDateString(), null],
                    $index % 60 === 0 => ['scheduled', now()->subDays(30 + ($index % 200))->toDateString(), null],
                    default => ['complete', now()->subDays($index % 500)->toDateString(), now()->subDays($index % 500)],
                };

                $rows[] = [
                    'patient_id' => $patientId,
                    'type' => 'routine',
                    'scheduled_for' => $scheduledFor,
                    'arrived_at' => $status === 'arrived' ? now()->subMinutes($index % 90) : $completedAt,
                    'started_at' => $completedAt,
                    'completed_at' => $completedAt,
                    'clinician_id' => $clinicians->random(),
                    'status' => $status,
                    'contact_attempts' => $status === 'scheduled' ? $index % 4 : 0,
                    'created_at' => now(),
                    'updated_at' => now(),
                ];
            }

            DB::table('visits')->insert($rows);
        }
    }
}
