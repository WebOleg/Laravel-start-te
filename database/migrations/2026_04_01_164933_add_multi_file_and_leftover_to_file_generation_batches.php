<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('file_generation_batches', function (Blueprint $table) {
            $table->unsignedSmallInteger('file_count')->default(1)->after('total_input_rows');
            $table->json('file_configs')->nullable()->after('file_count');
            $table->json('s3_result_files')->nullable()->after('s3_path_result');
            $table->string('s3_path_leftover')->nullable()->after('s3_result_files');
            $table->unsignedInteger('leftover_rows')->default(0)->after('s3_path_leftover');
        });
    }

    public function down(): void
    {
        Schema::table('file_generation_batches', function (Blueprint $table) {
            $table->dropColumn(['file_count', 'file_configs', 's3_result_files', 's3_path_leftover', 'leftover_rows']);
        });
    }
};
