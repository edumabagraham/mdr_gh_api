<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Slice 1 needs two roles and no more. The check constraint is deliberate:
     * a typo in a role string would otherwise create a silent third role that
     * every authorisation check quietly fails closed against.
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('role', 20)->default('clinician')->after('email');
        });

        DB::statement("
            ALTER TABLE users ADD CONSTRAINT users_role_check
            CHECK (role IN ('clinician','data_manager'))
        ");
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE users DROP CONSTRAINT IF EXISTS users_role_check');

        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('role');
        });
    }
};
