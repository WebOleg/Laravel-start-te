<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('vop_logs', function (Blueprint $table) {
            $table->index(['debtor_id', 'name_match'], 'vop_logs_debtor_name_match_idx');
        });
    }

    public function down(): void
    {
        Schema::table('vop_logs', function (Blueprint $table) {
            $table->dropIndex('vop_logs_debtor_name_match_idx');
        });
    }
};
