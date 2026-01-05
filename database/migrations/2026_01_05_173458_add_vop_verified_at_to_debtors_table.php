<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('debtors', function (Blueprint $table) {
            $table->timestamp('vop_verified_at')->nullable()->after('validated_at');
        });
    }

    public function down(): void
    {
        Schema::table('debtors', function (Blueprint $table) {
            $table->dropColumn('vop_verified_at');
        });
    }
};
