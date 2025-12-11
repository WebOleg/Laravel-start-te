<?php

/**
 * Adds validation fields to debtors table for record-level validation.
 * Enables MeLinux-style validation flow where records are validated on detail page before sync.
 */

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('debtors', function (Blueprint $table) {
            // Validation status: pending (not validated), valid, invalid
            $table->string('validation_status')->default('pending')->after('status');
            
            // Array of validation error messages
            $table->jsonb('validation_errors')->nullable()->after('validation_status');
            
            // Timestamp when validation was last performed
            $table->timestamp('validated_at')->nullable()->after('validation_errors');
            
            // Indexes
            $table->index('validation_status');
            $table->index(['upload_id', 'validation_status']);
        });
    }

    public function down(): void
    {
        Schema::table('debtors', function (Blueprint $table) {
            $table->dropIndex(['debtors_validation_status_index']);
            $table->dropIndex(['debtors_upload_id_validation_status_index']);
            
            $table->dropColumn([
                'validation_status',
                'validation_errors',
                'validated_at',
            ]);
        });
    }
};
