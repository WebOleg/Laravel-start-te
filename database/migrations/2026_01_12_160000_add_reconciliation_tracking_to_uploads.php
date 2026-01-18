<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('uploads', function (Blueprint $table) {
            $table->string('reconciliation_status')->default('idle')->after('vop_completed_at');
            $table->string('reconciliation_batch_id')->nullable()->after('reconciliation_status');
            $table->timestamp('reconciliation_started_at')->nullable()->after('reconciliation_batch_id');
            $table->timestamp('reconciliation_completed_at')->nullable()->after('reconciliation_started_at');
            
            $table->index('reconciliation_status');
        });
    }

    public function down(): void
    {
        Schema::table('uploads', function (Blueprint $table) {
            $table->dropIndex(['reconciliation_status']);
            
            $table->dropColumn([
                'reconciliation_status',
                'reconciliation_batch_id',
                'reconciliation_started_at',
                'reconciliation_completed_at',
            ]);
        });
    }
};
