<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('uploads', function (Blueprint $table) {
            $table->string('billing_status')->default('idle')->after('status');
            $table->string('billing_batch_id')->nullable()->after('billing_status');
            $table->timestamp('billing_started_at')->nullable()->after('billing_batch_id');
            $table->timestamp('billing_completed_at')->nullable()->after('billing_started_at');
            
            $table->string('vop_status')->default('idle')->after('billing_completed_at');
            $table->string('vop_batch_id')->nullable()->after('vop_status');
            $table->timestamp('vop_started_at')->nullable()->after('vop_batch_id');
            $table->timestamp('vop_completed_at')->nullable()->after('vop_started_at');
            
            $table->index('billing_status');
            $table->index('vop_status');
        });
    }

    public function down(): void
    {
        Schema::table('uploads', function (Blueprint $table) {
            $table->dropIndex(['billing_status']);
            $table->dropIndex(['vop_status']);
            
            $table->dropColumn([
                'billing_status',
                'billing_batch_id',
                'billing_started_at',
                'billing_completed_at',
                'vop_status',
                'vop_batch_id',
                'vop_started_at',
                'vop_completed_at',
            ]);
        });
    }
};
