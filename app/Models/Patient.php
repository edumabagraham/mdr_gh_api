<?php

namespace App\Models;

use App\Registry\NameNormaliser;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

#[Fillable([
    'registry_no', 'family_name', 'given_name', 'other_names', 'date_of_birth', 'dob_estimated',
    'estimated_age', 'sex', 'phone_primary', 'phone_alt',
    'contact_name', 'contact_relationship', 'contact_phone',
    'residence_region', 'residence_district', 'residence_type', 'status', 'date_last_seen',
    'enrolled_at', 'enrolled_by', 'client_ref', 'duplicate_check', 'folder_absent_reason',
    'symptom_onset_on', 'symptom_onset_estimated', 'first_symptom', 'side_of_onset', 'nonmotor_onset_on',
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
            'symptom_onset_on' => 'date',
            'nonmotor_onset_on' => 'date',
            'symptom_onset_estimated' => 'boolean',
            'date_last_seen' => 'date',
            'dob_estimated' => 'boolean',
            'estimated_age' => 'integer',
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

    public function diagnoses(): HasMany
    {
        return $this->hasMany(Diagnosis::class);
    }

    public function currentDiagnosis(): HasOne
    {
        return $this->hasOne(Diagnosis::class)->whereNull('superseded_at');
    }

    public function modules(): HasMany
    {
        return $this->hasMany(PatientModule::class);
    }

    public function panels(): HasMany
    {
        return $this->hasMany(PatientPanel::class);
    }

    /**
     * Years since the first symptom, to one decimal.
     *
     * Derived here and never accepted from a client: it is a function of the
     * onset date, and a duration typed by hand drifts out of step with it the
     * moment either changes. Null onset gives null duration — the banner then
     * renders without it rather than implying a duration of zero.
     */
    public function diseaseDurationYears(): ?float
    {
        if ($this->symptom_onset_on === null) {
            return null;
        }

        return round($this->symptom_onset_on->floatDiffInYears(now()), 1);
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
     * Age in whole years, or null when neither a date of birth nor an
     * estimated age was recorded.
     *
     * An estimated age is stored as the number given, anchored to enrolled_at,
     * and the years since are added back here. Storing the bare number and
     * reading it later would report the enrolment-day age forever; storing a
     * fabricated 1 January date of birth instead — which is what this used to
     * do — claims a precision nobody has and quietly fills any age-at-onset
     * distribution with a January spike. The anchor keeps the recorded fact
     * fixed and lets the derived answer move.
     */
    public function age(): ?int
    {
        if ($this->date_of_birth !== null) {
            return $this->date_of_birth->age;
        }

        if ($this->estimated_age === null) {
            return null;
        }

        return $this->estimated_age + (int) $this->enrolled_at->diffInYears(now());
    }

    /** True when the age above is an estimate rather than a calculated one. */
    public function ageIsEstimated(): bool
    {
        return $this->date_of_birth === null
            ? $this->estimated_age !== null
            : (bool) $this->dob_estimated;
    }

    /**
     * The year the patient was probably born, from whichever of the two facts
     * is present. Used for duplicate detection, where an approximate answer
     * from each side is still worth comparing.
     */
    public function approximateBirthYear(): ?int
    {
        if ($this->date_of_birth !== null) {
            return (int) $this->date_of_birth->year;
        }

        if ($this->estimated_age === null) {
            return null;
        }

        return (int) $this->enrolled_at->year - $this->estimated_age;
    }
}
