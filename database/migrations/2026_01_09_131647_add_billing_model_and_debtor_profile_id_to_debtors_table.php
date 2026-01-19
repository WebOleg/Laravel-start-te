<?php

use App\Enums\BillingModel;
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
        Schema::table('debtors', function (Blueprint $table) {
            // Snapshot of model used for this row (still useful for UI/debugging)
            $table->string('billing_model', 20)->default(BillingModel::Legacy->value)->after('validation_status');

            // IBAN-level profile (enforces "cannot be both flywheel & recovery")
            $table->foreignId('debtor_profile_id')
                ->nullable()
                ->after('iban_hash')
                ->constrained('debtor_profiles')
                ->nullOnDelete();

            $table->index(['billing_model', 'status']);
            $table->index('debtor_profile_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('debtors', function (Blueprint $table) {
            $table->dropIndex(['billing_model', 'status']);
            $table->dropIndex(['debtor_profile_id']);

            $table->dropConstrainedForeignId('debtor_profile_id');
            $table->dropColumn('billing_model');
        });
    }
};
