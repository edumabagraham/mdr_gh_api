<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A diagnosis as it stood on a date.
 *
 * Append-only: a revision writes a new row and marks this one superseded. In
 * movement disorders a patient labelled Parkinson's at year one may be MSA at
 * year three, and both the old label and the reason it changed are clinical
 * information. Overwriting would also destroy diagnostic stability at 3 and 5
 * years, which is a headline registry output that costs nothing to keep.
 */
#[Fillable([
    'patient_id', 'code', 'certainty', 'diagnosed_on', 'diagnosed_by',
    'criteria_set', 'criteria', 'rationale', 'superseded_at', 'superseded_by_id',
])]
class Diagnosis extends Model
{
    use HasFactory;

    public const CERTAINTIES = ['established', 'probable', 'possible', 'suspected'];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'diagnosed_on' => 'date',
            'criteria' => 'array',
            'superseded_at' => 'datetime',
        ];
    }

    public function scopeCurrent(Builder $query): Builder
    {
        return $query->whereNull('superseded_at');
    }

    public function patient(): BelongsTo
    {
        return $this->belongsTo(Patient::class);
    }

    public function diagnosedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'diagnosed_by');
    }

    public function supersededBy(): BelongsTo
    {
        return $this->belongsTo(Diagnosis::class, 'superseded_by_id');
    }

    public function isCurrent(): bool
    {
        return $this->superseded_at === null;
    }

    /** The human-readable label, from the vocabulary in config/modules.php. */
    public function label(): string
    {
        return config("modules.diagnoses.{$this->code}.label", $this->code);
    }

    /** The module this diagnosis opens. */
    public function module(): ?string
    {
        return config("modules.diagnoses.{$this->code}.module");
    }
}
