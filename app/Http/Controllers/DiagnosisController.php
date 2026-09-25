<?php

namespace App\Http\Controllers;

use App\Models\AuditEntry;
use App\Models\Diagnosis;
use App\Models\Patient;
use App\Registry\DiagnosisRecorder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class DiagnosisController extends Controller
{
    public function __construct(private readonly DiagnosisRecorder $recorder) {}

    /**
     * The diagnosis dropdown, served from config.
     *
     * The client never hard-codes clinical vocabulary: changing what this
     * registry can record is a decision with a paper trail in config/modules.php,
     * not a string edited in two places that drift apart.
     */
    public function vocabulary(): JsonResponse
    {
        $modules = config('modules.modules', []);
        $grouped = [];

        foreach (config('modules.diagnoses', []) as $code => $definition) {
            $module = $definition['module'];

            $grouped[$module]['module'] = $module;
            $grouped[$module]['label'] = $modules[$module]['label'] ?? $module;
            $grouped[$module]['stage_instrument'] = $modules[$module]['stage_instrument'] ?? null;
            $grouped[$module]['diagnoses'][] = [
                'code' => $code,
                'label' => $definition['label'],
                'module' => $module,
                'criteria_set' => $definition['criteria'],
            ];
        }

        return response()->json([
            'certainties' => Diagnosis::CERTAINTIES,
            'groups' => array_values($grouped),
        ]);
    }

    /** Full history, newest first, with who recorded each and why. */
    public function index(Request $request, Patient $patient): JsonResponse
    {
        $diagnoses = $patient->diagnoses()
            ->with('diagnosedBy')
            ->orderByDesc('diagnosed_on')
            ->orderByDesc('id')
            ->get();

        return response()->json([
            'diagnoses' => $diagnoses->map(fn (Diagnosis $diagnosis) => $this->present($diagnosis))->all(),
            'revision_count' => max(0, $diagnoses->count() - 1),
        ]);
    }

    public function store(Request $request, Patient $patient): JsonResponse
    {
        $hasCurrent = $patient->currentDiagnosis()->exists();

        $data = $request->validate([
            'code' => ['required', Rule::in(array_keys(config('modules.diagnoses', [])))],
            'certainty' => ['required', Rule::in(Diagnosis::CERTAINTIES)],
            'diagnosed_on' => ['required', 'date', 'before_or_equal:today'],
            'criteria_set' => ['nullable', 'string', 'max:40'],
            'criteria' => ['nullable', 'array'],

            // Why the clinician changed their mind is worth as much as the new
            // label, so a revision cannot be recorded without it.
            'rationale' => [$hasCurrent ? 'required' : 'nullable', 'string', 'max:2000'],
        ], [
            'rationale.required' => 'Say why the diagnosis is being revised; this replaces an existing diagnosis.',
        ]);

        $result = $this->recorder->record($patient, $data, $request->user()?->getKey());

        AuditEntry::record($request, 'diagnosis.recorded', 'patient', $patient->getKey(), [
            'diagnosis_id' => $result['diagnosis']->id,
            'code' => $data['code'],
            'certainty' => $data['certainty'],
            'superseded_diagnosis_id' => $result['superseded']?->id,
            'module_opened' => $result['module_opened'],
            'panels_opened' => $result['panels_opened'],
        ]);

        return response()->json([
            'diagnosis' => $this->present($result['diagnosis']->load('diagnosedBy')),
            'superseded' => $result['superseded'] ? $this->present($result['superseded']) : null,
            'module_opened' => $result['module_opened'],
            'panels_opened' => $result['panels_opened'],
        ], 201);
    }

    /**
     * @return array<string, mixed>
     */
    private function present(Diagnosis $diagnosis): array
    {
        return [
            'id' => $diagnosis->id,
            'code' => $diagnosis->code,
            'label' => $diagnosis->label(),
            'certainty' => $diagnosis->certainty,
            'module' => $diagnosis->module(),
            'diagnosed_on' => $diagnosis->diagnosed_on->toDateString(),
            'diagnosed_by' => $diagnosis->diagnosedBy?->displayName(),
            'criteria_set' => $diagnosis->criteria_set,
            'criteria' => $diagnosis->criteria,
            'rationale' => $diagnosis->rationale,
            'is_current' => $diagnosis->isCurrent(),
            'superseded_at' => $diagnosis->superseded_at?->toIso8601String(),
            'recorded_at' => $diagnosis->created_at?->toIso8601String(),
        ];
    }
}
