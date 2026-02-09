<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('uploads', function (Blueprint $table) {
            $table->string('bav_status')->default('idle')->after('vop_status');
            $table->timestamp('bav_started_at')->nullable()->after('bav_status');
            $table->timestamp('bav_completed_at')->nullable()->after('bav_started_at');
            $table->integer('bav_total_count')->default(0)->after('bav_completed_at');
            $table->integer('bav_processed_count')->default(0)->after('bav_total_count');
            $table->string('bav_batch_id')->nullable()->after('bav_processed_count');
            
            $table->index('bav_status');
            $table->index('bav_batch_id');
        });
    }

    public function down(): void
    {
        Schema::table('uploads', function (Blueprint $table) {
            $table->dropIndex(['bav_status']);
            $table->dropIndex(['bav_batch_id']);
            
            $table->dropColumn([
                'bav_status',
                'bav_started_at',
                'bav_completed_at',
                'bav_total_count',
                'bav_processed_count',
                'bav_batch_id',
            ]);
        });
    }
};
