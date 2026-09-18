<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Three things slice 1 requires that the patients migration does not create.
 *
 * Kept separate from 2026_09_17_000000_create_patients_and_identifiers so that
 * migration stays the reference artifact it was handed over as.
 */
return new class extends Migration
{
    public function up(): void
    {
        // Registration accepts a missing folder number only with a coded reason.
        // The list is mirrored in config/identifiers.php, which is what the
        // validator reads; the constraint is the backstop for anything that
        // reaches the table by another route.
        Schema::table('patients', function (Blueprint $table) {
            $table->string('folder_absent_reason', 40)->nullable()->after('client_ref');
        });

        DB::statement("
            ALTER TABLE patients ADD CONSTRAINT patients_folder_absent_reason_check
            CHECK (
                folder_absent_reason IS NULL
                OR folder_absent_reason IN (
                    'not_yet_issued','patient_does_not_have_it','illegible','referred_from_elsewhere'
                )
            )
        ");

        // Shown against every overdue follow-up on the dashboard: a patient
        // nobody has managed to reach is a different problem from one nobody
        // has tried to reach.
        Schema::table('visits', function (Blueprint $table) {
            $table->unsignedSmallInteger('contact_attempts')->default(0)->after('status');
        });

        // Append-only. Access to patient records is not restricted by who
        // enrolled them, so this log is how the governance concern is met —
        // every read is attributable after the fact.
        Schema::create('audit_log', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('action', 40);         // patient.view | patient.create | patient.search
            $table->string('subject_type', 40);   // patient | visit
            $table->unsignedBigInteger('subject_id')->nullable();
            $table->jsonb('context')->nullable();
            $table->string('ip_address', 45)->nullable();
            $table->timestampTz('created_at');

            $table->index(['subject_type', 'subject_id', 'created_at']);
            $table->index(['user_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('audit_log');

        Schema::table('visits', function (Blueprint $table) {
            $table->dropColumn('contact_attempts');
        });

        DB::statement('ALTER TABLE patients DROP CONSTRAINT IF EXISTS patients_folder_absent_reason_check');

        Schema::table('patients', function (Blueprint $table) {
            $table->dropColumn('folder_absent_reason');
        });
    }
};
