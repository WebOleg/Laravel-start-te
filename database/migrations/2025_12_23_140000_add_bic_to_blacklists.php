<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('blacklists', function (Blueprint $table) {
            $table->string('bic')->nullable()->after('email');
            $table->index('bic');
        });
    }

    public function down(): void
    {
        Schema::table('blacklists', function (Blueprint $table) {
            $table->dropIndex(['bic']);
            $table->dropColumn('bic');
        });
    }
};
