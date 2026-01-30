<?php

/**
 * Add emp_account_id to uploads table.
 * Links each upload to the EMP account used for billing.
 */

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('uploads', function (Blueprint $table) {
            $table->foreignId('emp_account_id')
                ->nullable()
                ->after('billing_model')
                ->constrained('emp_accounts')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('uploads', function (Blueprint $table) {
            $table->dropForeign(['emp_account_id']);
            $table->dropColumn('emp_account_id');
        });
    }
};
