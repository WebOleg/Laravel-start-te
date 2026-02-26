<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('debtors', function (Blueprint $table) {
            $table->unsignedBigInteger('emp_account_id')->nullable()->after('upload_id');
            $table->foreign('emp_account_id')->references('id')->on('emp_accounts')->nullOnDelete();
            $table->index('emp_account_id');
        });
    }

    public function down(): void
    {
        Schema::table('debtors', function (Blueprint $table) {
            $table->dropForeign(['emp_account_id']);
            $table->dropIndex(['emp_account_id']);
            $table->dropColumn('emp_account_id');
        });
    }
};
