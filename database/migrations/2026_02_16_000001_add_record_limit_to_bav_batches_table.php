<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('bav_batches', function (Blueprint $table) {
            $table->integer('record_limit')->nullable()->after('total_records');
        });
    }

    public function down(): void
    {
        Schema::table('bav_batches', function (Blueprint $table) {
            $table->dropColumn('record_limit');
        });
    }
};
