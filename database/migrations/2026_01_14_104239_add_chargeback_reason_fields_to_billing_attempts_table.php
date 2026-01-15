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
        Schema::table('billing_attempts', function (Blueprint $table) {
            $table->string('chargeback_reason_code')->nullable()->after('status');
            $table->text('chargeback_reason_description')->nullable()->after('chargeback_reason_code');
            $table->timestamp('chargebacked_at')->nullable()->after('chargeback_reason_description');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('billing_attempts', function (Blueprint $table) {
            $table->dropColumn('chargeback_reason_code');
            $table->dropColumn('chargeback_reason_description');
            $table->dropColumn('chargebacked_at');
        });
    }
};
