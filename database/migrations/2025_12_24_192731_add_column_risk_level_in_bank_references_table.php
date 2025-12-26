<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('bank_references', function (Blueprint $table) {
            $table->string('risk_level', 20)->nullable()->after('zip');
            $table->index('risk_level');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('bank_references', function (Blueprint $table) {
            $table->dropIndex(['risk_level']);
            $table->dropColumn('risk_level');
        });
    }
};
