<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Http\Request;
use RuntimeException;

/**
 * Append-only record of who did what.
 *
 * Every clinician can read every patient, by design — restricting visibility
 * is what causes the duplicate registrations this registry is built to avoid.
 * This table is how that openness stays accountable, so it accepts inserts and
 * nothing else: the model refuses updates and deletes outright, rather than
 * relying on nobody ever writing the code.
 */
#[Fillable([
    'actor_id', 'action', 'subject_type', 'subject_id', 'context', 'ip_address', 'user_agent', 'created_at',
])]
class AuditEntry extends Model
{
    protected $table = 'audit_events';

    public $timestamps = false;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'context' => 'array',
            'created_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::updating(function (): never {
            throw new RuntimeException('audit_events is append-only: entries cannot be updated.');
        });

        static::deleting(function (): never {
            throw new RuntimeException('audit_events is append-only: entries cannot be deleted.');
        });
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_id');
    }

    /**
     * @param  array<string, mixed>  $context
     */
    public static function record(
        Request $request,
        string $action,
        ?string $subjectType = null,
        ?int $subjectId = null,
        array $context = [],
    ): void {
        static::create([
            'actor_id' => $request->user()?->getKey(),
            'action' => $action,
            'subject_type' => $subjectType,
            'subject_id' => $subjectId,
            'context' => $context ?: null,
            'ip_address' => $request->ip(),
            'user_agent' => substr((string) $request->userAgent(), 0, 255) ?: null,
            'created_at' => now(),
        ]);
    }
}
