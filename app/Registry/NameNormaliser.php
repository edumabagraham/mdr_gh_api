<?php

declare(strict_types=1);

namespace App\Registry;

use Illuminate\Support\Str;

/**
 * Builds patients.name_normalised, the string every fuzzy match runs against.
 *
 * The token sort is the part that matters. "Kwame Mensah" and "Mensah Kwame"
 * are one person entered by two clerks who disagreed about which name is the
 * family name; comparing the strings in their original order misses it, and a
 * missed match is a duplicate patient record.
 *
 * Diacritics are stripped rather than preserved because they are recorded
 * inconsistently — the same patient is Kofí on one form and Kofi on the next,
 * and the registry must treat those as the same name.
 */
final class NameNormaliser
{
    public static function normalise(?string ...$parts): string
    {
        $combined = implode(' ', array_filter($parts, fn (?string $part) => $part !== null && $part !== ''));

        // Str::ascii folds the diacritics; whatever it cannot fold is not a
        // letter we can match on anyway, so the next step drops it.
        $ascii = Str::lower(Str::ascii($combined));

        $lettersOnly = preg_replace('/[^a-z ]+/', ' ', $ascii) ?? '';

        $tokens = array_values(array_filter(explode(' ', $lettersOnly), fn (string $token) => $token !== ''));

        sort($tokens, SORT_STRING);

        return implode(' ', $tokens);
    }
}
