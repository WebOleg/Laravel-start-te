<?php

/**
 * Migration to create bav_batches table for standalone BAV verification batches.
 */

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('bav_batches', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->comment('Who initiated the batch');
            $table->string('original_filename');
            $table->string('file_path')->comment('Input CSV path in S3/MinIO');
            $table->string('results_path')->nullable()->comment('Output CSV path in S3/MinIO');
            $table->string('status')->default('pending')->index();
            $table->integer('total_records')->default(0);
            $table->integer('processed_records')->default(0);
            $table->integer('success_count')->default(0);
            $table->integer('failed_count')->default(0);
            $table->integer('credits_used')->default(0);
            $table->string('batch_id')->nullable()->comment('Laravel Bus batch ID');
            $table->json('column_mapping')->nullable()->comment('Auto-detected column positions');
            $table->json('meta')->nullable()->comment('Errors, stats, etc');
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('bav_batches');
    }
};
