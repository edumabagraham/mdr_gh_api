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
        // users — access control, professional credential, lifecycle
        //
        // User rows are NEVER deleted. Every assessment, registration and audit
        // entry attributes to a user, and a deleted row turns that attribution
        // into null — which in a clinical registry means no longer being able to
        // say who recorded a value. Departure is a status change.
        // ------------------------------------------------------------------
        Schema::table('users', function (Blueprint $table) {
            $table->string('role', 24)->default('clinician')->after('email');
            $table->string('status', 16)->default('active')->after('role');

            // Professional attributes. Specialty is an attribute, NOT a role:
            // registrars and house officers need access too, and restricting by
            // specialty leads to password sharing, which destroys the audit trail.
            $table->string('specialty', 80)->nullable()->after('status');
            $table->string('grade', 60)->nullable()->after('specialty');
            $table->string('department', 80)->nullable()->after('grade');

            // Medical and Dental Council of Ghana registration. Cannot be verified
            // programmatically; sighted by the admin at onboarding. Recorded so there
            // is an auditable answer to "on what basis did this person have access?"
            $table->string('mdc_number', 40)->nullable()->after('department');
            $table->date('mdc_expires_on')->nullable()->after('mdc_number');
            $table->date('credential_sighted_on')->nullable()->after('mdc_expires_on');
            $table->foreignId('credential_sighted_by')->nullable()->after('credential_sighted_on')
                ->constrained('users')->nullOnDelete();

            $table->timestampTz('last_login_at')->nullable();
            $table->timestampTz('last_active_at')->nullable();

            $table->foreignId('invited_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampTz('invited_at')->nullable();

            $table->timestampTz('deactivated_at')->nullable();
            $table->foreignId('deactivated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('deactivation_reason', 255)->nullable();

            $table->index(['status', 'last_login_at']);
            $table->index('role');
        });

        DB::statement("
            ALTER TABLE users ADD CONSTRAINT users_role_check
            CHECK (role IN ('admin','clinician','research_assistant','data_manager'))
        ");

        DB::statement("
            ALTER TABLE users ADD CONSTRAINT users_status_check
            CHECK (status IN ('active','suspended','departed'))
        ");

        // A non-active user must carry the reason and timestamp; an active one must not.
        DB::statement("
            ALTER TABLE users ADD CONSTRAINT users_deactivation_consistency
            CHECK (
                (status = 'active' AND deactivated_at IS NULL)
                OR (status <> 'active' AND deactivated_at IS NOT NULL)
            )
        ");

        // ------------------------------------------------------------------
        // invitations — the only route to an account
        // ------------------------------------------------------------------
        Schema::create('invitations', function (Blueprint $table) {
            $table->id();
            $table->string('email');
            $table->string('role', 24);
            $table->string('token_hash', 64)->unique();   // sha256 of the emailed token
            $table->foreignId('invited_by')->constrained('users')->restrictOnDelete();
            $table->timestampTz('expires_at');
            $table->timestampTz('accepted_at')->nullable();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->timestampTz('revoked_at')->nullable();
            $table->foreignId('revoked_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['email', 'accepted_at']);
        });

        DB::statement("
            ALTER TABLE invitations ADD CONSTRAINT invitations_role_check
            CHECK (role IN ('admin','clinician','research_assistant','data_manager'))
        ");

        // Only one live invitation per email address.
        DB::statement('
            CREATE UNIQUE INDEX invitations_one_live_per_email
            ON invitations (lower(email))
            WHERE accepted_at IS NULL AND revoked_at IS NULL
        ');

        // ------------------------------------------------------------------
        // audit_events — append-only
        //
        // Covers administrative actions in this slice and patient-record access
        // in the next. No update or delete path is ever written for this table.
        // ------------------------------------------------------------------
        Schema::create('audit_events', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->foreignId('actor_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('action', 60);            // user.invited, user.deactivated, patient.viewed
            $table->string('subject_type', 60)->nullable();
            $table->unsignedBigInteger('subject_id')->nullable();
            $table->jsonb('context')->nullable();
            $table->string('ip_address', 45)->nullable();
            $table->string('user_agent', 255)->nullable();
            $table->timestampTz('created_at');

            $table->index(['action', 'created_at']);
            $table->index(['subject_type', 'subject_id']);
            $table->index(['actor_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('audit_events');
        Schema::dropIfExists('invitations');

        DB::statement('ALTER TABLE users DROP CONSTRAINT IF EXISTS users_role_check');
        DB::statement('ALTER TABLE users DROP CONSTRAINT IF EXISTS users_status_check');
        DB::statement('ALTER TABLE users DROP CONSTRAINT IF EXISTS users_deactivation_consistency');

        Schema::table('users', function (Blueprint $table) {
            $table->dropConstrainedForeignId('credential_sighted_by');
            $table->dropConstrainedForeignId('invited_by');
            $table->dropConstrainedForeignId('deactivated_by');
            $table->dropColumn([
                'role', 'status', 'specialty', 'grade', 'department',
                'mdc_number', 'mdc_expires_on', 'credential_sighted_on',
                'last_login_at', 'last_active_at', 'invited_at',
                'deactivated_at', 'deactivation_reason',
            ]);
        });
    }
};
