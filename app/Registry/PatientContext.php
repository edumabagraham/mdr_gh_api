<?php

declare(strict_types=1);

namespace App\Registry;

use App\Models\Patient;

/**
 * The patient facts exposed to instrument branching rules as `patient.*`.
 *
 * This is a contract, not a convenience. Every key here can appear inside a
 * stored instrument schema, so adding one is cheap and removing one means
 * versioning every instrument that references it. The patient model is never
 * exposed directly for exactly that reason.
 *
 * The key set is fixed by config('modules.expression_context'); a value that is
 * not yet knowable is null or false rather than missing, so a rule referencing
 * it evaluates rather than explodes.
 */
final class PatientContext
{
    /**
     * @return array<string, mixed>
     */
    public static function for(Patient $patient): array
    {
        $diagnosis = $patient->currentDiagnosis()->first();
        $openModules = $patient->modules()->open()->pluck('module')->all();
        $openPanels = $patient->panels()->open()->pluck('panel')->all();

        $values = [
            'age' => $patient->age(),
            'sex' => $patient->sex,
            'diagnosis_code' => $diagnosis?->code,
            'diagnosis_certainty' => $diagnosis?->certainty,
            'disease_duration_years' => $patient->diseaseDurationYears(),
            'symptom_onset_on' => $patient->symptom_onset_on?->toDateString(),

            // Arrive with the medication slice. Present and empty rather than
            // absent: a schema referencing patient.ledd must evaluate today.
            'on_levodopa' => false,
            'on_dopamine_agonist' => false,
            'ledd' => null,
            'has_device_therapy' => false,
        ];

        foreach (array_keys(config('modules.modules', [])) as $module) {
            $values["module_{$module}"] = in_array($module, $openModules, true);
        }

        foreach (array_keys(config('modules.panels', [])) as $panel) {
            $values["panel_{$panel}"] = in_array($panel, $openPanels, true);
        }

        // The allow-list is authoritative in both directions: nothing extra
        // escapes, and every promised key is present.
        $context = [];

        foreach (config('modules.expression_context', []) as $key) {
            $context[$key] = $values[$key] ?? null;
        }

        return $context;
    }
}
