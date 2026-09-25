<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('patients', function (Blueprint $table) {
            // Many patients do not know their date of birth. The registration form
            // already offers the checkbox; this is what it writes to. Age is
            // interpreted relative to enrolled_at, so it never silently ages.
            $table->unsignedSmallInteger('estimated_age')->nullable()->after('dob_estimated');
        });

        DB::statement('
            ALTER TABLE patients ADD CONSTRAINT patients_estimated_age_range
            CHECK (estimated_age IS NULL OR estimated_age BETWEEN 0 AND 120)
        ');

        // Either a real date of birth, or an estimated age. Never neither.
        DB::statement('
            ALTER TABLE patients ADD CONSTRAINT patients_age_known
            CHECK (date_of_birth IS NOT NULL OR estimated_age IS NOT NULL)
        ');
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE patients DROP CONSTRAINT IF EXISTS patients_age_known');
        DB::statement('ALTER TABLE patients DROP CONSTRAINT IF EXISTS patients_estimated_age_range');

        Schema::table('patients', function (Blueprint $table) {
            $table->dropColumn('estimated_age');
        });
    }
};
