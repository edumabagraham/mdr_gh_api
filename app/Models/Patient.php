<?php

namespace App\Models;

use App\Registry\NameNormaliser;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([
    'registry_no', 'family_name', 'given_name', 'other_names', 'date_of_birth', 'dob_estimated',
    'sex', 'phone_primary', 'phone_alt', 'contact_name', 'contact_relationship',
    'residence_region', 'residence_district', 'residence_type', 'status', 'date_last_seen',
    'enrolled_at', 'enrolled_by', 'client_ref', 'duplicate_check', 'folder_absent_reason',
])]
class Patient extends Model
{
    use HasFactory;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'date_of_birth' => 'date',
            'date_last_seen' => 'date',
            'dob_estimated' => 'boolean',
            'enrolled_at' => 'datetime',
            'merged_at' => 'datetime',
            'duplicate_check' => 'array',
        ];
    }

    /**
     * name_normalised is never set by a caller: it is derived from the three
     * name columns on every write, so it cannot drift out of step with them.
     */
    protected static function booted(): void
    {
        static::saving(function (Patient $patient): void {
            $patient->name_normalised = NameNormaliser::normalise(
                $patient->family_name,
                $patient->given_name,
                $patient->other_names,
            );
        });
    }

    public function identifiers(): HasMany
    {
        return $this->hasMany(PatientIdentifier::class);
    }

    public function visits(): HasMany
    {
        return $this->hasMany(Visit::class);
    }

    public function mergedInto(): BelongsTo
    {
        return $this->belongsTo(Patient::class, 'merged_into_id');
    }

    public function enrolledBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'enrolled_by');
    }

    /** Display name, in the order a clinician says it. */
    public function displayName(): string
    {
        return trim("{$this->given_name} {$this->family_name}");
    }

    /**
     * Age in whole years, or null when the date of birth is unknown. Estimated
     * dates still produce an age — that is the point of recording them.
     */
    public function age(): ?int
    {
        return $this->date_of_birth?->age;
    }
}
