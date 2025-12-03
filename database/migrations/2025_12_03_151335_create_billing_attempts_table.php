<?php

/**
 * Creates billing_attempts table for storing SEPA Direct Debit transaction attempts.
 */

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('billing_attempts', function (Blueprint $table) {
            $table->id();
            
            // Relations
            $table->foreignId('debtor_id')
                ->constrained('debtors')
                ->cascadeOnDelete();
            $table->foreignId('upload_id')
                ->constrained('uploads')
                ->cascadeOnDelete();
            
            // Transaction identifiers
            $table->string('transaction_id')->unique();
            $table->string('unique_id')->nullable();
            
            // Financial
            $table->decimal('amount', 12, 2);
            $table->string('currency', 3)->default('EUR');
            
            // Status: pending, approved, declined, error, voided
            $table->string('status')->default('pending');
            
            // Attempt tracking
            $table->unsignedTinyInteger('attempt_number')->default(1);
            
            // MID (Merchant ID) for future multi-MID routing
            $table->string('mid_reference')->nullable();
            
            // Response from processor
            $table->string('error_code')->nullable();
            $table->text('error_message')->nullable();
            $table->text('technical_message')->nullable();
            
            // Flexible data
            $table->jsonb('request_payload')->nullable();
            $table->jsonb('response_payload')->nullable();
            $table->jsonb('meta')->nullable();
            
            // Timing
            $table->timestamp('processed_at')->nullable();
            $table->timestamps();
            
            // Indexes
            $table->index('debtor_id');
            $table->index('upload_id');
            $table->index('status');
            $table->index('unique_id');
            $table->index(['debtor_id', 'status']);
            $table->index(['upload_id', 'status']);
            $table->index('processed_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('billing_attempts');
    }
};
