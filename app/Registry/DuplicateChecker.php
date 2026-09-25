<?php

declare(strict_types=1);

namespace App\Registry;

use App\Models\Patient;
use Illuminate\Support\Carbon;

/**
 * Decides whether a proposed patient is already in the registry.
 *
 * Thresholds come from the spec: an exact hospital identifier is a hard block,
 * a close name with a nearby date of birth is a warning, and a very close name
 * is a warning on its own.
 *
 * Similarity is pg_trgm, not soundex or metaphone: those encode English
 * phonology and mangle Ghanaian names.
 */
final class DuplicateChecker
{
    /** A name this close is a warning regardless of date of birth. */
    public const NAME_ONLY_THRESHOLD = 0.80;

    /** A name this close is a warning when the dates of birth are also close. */
    public const NAME_WITH_DOB_THRESHOLD = 0.60;

    public const DOB_WINDOW_YEARS = 2;

    private const MAX_CANDIDATES = 10;

    /**
     * @param  array<string, mixed>  $payload
     * @return array{verdict: string, candidates: list<array<string, mixed>>}
     */
    public function check(array $payload): array
    {
        $candidates = [];

        foreach ($this->identifierMatches($payload) as $patient) {
            $candidates[$patient->id] = ['patient' => $patient, 'reasons' => ['identifier_match'], 'similarity' => null];
        }

        // An identifier this hospital issued is conclusive on its own; there is
        // no point ranking fuzzy name matches behind it.
        if ($candidates !== []) {
            return ['verdict' => 'block', 'candidates' => $this->present($candidates)];
        }

        foreach ($this->nameMatches($payload) as $match) {
            $reasons = [];

            if ($match->similarity >= self::NAME_ONLY_THRESHOLD) {
                $reasons[] = 'name_similarity';
            } elseif (($ageReason = $this->birthYearIsNear($payload, $match)) !== null) {
                $reasons[] = 'name_similarity';
                $reasons[] = $ageReason;
            }

            if ($reasons !== []) {
                $candidates[$match->id] = [
                    'patient' => $match,
                    'reasons' => $reasons,
                    'similarity' => round((float) $match->similarity, 2),
                ];
            }
        }

        foreach ($this->phoneMatches($payload) as $patient) {
            $existing = $candidates[$patient->id] ?? ['patient' => $patient, 'reasons' => [], 'similarity' => null];
            $existing['reasons'][] = 'phone_match';
            $candidates[$patient->id] = $existing;
        }

        return [
            'verdict' => $candidates === [] ? 'clear' : 'warn',
            'candidates' => $this->present($candidates),
        ];
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return list<Patient>
     */
    private function identifierMatches(array $payload): array
    {
        $blocking = collect($payload['identifiers'] ?? [])
            ->filter(fn (array $identifier) => in_array(
                $identifier['system'] ?? '',
                config('identifiers.blocking_systems', []),
                true,
            ))
            ->values();

        if ($blocking->isEmpty()) {
            return [];
        }

        return Patient::query()
            ->where('status', '!=', 'merged')
            ->whereHas('identifiers', function ($query) use ($blocking) {
                $query->where(function ($inner) use ($blocking) {
                    foreach ($blocking as $identifier) {
                        $inner->orWhere(fn ($clause) => $clause
                            ->where('system', $identifier['system'])
                            ->where('value', $identifier['value']));
                    }
                });
            })
            ->limit(self::MAX_CANDIDATES)
            ->get()
            ->all();
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return list<Patient>
     */
    private function nameMatches(array $payload): array
    {
        $normalised = NameNormaliser::normalise(
            $payload['family_name'] ?? null,
            $payload['given_name'] ?? null,
            $payload['other_names'] ?? null,
        );

        if ($normalised === '') {
            return [];
        }

        // The `%` operator is what the GIN index answers; similarity() then
        // applies the real threshold, which is higher than pg_trgm's default.
        return Patient::query()
            ->select('patients.*')
            ->selectRaw('similarity(name_normalised, ?) as similarity', [$normalised])
            ->whereRaw('name_normalised % ?', [$normalised])
            ->whereRaw('similarity(name_normalised, ?) >= ?', [$normalised, self::NAME_WITH_DOB_THRESHOLD])
            ->where('status', '!=', 'merged')
            ->orderByDesc('similarity')
            ->limit(self::MAX_CANDIDATES)
            ->get()
            ->all();
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return list<Patient>
     */
    private function phoneMatches(array $payload): array
    {
        $phone = $payload['phone_primary'] ?? null;

        if (! $phone) {
            return [];
        }

        return Patient::query()
            ->where('status', '!=', 'merged')
            ->where(fn ($query) => $query->where('phone_primary', $phone)->orWhere('phone_alt', $phone))
            ->limit(self::MAX_CANDIDATES)
            ->get()
            ->all();
    }

    /**
     * Whether the two were born within the window, from whichever fact each
     * side carries.
     *
     * Many patients here do not know their date of birth and give an age
     * instead. Comparing only real dates of birth would drop this signal for
     * exactly those records — and an approximate year from each side is still
     * worth comparing, provided the reason returned says which it was, so a
     * clerk reading the warning knows how much weight it carries.
     *
     * @param  array<string, mixed>  $payload
     * @return string|null the reason code, or null when they are not near
     */
    private function birthYearIsNear(array $payload, Patient $candidate): ?string
    {
        $proposedDate = $payload['date_of_birth'] ?? null;
        $proposedAge = $payload['estimated_age'] ?? null;

        $proposedYear = match (true) {
            ! empty($proposedDate) => (int) Carbon::parse($proposedDate)->year,
            $proposedAge !== null && $proposedAge !== '' => (int) now()->year - (int) $proposedAge,
            default => null,
        };

        $candidateYear = $candidate->approximateBirthYear();

        if ($proposedYear === null || $candidateYear === null) {
            return null;
        }

        if (abs($candidateYear - $proposedYear) > self::DOB_WINDOW_YEARS) {
            return null;
        }

        $bothExact = ! empty($proposedDate) && $candidate->date_of_birth !== null;

        return $bothExact ? 'dob_within_2_years' : 'age_within_2_years';
    }

    /**
     * @param  array<int, array<string, mixed>>  $candidates
     * @return list<array<string, mixed>>
     */
    private function present(array $candidates): array
    {
        return collect($candidates)
            ->map(fn (array $candidate): array => array_filter([
                'patient' => [
                    'id' => $candidate['patient']->id,
                    'registry_no' => $candidate['patient']->registry_no,
                    'name' => $candidate['patient']->displayName(),
                    'date_of_birth' => $candidate['patient']->date_of_birth?->toDateString(),
                    'age' => $candidate['patient']->age(),
                    'age_is_estimated' => $candidate['patient']->ageIsEstimated(),
                ],
                'reasons' => array_values(array_unique($candidate['reasons'])),
                'similarity' => $candidate['similarity'],
            ], fn ($value) => $value !== null))
            ->values()
            ->all();
    }
}
