<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A panel of extra data collection, opened by a clinical feature rather than a
 * diagnosis label.
 *
 * Anyone on a dopamine agonist needs impulse-control screening whether they are
 * labelled PD, MSA or vascular parkinsonism.
 */
#[Fillable(['patient_id', 'panel', 'trigger', 'trigger_context', 'opened_at', 'closed_at', 'closed_reason'])]
class PatientPanel extends Model
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
            'trigger_context' => 'array',
            'opened_at' => 'datetime',
            'closed_at' => 'datetime',
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

    public function label(): string
    {
        return config("modules.panels.{$this->panel}.label", $this->panel);
    }
}
