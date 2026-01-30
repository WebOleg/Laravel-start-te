<?php

/**
 * Add emp_account_id foreign key to billing_attempts.
 * Links each billing attempt to the EMP account used for processing.
 */

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('billing_attempts', function (Blueprint $table) {
            $table->foreignId('emp_account_id')
                ->nullable()
                ->after('mid_reference')
                ->constrained('emp_accounts')
                ->nullOnDelete();
            
            $table->index('emp_account_id');
        });
    }

    public function down(): void
    {
        Schema::table('billing_attempts', function (Blueprint $table) {
            $table->dropForeign(['emp_account_id']);
            $table->dropColumn('emp_account_id');
        });
    }
};
