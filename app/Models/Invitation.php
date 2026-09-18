<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

/**
 * The only route to an account.
 *
 * The emailed token is never stored. What is stored is its SHA-256 hash, so a
 * leaked database — or a leaked backup, or a log line — hands out nothing
 * usable. Acceptance hashes what it is given and looks for a match.
 */
#[Fillable([
    'email', 'role', 'token_hash', 'invited_by', 'expires_at', 'accepted_at', 'user_id', 'revoked_at', 'revoked_by',
])]
class Invitation extends Model
{
    use HasFactory;

    public const LIFETIME_DAYS = 7;

    private const TOKEN_BYTES = 32;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'expires_at' => 'datetime',
            'accepted_at' => 'datetime',
            'revoked_at' => 'datetime',
        ];
    }

    /** A fresh token. The plaintext is returned once and never persisted. */
    public static function generateToken(): string
    {
        return Str::random(self::TOKEN_BYTES * 2);
    }

    public static function hashToken(string $token): string
    {
        return hash('sha256', $token);
    }

    public function scopePending(Builder $query): Builder
    {
        return $query->whereNull('accepted_at')->whereNull('revoked_at');
    }

    public function isPending(): bool
    {
        return $this->accepted_at === null && $this->revoked_at === null;
    }

    public function isExpired(): bool
    {
        return $this->expires_at->isPast();
    }

    public function isUsable(): bool
    {
        return $this->isPending() && ! $this->isExpired();
    }

    /** pending | accepted | revoked | expired */
    public function status(): string
    {
        return match (true) {
            $this->revoked_at !== null => 'revoked',
            $this->accepted_at !== null => 'accepted',
            $this->isExpired() => 'expired',
            default => 'pending',
        };
    }

    public function invitedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'invited_by');
    }

    public function revokedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'revoked_by');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
