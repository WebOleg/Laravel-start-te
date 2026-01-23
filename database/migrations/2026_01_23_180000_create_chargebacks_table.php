<?php

/**
 * Migration for chargebacks table.
 * Stores EMP chargeback events separately from billing_attempts.
 * For SDD: one chargeback per transaction (unique by original_transaction_unique_id).
 */

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('chargebacks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('billing_attempt_id')->constrained()->onDelete('cascade');
            $table->foreignId('debtor_id')->constrained()->onDelete('cascade');
            $table->string('original_transaction_unique_id', 64)->unique();
            $table->string('type', 50)->nullable();
            $table->string('reason_code', 20)->nullable()->index();
            $table->string('reason_description', 500)->nullable();
            $table->decimal('chargeback_amount', 12, 2)->nullable();
            $table->string('chargeback_currency', 3)->default('EUR');
            $table->string('arn', 50)->nullable()->index();
            $table->date('post_date')->nullable()->index();
            $table->date('import_date')->nullable();
            $table->string('source', 20)->index();
            $table->json('api_response')->nullable();
            $table->timestamps();

            $table->index(['billing_attempt_id', 'created_at']);
            $table->index(['debtor_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('chargebacks');
    }
};
