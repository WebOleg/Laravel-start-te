<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Replace the unconditional unique constraint on billing_attempts with a
     * partial unique index that only enforces uniqueness for live (non-terminal)
     * statuses: pending and approved.
     *
     * This allows resync to insert new attempts for a debtor on the same
     * cycle_anchor date when the previous attempt is in a terminal state
     * (failed, declined, chargebacked) — while still preventing true
     * double-billing for in-flight or approved attempts.
     */
    public function up(): void
    {
        // Drop the unconditional unique constraint added in the original migration
        Schema::table('billing_attempts', function (Blueprint $table) {
            $table->dropUnique('uniq_billing_attempts_profile_model_cycle');
        });

        // Re-create as a PostgreSQL partial unique index scoped to live statuses only
        DB::statement("
            CREATE UNIQUE INDEX uniq_billing_attempts_profile_model_cycle
            ON billing_attempts (debtor_profile_id, billing_model, cycle_anchor)
            WHERE status IN ('pending', 'approved')
        ");
    }

    public function down(): void
    {
        DB::statement("DROP INDEX IF EXISTS uniq_billing_attempts_profile_model_cycle");

        Schema::table('billing_attempts', function (Blueprint $table) {
            $table->unique(
                ['debtor_profile_id', 'billing_model', 'cycle_anchor'],
                'uniq_billing_attempts_profile_model_cycle'
            );
        });
    }
};
