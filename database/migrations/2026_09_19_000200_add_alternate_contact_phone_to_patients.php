<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The one column the alternate contact needs and does not have.
 *
 * Spec section 3.5 asks registration to collect an alternate contact — name,
 * relationship and phone — and notes the columns already exist. Two of the
 * three do: contact_name and contact_relationship. There is no number to ring.
 *
 * phone_alt is the patient's own second line, listed separately in the same
 * table, so it cannot stand in for this one. A contact with a name and a
 * relationship but no number is a note, not a way of reaching anybody, and
 * tracing is the entire reason the field is being collected.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('patients', function (Blueprint $table) {
            $table->string('contact_phone', 24)->nullable()->after('contact_relationship');
        });
    }

    public function down(): void
    {
        Schema::table('patients', function (Blueprint $table) {
            $table->dropColumn('contact_phone');
        });
    }
};
