<?php

declare(strict_types=1);

namespace App\Registry;

use App\Models\Patient;

/**
 * What this patient can be assessed with today.
 *
 * Two lists, and the split between them is the point. The core set is
 * cross-disease and available from registration onwards; the module set needs
 * a diagnosis because the instrument is chosen by the disease.
 *
 * A first visit that ends undiagnosed must still collect the core set. Gating
 * everything behind a diagnosis is how a registry ends up holding entries with
 * nothing in them — the patient came once, nothing could be recorded, and they
 * did not come back. `undetermined` is a diagnosis for exactly this case and
 * withholds nothing that does not genuinely depend on the label.
 *
 * Slice 3 replaces the placeholders with real instruments; the shape of the
 * answer, and which side of the line each instrument falls on, is decided here.
 */
final class AssessmentPlan
{
    /**
     * @return array{core: list<array<string, mixed>>, module: list<array<string, mixed>>}
     */
    public static function for(Patient $patient): array
    {
        $core = [];

        foreach (config('modules.core_assessments', []) as $code => $assessment) {
            $core[] = [
                'code' => $code,
                'label' => $assessment['label'],
                'domain' => $assessment['domain'],
                'available' => true,
            ];
        }

        $module = [];

        foreach ($patient->modules()->open()->orderByDesc('opened_at')->get() as $open) {
            $instrument = $open->stageInstrument();

            // A module with no staging instrument — dystonia, other — opens no
            // module-specific assessment. Listing it with a null instrument
            // would suggest something is pending that never arrives.
            if ($instrument === null) {
                continue;
            }

            $module[] = [
                'module' => $open->module,
                'label' => $open->label(),
                'instrument' => $instrument,
                'is_primary' => $open->is_primary,
                'available' => true,
            ];
        }

        return ['core' => $core, 'module' => $module];
    }
}
