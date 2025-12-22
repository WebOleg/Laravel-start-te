<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('blacklists', function (Blueprint $table) {
            $table->string('first_name')->nullable()->after('iban_hash');
            $table->string('last_name')->nullable()->after('first_name');
            $table->string('email')->nullable()->after('last_name');
            
            // Index for name-based lookups
            $table->index(['first_name', 'last_name']);
            $table->index('email');
        });
    }

    public function down(): void
    {
        Schema::table('blacklists', function (Blueprint $table) {
            $table->dropIndex(['first_name', 'last_name']);
            $table->dropIndex(['email']);
            $table->dropColumn(['first_name', 'last_name', 'email']);
        });
    }
};
