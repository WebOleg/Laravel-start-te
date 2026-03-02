<?php

/**
 * Add tether_instance_id to debtors table.
 * Composite indexes for tenant-scoped queries.
 */

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('debtors', function (Blueprint $table) {
            $table->foreignId('tether_instance_id')->nullable()->after('id')->constrained('tether_instances')->nullOnDelete();
            $table->index(['tether_instance_id', 'status']);
            $table->index(['tether_instance_id', 'iban_hash']);
        });
    }

    public function down(): void
    {
        Schema::table('debtors', function (Blueprint $table) {
            $table->dropIndex(['tether_instance_id', 'status']);
            $table->dropIndex(['tether_instance_id', 'iban_hash']);
            $table->dropConstrainedForeignId('tether_instance_id');
        });
    }
};
