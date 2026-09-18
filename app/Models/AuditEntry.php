<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Http\Request;

/**
 * Append-only record of who touched which patient.
 *
 * Every clinician can read every patient, by design — restricting visibility
 * is what causes the duplicate registrations this registry is built to avoid.
 * This table is how that openness stays accountable.
 */
#[Fillable(['user_id', 'action', 'subject_type', 'subject_id', 'context', 'ip_address', 'created_at'])]
class AuditEntry extends Model
{
    protected $table = 'audit_log';

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

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @param  array<string, mixed>  $context
     */
    public static function record(
        Request $request,
        string $action,
        string $subjectType,
        ?int $subjectId = null,
        array $context = [],
    ): void {
        static::create([
            'user_id' => $request->user()?->getKey(),
            'action' => $action,
            'subject_type' => $subjectType,
            'subject_id' => $subjectId,
            'context' => $context ?: null,
            'ip_address' => $request->ip(),
            'created_at' => now(),
        ]);
    }
}
