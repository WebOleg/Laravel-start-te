<?php

/**
 * Add tether_instance_id to debtor_profiles table.
 * Update unique constraint: iban_hash scoped per tether_instance.
 */

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('debtor_profiles', function (Blueprint $table) {
            $table->foreignId('tether_instance_id')->nullable()->after('id')->constrained('tether_instances')->nullOnDelete();
        });

        Schema::table('debtor_profiles', function (Blueprint $table) {
            $table->dropUnique('debtor_profiles_iban_hash_unique');
            $table->unique(['iban_hash', 'tether_instance_id']);
        });
    }

    public function down(): void
    {
        Schema::table('debtor_profiles', function (Blueprint $table) {
            $table->dropUnique(['iban_hash', 'tether_instance_id']);
            $table->unique('iban_hash');
            $table->dropConstrainedForeignId('tether_instance_id');
        });
    }
};
