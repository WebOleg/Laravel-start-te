<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('uploads', function (Blueprint $table) {
            $table->string('validation_status')->default('idle')->after('status');
            $table->string('validation_batch_id')->nullable()->after('validation_status');
            $table->timestamp('validation_started_at')->nullable()->after('validation_batch_id');
            $table->timestamp('validation_completed_at')->nullable()->after('validation_started_at');
            
            $table->index('validation_status');
        });
    }

    public function down(): void
    {
        Schema::table('uploads', function (Blueprint $table) {
            $table->dropIndex(['validation_status']);
            
            $table->dropColumn([
                'validation_status',
                'validation_batch_id',
                'validation_started_at',
                'validation_completed_at',
            ]);
        });
    }
};
