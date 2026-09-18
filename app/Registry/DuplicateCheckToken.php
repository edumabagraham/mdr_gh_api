<?php

declare(strict_types=1);

namespace App\Registry;

use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Support\Facades\Crypt;

/**
 * The short-lived token that proves a duplicate check actually happened.
 *
 * POST /api/patients refuses to create anything without one. Enforcing the
 * workflow server-side matters because the client is the part most likely to
 * be replaced, bypassed, or replayed from an offline queue days later.
 *
 * The token carries a fingerprint of the patient it was issued for, so it
 * cannot be reused for a different person: check patient A, then submit
 * patient B with A's token, and verification fails.
 */
final class DuplicateCheckToken
{
    public const TTL_MINUTES = 15;

    /**
     * @param  array<string, mixed>  $payload
     */
    public static function issue(array $payload, string $verdict): string
    {
        return Crypt::encryptString((string) json_encode([
            'fingerprint' => self::fingerprint($payload),
            'verdict' => $verdict,
            'expires_at' => now()->addMinutes(self::TTL_MINUTES)->timestamp,
        ]));
    }

    /**
     * The verdict the token was issued with, or null if it is invalid, expired,
     * or was issued for a different patient.
     *
     * @param  array<string, mixed>  $payload
     */
    public static function verdictFor(string $token, array $payload): ?string
    {
        try {
            $claims = json_decode(Crypt::decryptString($token), true);
        } catch (DecryptException) {
            return null;
        }

        if (! is_array($claims) || ! isset($claims['fingerprint'], $claims['verdict'], $claims['expires_at'])) {
            return null;
        }

        if ($claims['expires_at'] < now()->timestamp) {
            return null;
        }

        if (! hash_equals((string) $claims['fingerprint'], self::fingerprint($payload))) {
            return null;
        }

        return (string) $claims['verdict'];
    }

    /**
     * Identity of the proposed patient, in a form that is stable across the two
     * requests. Only the fields the check actually looks at are included —
     * correcting a phone number between check and submit should not invalidate
     * the check, but changing the name should.
     *
     * @param  array<string, mixed>  $payload
     */
    public static function fingerprint(array $payload): string
    {
        $identifiers = collect($payload['identifiers'] ?? [])
            ->map(fn (array $identifier): string => $identifier['system'].':'.$identifier['value'])
            ->sort()
            ->values()
            ->all();

        return hash('sha256', (string) json_encode([
            'name' => NameNormaliser::normalise(
                $payload['family_name'] ?? null,
                $payload['given_name'] ?? null,
                $payload['other_names'] ?? null,
            ),
            'date_of_birth' => $payload['date_of_birth'] ?? null,
            'age' => $payload['age'] ?? null,
            'sex' => $payload['sex'] ?? null,
            'identifiers' => $identifiers,
        ]));
    }
}
