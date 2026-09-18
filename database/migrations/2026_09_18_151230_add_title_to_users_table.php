<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * A column rather than part of `name`.
     *
     * Titles change — a registrar becomes a consultant, a candidate becomes a
     * doctor — and a title baked into the name string cannot be updated without
     * re-parsing free text. It also has to be droppable for correspondence that
     * needs the plain name.
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('title', 20)->nullable()->after('name');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('title');
        });
    }
};
