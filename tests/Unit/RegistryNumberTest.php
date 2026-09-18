<?php

use App\Registry\RegistryNumber;

/**
 * Acceptance criteria 1-3 of slice 1.
 *
 * These are pure unit tests: RegistryNumber touches no framework service and no
 * database, so they run whatever the connection is configured to be.
 */

/**
 * A deterministic spread of sequence numbers to check properties against — same
 * set on every run, so a failure can be reproduced from the output alone.
 *
 * @return list<int>
 */
function sampleSequences(int $count = 1000): array
{
    mt_srand(20260917);

    $sequences = [1, 2, 9, 10, 99, 100, 4127, 999999];

    while (count($sequences) < $count) {
        $sequences[] = mt_rand(1, 999999);
    }

    return array_slice($sequences, 0, $count);
}

it('formats a sequence into a registry number with check digits', function () {
    expect(RegistryNumber::format(4127))->toBe('MDR-004127-36')
        ->and(RegistryNumber::isValid('MDR-004127-36'))->toBeTrue();
});

it('round trips every formatted number back to its sequence', function () {
    foreach (sampleSequences() as $sequence) {
        $formatted = RegistryNumber::format($sequence);

        expect(RegistryNumber::isValid($formatted))->toBeTrue()
            ->and(RegistryNumber::sequenceOf($formatted))->toBe($sequence);
    }
});

it('rejects every single-digit alteration of a valid number', function () {
    $failures = [];

    foreach (sampleSequences() as $sequence) {
        $valid = RegistryNumber::format($sequence);
        $digits = substr($valid, 4, 6).substr($valid, 11, 2);

        foreach (str_split($digits) as $position => $digit) {
            foreach (range(0, 9) as $replacement) {
                if ((string) $replacement === $digit) {
                    continue;
                }

                $altered = $digits;
                $altered[$position] = (string) $replacement;

                $candidate = 'MDR-'.substr($altered, 0, 6).'-'.substr($altered, 6, 2);

                if (RegistryNumber::isValid($candidate)) {
                    $failures[] = "{$valid} -> {$candidate}";
                }
            }
        }
    }

    expect($failures)->toBe([]);
});

it('rejects every transposition of adjacent digits', function () {
    $failures = [];

    foreach (sampleSequences() as $sequence) {
        $valid = RegistryNumber::format($sequence);
        $digits = substr($valid, 4, 6).substr($valid, 11, 2);

        for ($i = 0; $i < 7; $i++) {
            // Swapping two equal digits changes nothing, so there is no error to catch.
            if ($digits[$i] === $digits[$i + 1]) {
                continue;
            }

            $swapped = $digits;
            [$swapped[$i], $swapped[$i + 1]] = [$swapped[$i + 1], $swapped[$i]];

            $candidate = 'MDR-'.substr($swapped, 0, 6).'-'.substr($swapped, 6, 2);

            if (RegistryNumber::isValid($candidate)) {
                $failures[] = "{$valid} -> {$candidate}";
            }
        }
    }

    expect($failures)->toBe([]);
});

it('canonicalises loose user input', function () {
    expect(RegistryNumber::canonicalise('mdr00412736'))->toBe('MDR-004127-36')
        ->and(RegistryNumber::canonicalise(' MDR-004127-36 '))->toBe('MDR-004127-36')
        ->and(RegistryNumber::canonicalise('MDR 004127 36'))->toBe('MDR-004127-36');
});

it('refuses input that is not a registry number', function () {
    expect(RegistryNumber::canonicalise('kmd00412736'))->toBeNull()
        ->and(RegistryNumber::canonicalise('MDR-004127-37'))->toBeNull()
        ->and(RegistryNumber::canonicalise('227845'))->toBeNull()
        ->and(RegistryNumber::isValid('MDR-004127-3'))->toBeFalse();
});

it('will not format a sequence outside the six digit window', function () {
    expect(fn () => RegistryNumber::format(0))->toThrow(InvalidArgumentException::class)
        ->and(fn () => RegistryNumber::format(-1))->toThrow(InvalidArgumentException::class)
        ->and(fn () => RegistryNumber::format(1000000))->toThrow(InvalidArgumentException::class);
});
