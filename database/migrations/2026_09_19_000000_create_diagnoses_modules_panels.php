<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // ------------------------------------------------------------------
        // Symptom onset lives on the patient, not on the diagnosis: onset is a
        // fact about the illness and survives every diagnostic revision.
        // Disease duration in the banner is derived from this, never typed.
        // ------------------------------------------------------------------
        Schema::table('patients', function (Blueprint $table) {
            $table->date('symptom_onset_on')->nullable()->after('sex');
            $table->boolean('symptom_onset_estimated')->default(false)->after('symptom_onset_on');
            $table->string('first_symptom', 40)->nullable()->after('symptom_onset_estimated');
            $table->string('side_of_onset', 16)->nullable()->after('first_symptom');
            $table->date('nonmotor_onset_on')->nullable()->after('side_of_onset');
        });

        DB::statement("
            ALTER TABLE patients ADD CONSTRAINT patients_side_of_onset_check
            CHECK (side_of_onset IS NULL OR side_of_onset IN ('right','left','bilateral','axial'))
        ");

        // ------------------------------------------------------------------
        // diagnoses — append-only history.
        //
        // A revision NEVER updates the previous row. It supersedes it. Diagnostic
        // change is routine in movement disorders (PD revised to MSA at year three)
        // and diagnostic stability at 3 and 5 years is a headline registry output
        // that costs nothing extra to produce if the history is kept.
        // ------------------------------------------------------------------
        Schema::create('diagnoses', function (Blueprint $table) {
            $table->id();
            $table->foreignId('patient_id')->constrained()->restrictOnDelete();

            $table->string('code', 40);                    // see config/modules.php
            $table->string('certainty', 20);               // established | probable | possible | suspected
            $table->date('diagnosed_on');
            $table->foreignId('diagnosed_by')->nullable()->constrained('users')->nullOnDelete();

            // Criteria checklist as applied, e.g. MDS 2015 for PD. Stored as the
            // answers, not as a conclusion, so a later criteria revision can be
            // re-evaluated against what was actually observed.
            $table->string('criteria_set', 40)->nullable(); // mds_pd_2015 | mds_msa_2022 | mds_psp_2017 | ...
            $table->jsonb('criteria')->nullable();

            $table->text('rationale')->nullable();          // required when superseding
            $table->timestampTz('superseded_at')->nullable();
            $table->foreignId('superseded_by_id')->nullable()->constrained('diagnoses')->nullOnDelete();

            $table->timestamps();

            $table->index(['patient_id', 'superseded_at']);
            $table->index('code');
        });

        DB::statement("
            ALTER TABLE diagnoses ADD CONSTRAINT diagnoses_certainty_check
            CHECK (certainty IN ('established','probable','possible','suspected'))
        ");

        // Exactly one current diagnosis per patient.
        DB::statement('
            CREATE UNIQUE INDEX diagnoses_one_current_per_patient
            ON diagnoses (patient_id)
            WHERE superseded_at IS NULL
        ');

        // ------------------------------------------------------------------
        // patient_modules — persisted, not derived.
        //
        // A module opens when a diagnosis sets it and STAYS open when the
        // diagnosis is revised. A patient revised from PD to MSA has both modules:
        // the PD data already collected remains meaningful and must stay reachable.
        // ------------------------------------------------------------------
        Schema::create('patient_modules', function (Blueprint $table) {
            $table->id();
            $table->foreignId('patient_id')->constrained()->restrictOnDelete();
            $table->string('module', 24);                  // pd | atypical | hd | dystonia | ataxia | tremor | dementia | mnd | other
            $table->foreignId('opened_by_diagnosis_id')->nullable()->constrained('diagnoses')->nullOnDelete();
            $table->timestampTz('opened_at');
            $table->boolean('is_primary')->default(false); // supplies the banner staging measure
            $table->timestampTz('closed_at')->nullable();
            $table->string('closed_reason', 255)->nullable();
            $table->timestamps();

            $table->index(['patient_id', 'closed_at']);
        });

        DB::statement('
            CREATE UNIQUE INDEX patient_modules_unique_open
            ON patient_modules (patient_id, module)
            WHERE closed_at IS NULL
        ');

        // Only one module supplies the banner stage, so the clinician is never
        // shown two competing stages.
        DB::statement('
            CREATE UNIQUE INDEX patient_modules_one_primary
            ON patient_modules (patient_id)
            WHERE is_primary AND closed_at IS NULL
        ');

        // ------------------------------------------------------------------
        // patient_panels — opened by a clinical FEATURE, not a diagnosis label.
        //
        // Anyone on a dopamine agonist needs impulse-control screening whether
        // they are labelled PD, MSA or vascular parkinsonism.
        // ------------------------------------------------------------------
        Schema::create('patient_panels', function (Blueprint $table) {
            $table->id();
            $table->foreignId('patient_id')->constrained()->restrictOnDelete();
            $table->string('panel', 32);                   // cognitive | synuclein_nonmotor | dopaminergic_therapy | falls_mobility | bulbar_respiratory
            $table->string('trigger', 60);                 // which rule opened it
            $table->jsonb('trigger_context')->nullable();  // the evidence, for audit
            $table->timestampTz('opened_at');
            $table->timestampTz('closed_at')->nullable();
            $table->string('closed_reason', 255)->nullable();
            $table->timestamps();

            $table->index(['patient_id', 'closed_at']);
        });

        DB::statement('
            CREATE UNIQUE INDEX patient_panels_unique_open
            ON patient_panels (patient_id, panel)
            WHERE closed_at IS NULL
        ');
    }

    public function down(): void
    {
        Schema::dropIfExists('patient_panels');
        Schema::dropIfExists('patient_modules');
        Schema::dropIfExists('diagnoses');

        DB::statement('ALTER TABLE patients DROP CONSTRAINT IF EXISTS patients_side_of_onset_check');

        Schema::table('patients', function (Blueprint $table) {
            $table->dropColumn([
                'symptom_onset_on', 'symptom_onset_estimated',
                'first_symptom', 'side_of_onset', 'nonmotor_onset_on',
            ]);
        });
    }
};
