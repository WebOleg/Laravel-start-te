<?php

/**
 * Creates vop_logs table for storing VOP (Verify Ownership Process) verification results.
 */

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('vop_logs', function (Blueprint $table) {
            $table->id();
            
            // Relations
            $table->foreignId('debtor_id')
                ->constrained('debtors')
                ->cascadeOnDelete();
            $table->foreignId('upload_id')
                ->constrained('uploads')
                ->cascadeOnDelete();
            
            // IBAN verification
            $table->string('iban_masked');
            $table->boolean('iban_valid')->default(false);
            
            // Bank identification
            $table->boolean('bank_identified')->default(false);
            $table->string('bank_name')->nullable();
            $table->string('bic')->nullable();
            $table->string('country', 2)->nullable();
            
            // VOP scoring
            $table->unsignedTinyInteger('vop_score')->default(0);
            
            // Result: verified, likely_verified, inconclusive, mismatch, rejected
            $table->string('result')->default('pending');
            
            // Flexible data for extra flags
            $table->jsonb('meta')->nullable();
            
            $table->timestamps();
            
            // Indexes
            $table->index('debtor_id');
            $table->index('upload_id');
            $table->index('result');
            $table->index('vop_score');
            $table->index(['upload_id', 'result']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('vop_logs');
    }
};
