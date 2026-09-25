<?php

declare(strict_types=1);

namespace App\Registry;

use App\Models\Diagnosis;
use App\Models\Patient;
use App\Models\PatientModule;
use Illuminate\Support\Facades\DB;

/**
 * Records a diagnosis, and everything that follows from it.
 *
 * One transaction: write the new row, supersede the previous one, open the
 * module it belongs to, and re-evaluate the panels. Half of this happening is
 * worse than none of it — a diagnosis with no module leaves a patient in the
 * registry that no assessment schedule applies to.
 */
final class DiagnosisRecorder
{
    public function __construct(private readonly PanelEvaluator $panels) {}

    /**
     * @param  array<string, mixed>  $data
     * @return array{diagnosis: Diagnosis, superseded: ?Diagnosis, module_opened: ?string, panels_opened: list<string>}
     */
    public function record(Patient $patient, array $data, ?int $recordedBy): array
    {
        $result = DB::transaction(function () use ($patient, $data, $recordedBy): array {
            $previous = $patient->currentDiagnosis()->lockForUpdate()->first();

            // Stand the old row down before the new one goes in. The partial
            // unique index allows exactly one diagnosis per patient with a null
            // superseded_at, and it is checked per statement, not at commit —
            // so inserting first means two current rows for an instant, and a
            // unique violation.
            $previous?->forceFill(['superseded_at' => now()])->save();

            $diagnosis = Diagnosis::create([
                'patient_id' => $patient->getKey(),
                'code' => $data['code'],
                'certainty' => $data['certainty'],
                'diagnosed_on' => $data['diagnosed_on'],
                'diagnosed_by' => $recordedBy,
                'criteria_set' => $data['criteria_set'] ?? config("modules.diagnoses.{$data['code']}.criteria"),
                'criteria' => $data['criteria'] ?? null,
                'rationale' => $data['rationale'] ?? null,
            ]);

            // Point the superseded row at what replaced it. The row is never
            // otherwise edited: both labels, and the reason for the change,
            // stay queryable.
            $previous?->forceFill(['superseded_by_id' => $diagnosis->getKey()])->save();

            $moduleOpened = $this->openModule($patient, $diagnosis);

            return [
                'diagnosis' => $diagnosis,
                'superseded' => $previous,
                'module_opened' => $moduleOpened,
            ];
        });

        // Outside the transaction: panel evaluation reads what was just
        // committed, and an idempotent evaluator can safely be re-run if it
        // fails here.
        $result['panels_opened'] = $this->panels->evaluate($patient->fresh());

        return $result;
    }

    /**
     * Opens the module this diagnosis belongs to, if it is not open already,
     * and makes it the one the banner stages from.
     *
     * A module already open is left exactly as it is: revising a diagnosis
     * never closes the module the old one opened, because the data collected
     * under it remains meaningful.
     */
    private function openModule(Patient $patient, Diagnosis $diagnosis): ?string
    {
        $module = $diagnosis->module();

        if ($module === null) {
            return null;
        }

        $existing = $patient->modules()->open()->where('module', $module)->first();

        if ($existing) {
            return null;
        }

        // Only one module supplies the banner stage, so the clinician is never
        // shown two competing stages. The newest wins until overridden.
        $patient->modules()->open()->where('is_primary', true)->update(['is_primary' => false]);

        PatientModule::create([
            'patient_id' => $patient->getKey(),
            'module' => $module,
            'opened_by_diagnosis_id' => $diagnosis->getKey(),
            'opened_at' => now(),
            'is_primary' => true,
        ]);

        return $module;
    }
}
