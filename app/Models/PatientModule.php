<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A disease module a patient belongs to.
 *
 * Persisted rather than derived from the current diagnosis, because a module
 * opens when a diagnosis sets it and stays open when that diagnosis is revised
 * away. A patient revised from PD to MSA has both: the PD data already
 * collected remains meaningful and must stay reachable.
 */
#[Fillable([
    'patient_id', 'module', 'opened_by_diagnosis_id', 'opened_at', 'is_primary', 'closed_at', 'closed_reason',
])]
class PatientModule extends Model
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
            'opened_at' => 'datetime',
            'closed_at' => 'datetime',
            'is_primary' => 'boolean',
        ];
    }

    public function scopeOpen(Builder $query): Builder
    {
        return $query->whereNull('closed_at');
    }

    public function patient(): BelongsTo
    {
        return $this->belongsTo(Patient::class);
    }

    public function openedByDiagnosis(): BelongsTo
    {
        return $this->belongsTo(Diagnosis::class, 'opened_by_diagnosis_id');
    }

    public function label(): string
    {
        return config("modules.modules.{$this->module}.label", $this->module);
    }

    /** The staging measure the banner shows while this module is primary. */
    public function stageInstrument(): ?string
    {
        return config("modules.modules.{$this->module}.stage_instrument");
    }
}
