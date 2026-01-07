<?php

/**
 * Adds EMP-specific fields for inbound sync (EMP Refresh feature).
 * - emp_created_at: timestamp from EMP gateway
 * - unique_id: add unique constraint for upsert strategy
 */

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('billing_attempts', function (Blueprint $table) {
            // EMP timestamp for when transaction was created on gateway side
            $table->timestamp('emp_created_at')->nullable()->after('processed_at');
        });

        // Add unique constraint to unique_id (separate statement for nullable unique)
        Schema::table('billing_attempts', function (Blueprint $table) {
            $table->unique('unique_id');
        });
    }

    public function down(): void
    {
        Schema::table('billing_attempts', function (Blueprint $table) {
            $table->dropUnique(['unique_id']);
            $table->dropColumn('emp_created_at');
        });
    }
};
