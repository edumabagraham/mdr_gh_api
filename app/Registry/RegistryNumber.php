<?php

declare(strict_types=1);

namespace App\Registry;

use InvalidArgumentException;

/**
 * Registry numbers: MDR-004127-36
 *
 *   MDR      fixed site prefix
 *   004127   zero-padded sequence from registry_no_seq
 *   36       ISO 7064 MOD 97-10 check digits
 *
 * The check digits exist because this number is transcribed by hand onto consent
 * forms, specimen tubes and referral letters. Without them a single transposed
 * digit silently attaches data to the wrong patient. MOD 97-10 catches every
 * single-digit error and every transposition of adjacent digits.
 *
 * The format carries no meaning. No year, no diagnosis, no initials, no sex.
 * Semantic identifiers leak identifying information onto every document they
 * appear on, and they break when the underlying fact changes — and diagnostic
 * revision is routine in this population.
 */
final class RegistryNumber
{
    public const PREFIX = 'MDR';

    private const SEQUENCE_LENGTH = 6;

    public static function format(int $sequence): string
    {
        if ($sequence < 1) {
            throw new InvalidArgumentException("Sequence must be positive, got {$sequence}.");
        }

        $digits = str_pad((string) $sequence, self::SEQUENCE_LENGTH, '0', STR_PAD_LEFT);

        if (strlen($digits) > self::SEQUENCE_LENGTH) {
            throw new InvalidArgumentException(
                "Sequence {$sequence} exceeds ".self::SEQUENCE_LENGTH.' digits. Widen the format deliberately, do not truncate.'
            );
        }

        return self::PREFIX.'-'.$digits.'-'.self::checkDigits($digits);
    }

    public static function isValid(string $candidate): bool
    {
        $parts = self::split($candidate);

        if ($parts === null) {
            return false;
        }

        return self::mod97($parts['digits'].$parts['check']) === 1;
    }

    /** Returns the sequence number, or null if the identifier is malformed or fails its checksum. */
    public static function sequenceOf(string $candidate): ?int
    {
        if (! self::isValid($candidate)) {
            return null;
        }

        return (int) self::split($candidate)['digits'];
    }

    /** Accepts loose user input: lowercase, missing hyphens, surrounding whitespace. */
    public static function canonicalise(string $input): ?string
    {
        $bare = strtoupper(preg_replace('/[^A-Za-z0-9]/', '', $input) ?? '');
        $expected = strlen(self::PREFIX) + self::SEQUENCE_LENGTH + 2;

        if (strlen($bare) !== $expected || ! str_starts_with($bare, self::PREFIX)) {
            return null;
        }

        $digits = substr($bare, strlen(self::PREFIX), self::SEQUENCE_LENGTH);
        $check = substr($bare, strlen(self::PREFIX) + self::SEQUENCE_LENGTH);
        $formatted = self::PREFIX.'-'.$digits.'-'.$check;

        return self::isValid($formatted) ? $formatted : null;
    }

    public static function checkDigits(string $digits): string
    {
        if (! preg_match('/^\d+$/', $digits)) {
            throw new InvalidArgumentException('Check digits require a numeric string.');
        }

        return str_pad((string) (98 - self::mod97($digits.'00')), 2, '0', STR_PAD_LEFT);
    }

    /**
     * @return array{digits: string, check: string}|null
     */
    private static function split(string $candidate): ?array
    {
        $pattern = '/^'.self::PREFIX.'-(\d{'.self::SEQUENCE_LENGTH.'})-(\d{2})$/';

        if (! preg_match($pattern, trim($candidate), $m)) {
            return null;
        }

        return ['digits' => $m[1], 'check' => $m[2]];
    }

    /**
     * Digit-by-digit modulo, so arbitrarily long numeric strings are safe without bcmath.
     */
    private static function mod97(string $numeric): int
    {
        $remainder = 0;

        for ($i = 0, $len = strlen($numeric); $i < $len; $i++) {
            $remainder = ($remainder * 10 + (int) $numeric[$i]) % 97;
        }

        return $remainder;
    }
}
