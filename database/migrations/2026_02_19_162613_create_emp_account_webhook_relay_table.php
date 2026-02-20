<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('emp_account_webhook_relay', function (Blueprint $table) {
            $table->id();
            $table->foreignId('emp_account_id')->constrained()->cascadeOnDelete();
            $table->foreignId('webhook_relay_id')->constrained()->cascadeOnDelete();
            $table->timestamps();

            // Ensure the same account isn't attached to the same relay twice
            $table->unique(['emp_account_id', 'webhook_relay_id'], 'emp_relay_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('emp_account_webhook_relay');
    }
};
