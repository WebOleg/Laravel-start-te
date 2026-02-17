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
            
            // Drop old unique constraint and create new one with emp_account_id
            $table->dropUnique(['year', 'month']);
            $table->unique(['year', 'month', 'emp_account_id']);
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
