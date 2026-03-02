<?php

/**
 * Add tether_instance_id to uploads and billing_attempts tables.
 * Keeps emp_account_id for backward compatibility.
 */

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('uploads', function (Blueprint $table) {
            $table->foreignId('tether_instance_id')->nullable()->after('id')->constrained('tether_instances')->nullOnDelete();
            $table->index(['tether_instance_id', 'status']);
        });

        Schema::table('billing_attempts', function (Blueprint $table) {
            $table->foreignId('tether_instance_id')->nullable()->after('id')->constrained('tether_instances')->nullOnDelete();
            $table->index(['tether_instance_id', 'status']);
            $table->index(['tether_instance_id', 'emp_created_at']);
        });
    }

    public function down(): void
    {
        Schema::table('billing_attempts', function (Blueprint $table) {
            $table->dropIndex(['tether_instance_id', 'emp_created_at']);
            $table->dropIndex(['tether_instance_id', 'status']);
            $table->dropConstrainedForeignId('tether_instance_id');
        });

        Schema::table('uploads', function (Blueprint $table) {
            $table->dropIndex(['tether_instance_id', 'status']);
            $table->dropConstrainedForeignId('tether_instance_id');
        });
    }
};
