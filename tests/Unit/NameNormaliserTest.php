<?php

use App\Registry\NameNormaliser;

it('sorts tokens so name order cannot hide a duplicate', function () {
    expect(NameNormaliser::normalise('Mensah', 'Kwame'))
        ->toBe(NameNormaliser::normalise('Kwame', 'Mensah'))
        ->toBe('kwame mensah');
});

it('folds diacritics to their plain letters', function () {
    expect(NameNormaliser::normalise('Adjeí', 'Kofí'))->toBe('adjei kofi')
        ->and(NameNormaliser::normalise('Müller', 'Anaïs'))->toBe('anais muller');
});

it('drops punctuation, digits and case', function () {
    expect(NameNormaliser::normalise("O'Brien-Nkrumah", 'Nana Ama'))
        ->toBe('ama brien nana nkrumah o')
        ->and(NameNormaliser::normalise('MENSAH', 'kwame', '2nd'))->toBe('kwame mensah nd');
});

it('collapses stray whitespace and ignores missing parts', function () {
    expect(NameNormaliser::normalise('  Mensah  ', null, ''))->toBe('mensah')
        ->and(NameNormaliser::normalise('Mensah', 'Kwame', 'Kofi   Yaw'))
        ->toBe('kofi kwame mensah yaw');
});

it('returns an empty string when there is nothing to normalise', function () {
    expect(NameNormaliser::normalise(null, '', '   '))->toBe('');
});
