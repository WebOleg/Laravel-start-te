<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('billing_attempts', function (Blueprint $table) {
            $table->foreignId('debtor_profile_id')
                ->nullable()
                ->after('debtor_id')
                ->constrained('debtor_profiles')
                ->nullOnDelete();

            // Snapshot at attempt time
            $table->string('billing_model', 20)->nullable()->after('status');

            // Used for idempotency: one bill per cycle window per profile+model
            $table->date('cycle_anchor')->nullable()->after('billing_model');

            // manual|automation
            $table->string('source', 20)->default('manual')->after('cycle_anchor');

            $table->index(['debtor_profile_id', 'status']);
            $table->index(['billing_model', 'cycle_anchor']);
        });

        Schema::table('billing_attempts', function (Blueprint $table) {
            $table->unique(
                ['debtor_profile_id', 'billing_model', 'cycle_anchor'],
                'uniq_billing_attempts_profile_model_cycle'
            );
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('billing_attempts', function (Blueprint $table) {
            $table->dropUnique('uniq_billing_attempts_profile_model_cycle');

            $table->dropIndex(['debtor_profile_id', 'status']);
            $table->dropIndex(['billing_model', 'cycle_anchor']);

            $table->dropColumn(['source', 'cycle_anchor', 'billing_model']);
            $table->dropConstrainedForeignId('debtor_profile_id');
        });
    }
};
