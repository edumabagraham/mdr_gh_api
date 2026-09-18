<?php

namespace App\Http\Controllers;

use App\Http\Requests\DuplicateCheckRequest;
use App\Http\Requests\StorePatientRequest;
use App\Http\Resources\PatientSummaryResource;
use App\Models\AuditEntry;
use App\Models\Patient;
use App\Registry\DuplicateChecker;
use App\Registry\DuplicateCheckToken;
use App\Registry\NameNormaliser;
use App\Registry\PatientRegistrar;
use App\Registry\RegistryNumber;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
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
        $patient->load(['identifiers', 'mergedInto']);

        AuditEntry::record($request, 'patient.viewed', 'patient', $patient->id);

        return response()->json(PatientSummaryResource::make($patient)->resolve());
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
