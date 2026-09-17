<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Hash;

#[Fillable(['user_id', 'code_hash', 'attempts', 'expires_at'])]
class EmailVerificationCode extends Model
{
    /**
     * How long a freshly issued code stays usable.
     */
    public const LIFETIME_MINUTES = 15;

    /**
     * Wrong guesses allowed before the code is burned and must be re-sent.
     */
    public const MAX_ATTEMPTS = 5;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'expires_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Issue a fresh code for the user, replacing any code already outstanding,
     * and return the plain-text code so it can be mailed.
     */
    public static function issueFor(User $user): string
    {
        $code = (string) random_int(100000, 999999);

        static::updateOrCreate(
            ['user_id' => $user->getKey()],
            [
                'code_hash' => Hash::make($code),
                'attempts' => 0,
                'expires_at' => now()->addMinutes(self::LIFETIME_MINUTES),
            ],
        );

        return $code;
    }

    public function isExpired(): bool
    {
        return $this->expires_at->isPast();
    }

    public function hasTooManyAttempts(): bool
    {
        return $this->attempts >= self::MAX_ATTEMPTS;
    }

    public function matches(string $code): bool
    {
        return Hash::check($code, $this->code_hash);
    }
}
