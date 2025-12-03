<?php

/**
 * Creates debtors table for storing debtor records imported from CSV uploads.
 */

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('debtors', function (Blueprint $table) {
            $table->id();
            
            // Relations
            $table->foreignId('upload_id')
                ->constrained('uploads')
                ->cascadeOnDelete();
            
            // IBAN data
            $table->string('iban');
            $table->string('iban_hash')->nullable();
            
            // Personal info
            $table->string('first_name');
            $table->string('last_name');
            $table->string('email')->nullable();
            $table->string('phone')->nullable();
            
            // Address
            $table->string('address')->nullable();
            $table->string('zip_code')->nullable();
            $table->string('city')->nullable();
            $table->string('country', 2)->default('DE');
            
            // Financial
            $table->decimal('amount', 12, 2);
            $table->string('currency', 3)->default('EUR');
            
            // Status & classification
            $table->string('status')->default('pending');
            $table->string('risk_class')->nullable();
            
            // External reference (client's internal ID)
            $table->string('external_reference')->nullable();
            
            // Flexible data
            $table->jsonb('meta')->nullable();
            
            $table->timestamps();
            $table->softDeletes();
            
            // Indexes
            $table->index('upload_id');
            $table->index('iban_hash');
            $table->index('status');
            $table->index('country');
            $table->index(['upload_id', 'status']);
            $table->index('external_reference');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('debtors');
    }
};
