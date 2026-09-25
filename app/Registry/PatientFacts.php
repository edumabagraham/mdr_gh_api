<?php

declare(strict_types=1);

namespace App\Registry;

use App\Models\Patient;

/**
 * What is currently known about a patient, for rules to be evaluated against.
 *
 * A fact that is not known is **absent**, not false. "This patient is not on
 * levodopa" and "nobody has recorded what this patient takes" are different
 * clinical statements, and a rule that cannot tell them apart will open panels
 * on the strength of missing data.
 *
 * Medication and assessment facts arrive with later slices. Adding them here is
 * the only change the evaluator needs when they do.
 *
 * @see PanelEvaluator
 */
final class PatientFacts
{
    /**
     * @return array<string, mixed>
     */
    public static function for(Patient $patient): array
    {
        $facts = [];

        $diagnosis = $patient->relationLoaded('currentDiagnosis')
            ? $patient->currentDiagnosis
            : $patient->currentDiagnosis()->first();

        if ($diagnosis) {
            $facts['diagnosis_code'] = $diagnosis->code;
            $facts['diagnosis_certainty'] = $diagnosis->certainty;
        }

        // Deliberately nothing else yet. moca, cognitive_complaint_recorded,
        // rbd_screen_positive, on_levodopa, on_dopamine_agonist,
        // fall_in_interval, freezing_reported, gait_item and
        // dysphagia_screen_positive all arrive with the instrument, medication
        // and milestone slices.

        return $facts;
    }
}
