<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Two things the patients migration does not create.
 *
 * Kept separate from 2026_09_17_000000_create_patients_and_identifiers so that
 * migration stays the reference artifact it was handed over as.
 *
 * The audit table this originally created has been dropped in favour of
 * audit_events, which slice 0 owns and every later slice writes to.
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

    }

    public function down(): void
    {
        Schema::table('visits', function (Blueprint $table) {
            $table->dropColumn('contact_attempts');
        });

        DB::statement('ALTER TABLE patients DROP CONSTRAINT IF EXISTS patients_folder_absent_reason_check');

        Schema::table('patients', function (Blueprint $table) {
            $table->dropColumn('folder_absent_reason');
        });
    }
};
