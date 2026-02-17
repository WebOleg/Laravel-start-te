<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

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
            
            // Drop old unique constraint
            $table->dropUnique(['year', 'month']);
        });

        // Regular unique constraint for account-specific descriptors
        Schema::table('transaction_descriptors', function (Blueprint $table) {
            $table->unique(['year', 'month', 'emp_account_id']);
        });

        // Partial unique index for global descriptors (where emp_account_id IS NULL)
        // PostgreSQL allows multiple NULLs in unique constraints, so we need this
        DB::statement('
            CREATE UNIQUE INDEX transaction_descriptors_global_unique
            ON transaction_descriptors (year, month)
            WHERE emp_account_id IS NULL
        ');

        // Partial unique index for global default descriptor
        DB::statement('
            CREATE UNIQUE INDEX transaction_descriptors_global_default_unique
            ON transaction_descriptors (is_default)
            WHERE emp_account_id IS NULL AND is_default = true
        ');

        // Partial unique index for account-specific default descriptor
        DB::statement('
            CREATE UNIQUE INDEX transaction_descriptors_account_default_unique
            ON transaction_descriptors (emp_account_id, is_default)
            WHERE is_default = true
        ');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Drop partial unique indexes
        DB::statement('DROP INDEX IF EXISTS transaction_descriptors_account_default_unique');
        DB::statement('DROP INDEX IF EXISTS transaction_descriptors_global_default_unique');
        DB::statement('DROP INDEX IF EXISTS transaction_descriptors_global_unique');

        Schema::table('transaction_descriptors', function (Blueprint $table) {
            $table->dropUnique(['year', 'month', 'emp_account_id']);
            $table->dropForeign(['emp_account_id']);
            $table->dropColumn('emp_account_id');
            $table->unique(['year', 'month']);
        });
    }
};
