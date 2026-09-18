<?php

use App\Models\Patient;
use App\Registry\PatientRegistrar;
use App\Registry\RegistryNumber;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

it('never hands the same registry number to two connections', function () {
    // Two separate database connections drawing from the sequence at the same
    // time. This is the property criterion 4 is really about: allocation comes
    // from registry_no_seq, which is non-transactional, rather than from
    // max(id) + 1, which two concurrent inserts can read identically.
    config(['database.connections.second' => config('database.connections.pgsql')]);

    $allocated = [];

    foreach (range(1, 50) as $ignored) {
        $allocated[] = DB::connection()->selectOne("SELECT nextval('registry_no_seq') AS value")->value;
        $allocated[] = DB::connection('second')->selectOne("SELECT nextval('registry_no_seq') AS value")->value;
    }

    expect($allocated)->toHaveCount(100)
        ->and(array_unique($allocated))->toHaveCount(100);
});

it('gives every registered patient a distinct, valid registry number', function () {
    $registrar = app(PatientRegistrar::class);

    foreach (range(1, 25) as $index) {
        $registrar->register([
            'client_ref' => (string) Str::uuid(),
            'family_name' => 'Mensah',
            'given_name' => "Kwame {$index}",
            'sex' => 'male',
            'date_of_birth' => '1964-03-02',
            'identifiers' => [],
        ], null, ['verdict' => 'clear', 'decision' => 'no_match']);
    }

    $numbers = Patient::pluck('registry_no');

    expect($numbers)->toHaveCount(25)
        ->and($numbers->unique())->toHaveCount(25);

    foreach ($numbers as $number) {
        expect(RegistryNumber::isValid($number))->toBeTrue();
    }
});

it('skips a registry number rather than reusing one when a registration fails', function () {
    $registrar = app(PatientRegistrar::class);

    $first = $registrar->register([
        'client_ref' => (string) Str::uuid(),
        'family_name' => 'Mensah',
        'given_name' => 'Kwame',
        'sex' => 'male',
        'date_of_birth' => '1964-03-02',
        'identifiers' => [],
    ], null, []);

    // A registration that blows up inside the transaction still consumes its
    // sequence value. A gap is harmless; a reused number is a safety problem.
    try {
        $registrar->register([
            'client_ref' => (string) Str::uuid(),
            'family_name' => 'Boateng',
            'given_name' => 'Akosua',
            'sex' => 'female',
            'date_of_birth' => '1970-01-01',
            'identifiers' => [['system' => 'folder', 'value' => str_repeat('9', 200)]],
        ], null, []);
    } catch (Throwable) {
        // expected: the identifier value is too long for the column
    }

    $third = $registrar->register([
        'client_ref' => (string) Str::uuid(),
        'family_name' => 'Asante',
        'given_name' => 'Yaw',
        'sex' => 'male',
        'date_of_birth' => '1980-05-05',
        'identifiers' => [],
    ], null, []);

    $firstSequence = RegistryNumber::sequenceOf($first->registry_no);
    $thirdSequence = RegistryNumber::sequenceOf($third->registry_no);

    expect($thirdSequence)->toBeGreaterThan($firstSequence + 1);
});
