<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('billing_attempts', function (Blueprint $table) {
            $table->timestamp('last_reconciled_at')->nullable()->after('processed_at');
            $table->unsignedTinyInteger('reconciliation_attempts')->default(0)->after('last_reconciled_at');
        });

        // Index for efficient reconciliation queries
        Schema::table('billing_attempts', function (Blueprint $table) {
            $table->index(['status', 'created_at', 'last_reconciled_at'], 'idx_reconciliation');
        });
    }

    public function down(): void
    {
        Schema::table('billing_attempts', function (Blueprint $table) {
            $table->dropIndex('idx_reconciliation');
            $table->dropColumn(['last_reconciled_at', 'reconciliation_attempts']);
        });
    }
};
