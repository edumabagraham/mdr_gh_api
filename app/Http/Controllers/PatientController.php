<?php

namespace App\Http\Controllers;

use App\Http\Requests\DuplicateCheckRequest;
use App\Http\Requests\StorePatientRequest;
use App\Http\Resources\PatientSummaryResource;
use App\Models\AuditEntry;
use App\Models\Patient;
use App\Registry\AssessmentPlan;
use App\Registry\DuplicateChecker;
use App\Registry\DuplicateCheckToken;
use App\Registry\NameNormaliser;
use App\Registry\PatientAlerts;
use App\Registry\PatientRegistrar;
use App\Registry\RegistryNumber;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class PatientController extends Controller
{
    public function __construct(
        private readonly DuplicateChecker $duplicates,
        private readonly PatientRegistrar $registrar,
    ) {}

    /**
     * Four tiers, stopping at the first that returns anything.
     *
     * The order is by confidence: an identifier match is certain, a trigram
     * match is a guess. Running them all and merging would bury the certain
     * answer in a list of maybes.
     */
    public function search(Request $request): JsonResponse
    {
        $query = trim((string) $request->query('q', ''));
        $limit = min(max((int) $request->query('limit', 20), 1), 50);

        if ($query === '') {
            return response()->json(['query' => '', 'matched_by' => null, 'results' => []]);
        }

        [$matchedBy, $results] = $this->runSearchTiers($query, $limit);

        AuditEntry::record($request, 'patient.searched', 'patient', null, [
            'query' => $query,
            'matched_by' => $matchedBy,
            'results' => $results->count(),
        ]);

        return response()->json([
            'query' => $query,
            'matched_by' => $matchedBy,
            'results' => PatientSummaryResource::collection($results)->resolve(),
        ]);
    }

    /**
     * @return array{0: ?string, 1: Collection<int, Patient>}
     */
    private function runSearchTiers(string $query, int $limit): array
    {
        $canonical = RegistryNumber::canonicalise($query);

        if ($canonical !== null) {
            $results = Patient::with(['identifiers', 'mergedInto'])
                ->where('registry_no', $canonical)
                ->get();

            if ($results->isNotEmpty()) {
                return ['registry_no', $results];
            }
        }

        $byIdentifier = Patient::with(['identifiers', 'mergedInto'])
            ->whereHas('identifiers', fn ($inner) => $inner->where('value', $query))
            ->limit($limit)
            ->get();

        if ($byIdentifier->isNotEmpty()) {
            return ['identifier', $byIdentifier];
        }

        $normalised = NameNormaliser::normalise($query);

        if ($normalised !== '') {
            $byName = Patient::with(['identifiers', 'mergedInto'])
                ->select('patients.*')
                ->selectRaw('similarity(name_normalised, ?) as similarity', [$normalised])
                ->whereRaw('name_normalised % ?', [$normalised])
                ->orderByDesc('similarity')
                ->limit($limit)
                ->get();

            if ($byName->isNotEmpty()) {
                return ['name', $byName];
            }
        }

        $byPhone = Patient::with(['identifiers', 'mergedInto'])
            ->where(fn ($inner) => $inner->where('phone_primary', $query)->orWhere('phone_alt', $query))
            ->limit($limit)
            ->get();

        return [$byPhone->isNotEmpty() ? 'phone' : null, $byPhone];
    }

    /** Reading a record is an event worth keeping: access is open, so it is logged. */
    public function show(Request $request, Patient $patient): JsonResponse
    {
        $patient->load(['identifiers', 'mergedInto', 'currentDiagnosis.diagnosedBy']);

        AuditEntry::record($request, 'patient.viewed', 'patient', $patient->id);

        $diagnosis = $patient->currentDiagnosis;
        $modules = $patient->modules()->open()->orderByDesc('opened_at')->get();

        return response()->json(PatientSummaryResource::make($patient)->resolve() + [
            // How to reach this person, and who answers when they cannot be
            // reached. Tracing over years depends on these more than on
            // anything else collected at registration.
            'phone_primary' => $patient->phone_primary,
            'phone_alt' => $patient->phone_alt,
            'contact_name' => $patient->contact_name,
            'contact_relationship' => $patient->contact_relationship,
            'contact_phone' => $patient->contact_phone,
            'residence_district' => $patient->residence_district,

            'symptom_onset_on' => $patient->symptom_onset_on?->toDateString(),
            'symptom_onset_estimated' => $patient->symptom_onset_estimated,
            'first_symptom' => $patient->first_symptom,
            'side_of_onset' => $patient->side_of_onset,

            // Derived here rather than in the browser: staleness is measured
            // against the server's clock, and a banner that computes it during
            // render is reading a moving value.
            'months_since_seen' => $patient->date_last_seen
                ? (int) $patient->date_last_seen->diffInMonths(today())
                : null,

            'diagnosis' => $diagnosis === null ? null : [
                'id' => $diagnosis->id,
                'code' => $diagnosis->code,
                'label' => $diagnosis->label(),
                'certainty' => $diagnosis->certainty,
                'diagnosed_on' => $diagnosis->diagnosed_on->toDateString(),
                'diagnosed_by' => $diagnosis->diagnosedBy?->displayName(),
                'criteria_set' => $diagnosis->criteria_set,

                // Derived, never accepted from a client: it is a function of
                // the onset date and would drift the moment either changed.
                'disease_duration_years' => $patient->diseaseDurationYears(),

                // A patient revised twice is clinically different from one
                // never revised, so the count belongs on the banner.
                'revision_count' => max(0, $patient->diagnoses()->count() - 1),
            ],

            'modules' => $modules->map(fn ($module) => [
                'module' => $module->module,
                'label' => $module->label(),
                'stage_instrument' => $module->stageInstrument(),
                'is_primary' => $module->is_primary,
                'opened_at' => $module->opened_at->toIso8601String(),
            ])->values()->all(),

            'panels' => $patient->panels()->open()->orderBy('opened_at')->get()
                ->map(fn ($panel) => [
                    'panel' => $panel->panel,
                    'label' => $panel->label(),
                    'trigger' => $panel->trigger,
                    'trigger_context' => $panel->trigger_context,
                    'opened_at' => $panel->opened_at->toIso8601String(),
                ])->values()->all(),

            'alerts' => PatientAlerts::for($patient),

            // The core set is available from registration; only the module set
            // waits for a diagnosis. See App\Registry\AssessmentPlan.
            'assessments' => AssessmentPlan::for($patient),
        ]);
    }

    /**
     * Symptom onset, which lives on the patient rather than on a diagnosis:
     * onset is a fact about the illness and survives every diagnostic revision.
     *
     * Many patients date onset only to a year or a season, so a null date with
     * `symptom_onset_estimated` is a legitimate answer rather than missing data.
     */
    public function update(Request $request, Patient $patient): JsonResponse
    {
        $data = $request->validate([
            'symptom_onset_on' => ['nullable', 'date', 'before_or_equal:today'],
            'symptom_onset_estimated' => ['boolean'],
            'first_symptom' => ['nullable', 'string', 'max:40'],
            'side_of_onset' => ['nullable', Rule::in(['right', 'left', 'bilateral', 'axial'])],
            'nonmotor_onset_on' => ['nullable', 'date', 'before_or_equal:today'],
        ]);

        $patient->forceFill($data)->save();

        AuditEntry::record($request, 'patient.updated', 'patient', $patient->id, [
            'fields' => array_keys($data),
        ]);

        return response()->json(PatientSummaryResource::make($patient->fresh('identifiers'))->resolve() + [
            'symptom_onset_on' => $patient->symptom_onset_on?->toDateString(),
            'symptom_onset_estimated' => $patient->symptom_onset_estimated,
            'disease_duration_years' => $patient->diseaseDurationYears(),
        ]);
    }

    public function duplicateCheck(DuplicateCheckRequest $request): JsonResponse
    {
        $payload = $request->validated();
        $result = $this->duplicates->check($payload);

        return response()->json([
            'verdict' => $result['verdict'],
            'duplicate_check_token' => DuplicateCheckToken::issue($payload, $result['verdict']),
            'candidates' => $result['candidates'],
        ]);
    }

    public function store(StorePatientRequest $request): JsonResponse
    {
        $data = $request->validated();

        $verdict = DuplicateCheckToken::verdictFor($data['duplicate_check_token'], $data);

        // No valid token means no check was run for this patient — or one was
        // run for somebody else. Either way the workflow was not followed.
        if ($verdict === null) {
            throw ValidationException::withMessages([
                'duplicate_check_token' => 'Run the duplicate check for this patient first; the check has expired or does not match.',
            ]);
        }

        if ($verdict === 'block') {
            throw ValidationException::withMessages([
                'duplicate_check_token' => 'An existing record carries one of these hospital identifiers. Open that record instead.',
            ]);
        }

        if ($verdict === 'warn' && $data['duplicate_decision'] !== 'new_person_despite_match') {
            throw ValidationException::withMessages([
                'duplicate_decision' => 'Possible duplicates were found. Confirm this is a different person before registering.',
            ]);
        }

        $patient = $this->registrar->register($data, $request->user()?->getKey(), [
            'verdict' => $verdict,
            'decision' => $data['duplicate_decision'],
            'checked_at' => now()->toIso8601String(),
        ]);

        AuditEntry::record($request, 'patient.created', 'patient', $patient->id, [
            'verdict' => $verdict,
            'decision' => $data['duplicate_decision'],
        ]);

        return response()->json(PatientSummaryResource::make($patient->load('identifiers'))->resolve(), 201);
    }
}
