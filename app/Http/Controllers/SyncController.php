<?php

namespace App\Http\Controllers;

use App\Models\Patient;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

class SyncController extends Controller
{
    private const PAGE_SIZE = 500;

    /**
     * Seeds and refreshes the client's offline cache.
     *
     * Compact on purpose: this list is what offline search runs against, and a
     * clinic tablet on a phone connection has to be able to pull all of it.
     */
    public function patients(Request $request): JsonResponse
    {
        $request->validate(['since' => ['nullable', 'date']]);

        $since = $request->query('since') ? Carbon::parse($request->query('since')) : null;

        $patients = Patient::with('identifiers')
            ->when($since, fn ($query) => $query->where('updated_at', '>', $since))
            ->orderBy('updated_at')
            ->orderBy('id')
            ->limit(self::PAGE_SIZE + 1)
            ->get();

        $hasMore = $patients->count() > self::PAGE_SIZE;
        $page = $patients->take(self::PAGE_SIZE);

        return response()->json([
            'generated_at' => now()->toIso8601String(),
            'has_more' => $hasMore,
            'next_since' => $page->last()?->updated_at?->toIso8601String(),
            'patients' => $page->map(fn (Patient $patient) => [
                'id' => $patient->id,
                'registry_no' => $patient->registry_no,
                'name' => $patient->displayName(),
                'name_normalised' => $patient->name_normalised,
                'date_of_birth' => $patient->date_of_birth?->toDateString(),
                'sex' => $patient->sex,
                'status' => $patient->status,
                'primary_identifier' => $patient->identifiers
                    ->firstWhere('is_primary', true)?->value
                    ?? $patient->identifiers->first()?->value,
            ])->values()->all(),
        ]);
    }
}
