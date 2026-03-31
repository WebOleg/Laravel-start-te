<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('file_generation_batches', function (Blueprint $table) {
            $table->string('pricing_strategy')->default('high_bias')->after('tolerance');
        });
    }

    public function down(): void
    {
        Schema::table('file_generation_batches', function (Blueprint $table) {
            $table->dropColumn('pricing_strategy');
        });
    }
};
