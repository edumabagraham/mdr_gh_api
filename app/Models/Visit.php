<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'patient_id', 'type', 'scheduled_for', 'arrived_at', 'started_at', 'completed_at',
    'clinician_id', 'status', 'contact_attempts',
])]
class Visit extends Model
{
    use HasFactory;

    /**
     * Which statuses may follow which.
     *
     * A visit that is complete or cancelled is finished: reopening it would
     * quietly rewrite what happened in a clinic that has already ended.
     *
     * @var array<string, list<string>>
     */
    public const TRANSITIONS = [
        'scheduled' => ['arrived', 'missed', 'cancelled'],
        'arrived' => ['in_progress', 'missed', 'cancelled'],
        'in_progress' => ['complete', 'cancelled'],
        'complete' => [],
        'missed' => [],
        'cancelled' => [],
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'scheduled_for' => 'date',
            'arrived_at' => 'datetime',
            'started_at' => 'datetime',
            'completed_at' => 'datetime',
        ];
    }

    public function patient(): BelongsTo
    {
        return $this->belongsTo(Patient::class);
    }

    public function clinician(): BelongsTo
    {
        return $this->belongsTo(User::class, 'clinician_id');
    }

    public function canTransitionTo(string $status): bool
    {
        return in_array($status, self::TRANSITIONS[$this->status] ?? [], true);
    }

    /** Minutes since the patient arrived, for anyone still waiting. */
    public function waitingMinutes(): ?int
    {
        return $this->arrived_at === null ? null : (int) $this->arrived_at->diffInMinutes(now());
    }
}
