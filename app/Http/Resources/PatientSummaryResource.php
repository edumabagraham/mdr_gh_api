<?php

namespace App\Http\Resources;

use App\Models\Patient;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Patient
 */
class PatientSummaryResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return array_filter([
            'id' => $this->id,
            'registry_no' => $this->registry_no,
            'name' => $this->displayName(),
            'date_of_birth' => $this->date_of_birth?->toDateString(),
            'dob_estimated' => $this->dob_estimated,

            // The number a clerk actually gave, kept alongside the derived
            // age so a reader can tell an estimate from a calculation.
            'estimated_age' => $this->estimated_age,
            'age' => $this->age(),
            'age_is_estimated' => $this->ageIsEstimated(),
            'sex' => $this->sex,
            'status' => $this->status,
            'date_last_seen' => $this->date_last_seen?->toDateString(),
            'identifiers' => $this->whenLoaded('identifiers', fn () => $this->identifiers
                ->map(fn ($identifier) => [
                    'system' => $identifier->system,
                    'value' => $identifier->value,
                    'is_primary' => $identifier->is_primary,
                ])->values()->all()),

            // Present only on a record that was merged away: a registry number
            // printed on a form years ago must still resolve to a live record.
            'merged_into' => $this->when($this->merged_into_id !== null, fn () => [
                'id' => $this->mergedInto?->id,
                'registry_no' => $this->mergedInto?->registry_no,
            ]),

            'similarity' => $this->when(isset($this->similarity), fn () => round((float) $this->similarity, 2)),
        ], fn ($value) => $value !== null);
    }
}
