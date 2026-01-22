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
        Schema::create('otp_codes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('code'); // Stores the bcrypt hash of the code
            $table->enum('purpose', ['login', 'recovery']);
            $table->timestamp('expires_at');
            $table->tinyInteger('attempts')->default(0);
            $table->string('user_agent')->nullable();
            $table->string('ip_address', 45)->nullable(); // IPv6 support
            $table->timestamp('created_at')->useCurrent();
            $table->timestamp('updated_at')->nullable();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('otp_codes');
    }
};
