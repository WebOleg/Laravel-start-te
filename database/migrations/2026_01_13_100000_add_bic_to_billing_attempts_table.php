<?php

/**
 * Adds BIC column to billing_attempts for bank-level analytics.
 * Denormalized from debtors.bic for query performance.
 */

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('billing_attempts', function (Blueprint $table) {
            $table->string('bic', 11)->nullable()->after('mid_reference');
            
            $table->index(['bic', 'created_at'], 'idx_billing_bic_created');
            $table->index(['bic', 'status'], 'idx_billing_bic_status');
        });
    }

    public function down(): void
    {
        Schema::table('billing_attempts', function (Blueprint $table) {
            $table->dropIndex('idx_billing_bic_created');
            $table->dropIndex('idx_billing_bic_status');
            $table->dropColumn('bic');
        });
    }
};
