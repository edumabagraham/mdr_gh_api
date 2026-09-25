<?php

declare(strict_types=1);

namespace App\Registry;

use App\Models\Patient;
use App\Models\PatientPanel;
use Illuminate\Support\Facades\DB;

/**
 * Opens data-collection panels from clinical features.
 *
 * Run after anything that could change the answer: a diagnosis set, an
 * assessment completed, a fall recorded, a medication changed.
 *
 * Two properties matter more than the rules themselves:
 *
 * - **Idempotent.** Running it twice opens nothing twice. It is called from
 *   several places and will be called from more.
 * - **Tolerant of unknowns.** A rule whose input nobody has recorded yet
 *   simply does not fire. The dopaminergic panel's inputs arrive with the
 *   medication slice; until then it stays dormant with no special-casing here.
 *
 * Panels are never closed automatically — closure is a clinical statement with
 * a reason. A panel that silently disappears is missing data.
 */
final class PanelEvaluator
{
    /**
     * @param  array<string, mixed>|null  $facts  overrides, for callers that already hold them
     * @return list<string> panels opened by this run
     */
    public function evaluate(Patient $patient, ?array $facts = null): array
    {
        $facts ??= PatientFacts::for($patient);

        $alreadyOpen = $patient->panels()->open()->pluck('panel')->all();
        $opened = [];

        foreach (config('modules.panels', []) as $panel => $definition) {
            if (in_array($panel, $alreadyOpen, true)) {
                continue;
            }

            foreach ($definition['opens_when'] ?? [] as $rule => $expected) {
                $evidence = $this->fires($rule, $expected, $facts);

                if ($evidence === null) {
                    continue;
                }

                DB::transaction(function () use ($patient, $panel, $rule, $evidence): void {
                    PatientPanel::create([
                        'patient_id' => $patient->getKey(),
                        'panel' => $panel,
                        'trigger' => $rule,
                        'trigger_context' => $evidence,
                        'opened_at' => now(),
                    ]);
                });

                $opened[] = $panel;

                // One trigger is enough to open a panel; recording which one
                // fired first is what the clinician needs to see.
                break;
            }
        }

        return $opened;
    }

    /**
     * Every rule name this evaluator understands. A rule in config with no
     * implementation here is a typo that would silently never fire, so the
     * test suite compares the two lists.
     *
     * @return list<string>
     */
    public static function supportedRules(): array
    {
        return [
            'diagnosis_in',
            'moca_below',
            'cognitive_complaint_recorded',
            'rbd_screen_positive',
            'on_levodopa',
            'on_dopamine_agonist',
            'fall_in_interval',
            'freezing_reported',
            'gait_item_above',
            'dysphagia_screen_positive',
        ];
    }

    /**
     * The evidence that fired the rule, or null when it did not — including
     * when the fact it needs has never been recorded.
     *
     * @param  array<string, mixed>  $facts
     * @return array<string, mixed>|null
     */
    private function fires(string $rule, mixed $expected, array $facts): ?array
    {
        return match ($rule) {
            'diagnosis_in' => isset($facts['diagnosis_code'])
                && in_array($facts['diagnosis_code'], (array) $expected, true)
                    ? ['diagnosis_code' => $facts['diagnosis_code']]
                    : null,

            'moca_below' => isset($facts['moca']) && $facts['moca'] < $expected
                ? ['moca' => $facts['moca'], 'threshold' => $expected]
                : null,

            'gait_item_above' => isset($facts['gait_item']) && $facts['gait_item'] > $expected
                ? ['gait_item' => $facts['gait_item'], 'threshold' => $expected]
                : null,

            'cognitive_complaint_recorded',
            'rbd_screen_positive',
            'on_levodopa',
            'on_dopamine_agonist',
            'fall_in_interval',
            'freezing_reported',
            'dysphagia_screen_positive' => ($facts[$rule] ?? null) === true && $expected === true
                ? [$rule => true]
                : null,

            default => null,
        };
    }
}
