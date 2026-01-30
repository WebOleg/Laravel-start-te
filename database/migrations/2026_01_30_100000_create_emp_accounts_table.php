<?php

/**
 * Migration for EMP merchant accounts management.
 * Stores credentials for multiple emerchantpay terminals.
 */

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('emp_accounts', function (Blueprint $table) {
            $table->id();
            $table->string('name', 100);
            $table->string('slug', 50)->unique();
            $table->string('endpoint', 255)->default('gate.emerchantpay.net');
            $table->text('username');
            $table->text('password');
            $table->text('terminal_token');
            $table->boolean('is_active')->default(false);
            $table->unsignedInteger('sort_order')->default(0);
            $table->text('notes')->nullable();
            $table->timestamps();
            
            $table->index('is_active');
            $table->index('sort_order');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('emp_accounts');
    }
};
