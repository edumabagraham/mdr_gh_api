<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Identifier systems
    |--------------------------------------------------------------------------
    |
    | patient_identifiers.system stores the short code on the left. The URI is
    | what that code becomes in a FHIR Patient.identifier, once an export
    | endpoint exists — the database never stores the URI itself, so a change
    | of namespace here does not require a data migration.
    |
    | 'collectable' => false keeps a system out of registration entirely. The
    | Ghana Card PIN stays closed until the ethics committee approves it in
    | writing (spec section 8.3); the row shape is ready for the day it does.
    |
    */

    'systems' => [

        'folder' => [
            'uri' => 'https://kath.gov.gh/fhir/identifier/folder-number',
            'label' => 'Hospital folder number',
            'collectable' => true,
            'repeatable' => false,
            'requires_assigner' => false,
        ],

        'nhis' => [
            'uri' => 'https://nhis.gov.gh/fhir/identifier/membership-number',
            'label' => 'NHIS membership number',
            'collectable' => true,
            'repeatable' => false,
            'requires_assigner' => false,
        ],

        'ghana_card' => [
            'uri' => 'https://nia.gov.gh/fhir/identifier/ghana-card-pin',
            'label' => 'Ghana Card PIN',
            'collectable' => false,
            'repeatable' => false,
            'requires_assigner' => false,
        ],

        'lhims' => [
            'uri' => 'https://kath.gov.gh/fhir/identifier/lhims-patient-id',
            'label' => 'LHIMS patient ID',
            'collectable' => true,
            'repeatable' => false,
            'requires_assigner' => false,
        ],

        'referral_folder' => [
            'uri' => 'https://moh.gov.gh/fhir/identifier/referral-folder-number',
            'label' => 'Referring facility folder number',
            'collectable' => true,
            'repeatable' => true,
            'requires_assigner' => true,
        ],

        'other' => [
            'uri' => 'https://kath.gov.gh/fhir/identifier/other',
            'label' => 'Other identifier',
            'collectable' => true,
            'repeatable' => true,
            'requires_assigner' => true,
        ],

    ],

    /*
    |--------------------------------------------------------------------------
    | Duplicate detection
    |--------------------------------------------------------------------------
    |
    | An exact match on any of these systems is a hard block: two records
    | carrying the same folder or NHIS number are the same person until a human
    | says otherwise. referral_folder and other are excluded — they are
    | repeatable and not owned by this hospital.
    |
    */

    'blocking_systems' => ['folder', 'nhis', 'ghana_card', 'lhims'],

    /*
    |--------------------------------------------------------------------------
    | Reasons a folder number may be absent
    |--------------------------------------------------------------------------
    |
    | Registration requires a folder number OR one of these coded reasons.
    | Free text is not accepted: a reason that cannot be counted cannot be
    | acted on, and "no folder" is a data-quality signal worth measuring.
    |
    */

    'folder_absent_reasons' => [
        'not_yet_issued',
        'patient_does_not_have_it',
        'illegible',
        'referred_from_elsewhere',
    ],

];
