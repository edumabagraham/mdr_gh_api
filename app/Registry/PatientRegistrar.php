<?php

declare(strict_types=1);

namespace App\Registry;

use App\Models\Patient;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

/**
 * Creates patients and allocates their registry numbers.
 *
 * The number comes from registry_no_seq, never from patients.id. If the
 * surrounding transaction rolls back, the sequence value is consumed and the
 * number is skipped — which is intended. A gap in registry numbers is
 * harmless; a reused number attaches one patient's data to another.
 */
final class PatientRegistrar
{
    /**
     * @param  array<string, mixed>  $data
     * @param  array<string, mixed>  $duplicateAudit  the check and the decision, stored for audit
     */
    public function register(array $data, ?int $enrolledBy, array $duplicateAudit): Patient
    {
        // A retry after a dropped connection carries the same client_ref. It
        // must return the original patient, not make a second one.
        if (! empty($data['client_ref'])) {
            $existing = Patient::where('client_ref', $data['client_ref'])->first();

            if ($existing) {
                return $existing;
            }
        }

        try {
            return $this->create($data, $enrolledBy, $duplicateAudit);
        } catch (UniqueConstraintViolationException $exception) {
            // Two retries raced. Whichever lost re-reads the winner's row.
            $existing = Patient::where('client_ref', $data['client_ref'] ?? null)->first();

            if ($existing) {
                return $existing;
            }

            throw $exception;
        }
    }

    /**
     * @param  array<string, mixed>  $data
     * @param  array<string, mixed>  $duplicateAudit
     */
    private function create(array $data, ?int $enrolledBy, array $duplicateAudit): Patient
    {
        return DB::transaction(function () use ($data, $enrolledBy, $duplicateAudit): Patient {
            $sequence = (int) DB::selectOne("SELECT nextval('registry_no_seq') AS value")->value;

            $patient = Patient::create([
                'registry_no' => RegistryNumber::format($sequence),
                'family_name' => $data['family_name'],
                'given_name' => $data['given_name'],
                'other_names' => $data['other_names'] ?? null,
                'sex' => $data['sex'],
                'date_of_birth' => $data['date_of_birth'] ?? null,
                'dob_estimated' => $this->dateOfBirthIsEstimated($data),
                'estimated_age' => empty($data['date_of_birth'])
                    ? ($data['estimated_age'] ?? null)
                    : null,
                'phone_primary' => $data['phone_primary'] ?? null,
                'phone_alt' => $data['phone_alt'] ?? null,
                'contact_name' => $data['contact_name'] ?? null,
                'contact_relationship' => $data['contact_relationship'] ?? null,
                'contact_phone' => $data['contact_phone'] ?? null,
                'residence_district' => $data['residence_district'] ?? null,
                'status' => 'active',
                'enrolled_at' => now(),
                'enrolled_by' => $enrolledBy,
                'client_ref' => $data['client_ref'] ?? null,
                'folder_absent_reason' => $data['folder_absent_reason'] ?? null,
                'duplicate_check' => $duplicateAudit,
            ]);

            foreach ($data['identifiers'] ?? [] as $identifier) {
                $patient->identifiers()->create([
                    'system' => $identifier['system'],
                    'value' => $identifier['value'],
                    'assigner' => $identifier['assigner'] ?? null,
                    'is_primary' => (bool) ($identifier['is_primary'] ?? false),
                ]);
            }

            return $patient->load('identifiers');
        });
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function dateOfBirthIsEstimated(array $data): bool
    {
        if (empty($data['date_of_birth'])) {
            return true;
        }

        return (bool) ($data['dob_estimated'] ?? false);
    }
}
