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
        Schema::table('transaction_descriptors', function (Blueprint $table) {
            $table->foreignId('emp_account_id')
                  ->nullable()
                  ->constrained('emp_accounts')
                  ->nullOnDelete()
                  ->after('id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('transaction_descriptors', function (Blueprint $table) {
            $table->dropColumn('emp_account_id');
        });
    }
};
