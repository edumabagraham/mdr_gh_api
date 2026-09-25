<?php

/**
 * Diagnosis vocabulary, module routing and panel triggers.
 *
 * Kept in config rather than the database: this is clinical policy that belongs
 * under version control and code review, not something an admin edits at runtime.
 * Changing which module a diagnosis opens is a decision with a paper trail.
 */

return [

    /*
    |--------------------------------------------------------------------------
    | Diagnoses
    |--------------------------------------------------------------------------
    | 'module' is the module this diagnosis opens. 'criteria' names the checklist
    | the clinician completes, where a published one exists.
    */

    'diagnoses' => [

        // --- Parkinson's disease -------------------------------------------
        'pd_established' => ['label' => 'Parkinson\'s disease, clinically established', 'module' => 'pd',        'criteria' => 'mds_pd_2015'],
        'pd_probable' => ['label' => 'Parkinson\'s disease, clinically probable',    'module' => 'pd',        'criteria' => 'mds_pd_2015'],
        'pd_prodromal' => ['label' => 'Prodromal Parkinson\'s disease',               'module' => 'pd',        'criteria' => 'mds_prodromal_2019'],
        'pd_mci' => ['label' => 'PD with mild cognitive impairment',            'module' => 'pd',        'criteria' => 'mds_pdmci'],
        'pdd' => ['label' => 'Parkinson\'s disease dementia',                'module' => 'dementia',  'criteria' => 'mds_pdd'],

        // --- Atypical parkinsonism -----------------------------------------
        'msa_p' => ['label' => 'Multiple system atrophy, parkinsonian',        'module' => 'atypical',  'criteria' => 'mds_msa_2022'],
        'msa_c' => ['label' => 'Multiple system atrophy, cerebellar',          'module' => 'atypical',  'criteria' => 'mds_msa_2022'],
        'psp_rs' => ['label' => 'PSP, Richardson syndrome',                     'module' => 'atypical',  'criteria' => 'mds_psp_2017'],
        'psp_p' => ['label' => 'PSP, parkinsonism predominant',                'module' => 'atypical',  'criteria' => 'mds_psp_2017'],
        'psp_pgf' => ['label' => 'PSP, progressive gait freezing',               'module' => 'atypical',  'criteria' => 'mds_psp_2017'],
        'cbs' => ['label' => 'Corticobasal syndrome',                        'module' => 'atypical',  'criteria' => 'armstrong_2013'],

        // --- Huntington's and chorea ---------------------------------------
        'hd' => ['label' => 'Huntington\'s disease',                        'module' => 'hd',        'criteria' => null],
        'chorea_other' => ['label' => 'Chorea, other cause',                          'module' => 'hd',        'criteria' => null],

        // --- Dystonia -------------------------------------------------------
        'dystonia_focal' => ['label' => 'Focal dystonia',                               'module' => 'dystonia',  'criteria' => null],
        'dystonia_cervical' => ['label' => 'Cervical dystonia',                            'module' => 'dystonia',  'criteria' => null],
        'dystonia_segmental' => ['label' => 'Segmental dystonia',                           'module' => 'dystonia',  'criteria' => null],
        'dystonia_generalised' => ['label' => 'Generalised dystonia',                         'module' => 'dystonia',  'criteria' => null],
        'blepharospasm' => ['label' => 'Blepharospasm',                                'module' => 'dystonia',  'criteria' => null],
        'tardive_syndrome' => ['label' => 'Tardive dystonia or dyskinesia',               'module' => 'dystonia',  'criteria' => null],

        // --- Ataxia ---------------------------------------------------------
        'ataxia_sca' => ['label' => 'Spinocerebellar ataxia',                       'module' => 'ataxia',    'criteria' => null],
        'ataxia_friedreich' => ['label' => 'Friedreich\'s ataxia',                         'module' => 'ataxia',    'criteria' => null],
        'ataxia_sporadic' => ['label' => 'Sporadic or acquired ataxia',                  'module' => 'ataxia',    'criteria' => null],

        // --- Tremor ---------------------------------------------------------
        'et' => ['label' => 'Essential tremor',                             'module' => 'tremor',    'criteria' => 'mds_tremor_2018'],
        'et_plus' => ['label' => 'Essential tremor plus',                        'module' => 'tremor',    'criteria' => 'mds_tremor_2018'],
        'tremor_dystonic' => ['label' => 'Dystonic tremor',                              'module' => 'tremor',    'criteria' => 'mds_tremor_2018'],

        // --- Dementia and Lewy body -----------------------------------------
        'dlb' => ['label' => 'Dementia with Lewy bodies',                    'module' => 'dementia',  'criteria' => 'mckeith_2017'],
        'dementia_ad' => ['label' => 'Alzheimer\'s disease',                         'module' => 'dementia',  'criteria' => null],
        'dementia_vascular' => ['label' => 'Vascular dementia',                            'module' => 'dementia',  'criteria' => null],
        'dementia_mixed' => ['label' => 'Mixed dementia',                               'module' => 'dementia',  'criteria' => null],
        'dementia_ftd' => ['label' => 'Frontotemporal dementia',                      'module' => 'dementia',  'criteria' => null],

        // --- Motor neurone disease ------------------------------------------
        'als' => ['label' => 'Amyotrophic lateral sclerosis',                'module' => 'mnd',       'criteria' => 'gold_coast'],
        'pls' => ['label' => 'Primary lateral sclerosis',                    'module' => 'mnd',       'criteria' => 'gold_coast'],
        'pma' => ['label' => 'Progressive muscular atrophy',                 'module' => 'mnd',       'criteria' => 'gold_coast'],
        'pbp' => ['label' => 'Progressive bulbar palsy',                     'module' => 'mnd',       'criteria' => 'gold_coast'],

        // --- Other and rare --------------------------------------------------
        'drug_induced_park' => ['label' => 'Drug-induced parkinsonism',                    'module' => 'other',     'criteria' => null],
        'vascular_park' => ['label' => 'Vascular parkinsonism',                        'module' => 'other',     'criteria' => null],
        'nph' => ['label' => 'Normal pressure hydrocephalus',                'module' => 'other',     'criteria' => null],
        'wilson' => ['label' => 'Wilson disease',                               'module' => 'other',     'criteria' => null],
        'tourette' => ['label' => 'Tourette syndrome or chronic tics',            'module' => 'other',     'criteria' => null],
        'myoclonus' => ['label' => 'Myoclonus',                                    'module' => 'other',     'criteria' => null],
        'rls' => ['label' => 'Restless legs syndrome',                       'module' => 'other',     'criteria' => null],
        'functional' => ['label' => 'Functional movement disorder',                 'module' => 'other',     'criteria' => null],

        // Honest, and analytically useful. A free-text field would hide these.
        'undetermined' => ['label' => 'Undetermined movement disorder',               'module' => 'other',     'criteria' => null],
    ],

    /*
    |--------------------------------------------------------------------------
    | Modules
    |--------------------------------------------------------------------------
    | 'stage_instrument' is the measure shown on the banner when this module is
    | primary. When several modules are open, the most recently opened becomes
    | primary unless a clinician overrides it.
    */

    'modules' => [
        'pd' => ['label' => 'Parkinson\'s disease',   'stage_instrument' => 'HY'],
        'atypical' => ['label' => 'Atypical parkinsonism',  'stage_instrument' => 'HY'],
        'hd' => ['label' => 'Huntington\'s disease',  'stage_instrument' => 'UHDRS_TFC'],
        'dystonia' => ['label' => 'Dystonia',               'stage_instrument' => null],
        'ataxia' => ['label' => 'Ataxia',                 'stage_instrument' => 'SARA'],
        'tremor' => ['label' => 'Tremor',                 'stage_instrument' => 'TETRAS'],
        'dementia' => ['label' => 'Dementia and DLB',       'stage_instrument' => 'CDR'],
        'mnd' => ['label' => 'Motor neurone disease',  'stage_instrument' => 'ALSFRS_R'],
        'other' => ['label' => 'Other and rare',         'stage_instrument' => null],
    ],

    /*
    |--------------------------------------------------------------------------
    | Panel triggers
    |--------------------------------------------------------------------------
    | Evaluated by App\Registry\PanelEvaluator after any event that could change
    | the answer: diagnosis set, medication changed, assessment completed, fall
    | recorded. Each entry documents WHAT opens the panel; the evaluator holds the
    | implementation.
    |
    | Panels close only when the triggering feature resolves, and closure is
    | recorded. A panel that silently disappears is missing data.
    */

    'panels' => [

        'cognitive' => [
            'label' => 'Cognitive',
            'opens_when' => [
                'diagnosis_in' => ['pd_mci', 'pdd', 'dlb', 'dementia_ad', 'dementia_vascular', 'dementia_mixed', 'dementia_ftd', 'hd', 'psp_rs', 'cbs', 'als'],
                'moca_below' => 26,
                'cognitive_complaint_recorded' => true,
            ],
        ],

        'synuclein_nonmotor' => [
            'label' => 'Synucleinopathy non-motor',
            'opens_when' => [
                'diagnosis_in' => ['pd_established', 'pd_probable', 'pd_prodromal', 'pd_mci', 'pdd', 'dlb', 'msa_p', 'msa_c'],
                'rbd_screen_positive' => true,
            ],
        ],

        'dopaminergic_therapy' => [
            'label' => 'Dopaminergic therapy',
            'opens_when' => [
                // Deliberately NOT diagnosis-based.
                'on_levodopa' => true,
                'on_dopamine_agonist' => true,
            ],
        ],

        'falls_mobility' => [
            'label' => 'Falls and mobility',
            'opens_when' => [
                'fall_in_interval' => true,
                'freezing_reported' => true,
                'gait_item_above' => 0,
            ],
        ],

        'bulbar_respiratory' => [
            'label' => 'Bulbar and respiratory',
            'opens_when' => [
                'diagnosis_in' => ['als', 'pls', 'pma', 'pbp', 'psp_rs', 'psp_p', 'psp_pgf'],
                'dysphagia_screen_positive' => true,
            ],
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Core assessments
    |--------------------------------------------------------------------------
    | Cross-disease instruments that apply to every patient regardless of label,
    | and therefore do not wait for a diagnosis — see spec section 3.6.
    |
    | This matters more than it looks. A first visit often ends without a firm
    | diagnosis; 'undetermined' exists in the vocabulary for exactly that case.
    | If the core set were gated behind a diagnosis, that visit would collect
    | nothing, the patient might not return, and the registry would hold an
    | entry with no data in it.
    |
    | Only the module instruments above — MDS-UPDRS, SARA, UHDRS, TETRAS — need
    | the diagnosis first.
    */

    'core_assessments' => [
        'barthel' => ['label' => 'Barthel Index', 'domain' => 'function'],
        'idea' => ['label' => 'IDEA cognitive screen', 'domain' => 'cognition'],
        'moca' => ['label' => 'MoCA', 'domain' => 'cognition'],
        'npi' => ['label' => 'Neuropsychiatric Inventory', 'domain' => 'neuropsychiatric'],
        'ham_a' => ['label' => 'Hamilton Anxiety Rating Scale', 'domain' => 'neuropsychiatric'],
        'eq5d5l' => ['label' => 'EQ-5D-5L', 'domain' => 'quality of life'],
    ],

    /*
    |--------------------------------------------------------------------------
    | Expression context
    |--------------------------------------------------------------------------
    | The explicit allow-list of patient facts exposed to instrument branching
    | rules as patient.* — see instrument-schema/README.md section 5.
    |
    | This is a contract, not a convenience. Never expose the patient model to
    | the expression context: every key here appears in stored schemas and can
    | only be removed by versioning every instrument that references it.
    */

    'expression_context' => [
        'age',
        'sex',
        'diagnosis_code',
        'diagnosis_certainty',
        'disease_duration_years',
        'symptom_onset_on',
        'module_pd', 'module_atypical', 'module_hd', 'module_dystonia',
        'module_ataxia', 'module_tremor', 'module_dementia', 'module_mnd', 'module_other',
        'panel_cognitive', 'panel_synuclein_nonmotor', 'panel_dopaminergic_therapy',
        'panel_falls_mobility', 'panel_bulbar_respiratory',
        'on_levodopa',
        'on_dopamine_agonist',
        'ledd',
        'has_device_therapy',
    ],
];
