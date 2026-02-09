<?php
/**
 * Migration to create bav_credits table for tracking BAV API credits.
 */
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('bav_credits', function (Blueprint $table) {
            $table->id();
            $table->integer('credits_total')->default(2500);
            $table->integer('credits_used')->default(0);
            $table->timestamp('expires_at')->nullable();
            $table->timestamp('last_refill_at')->nullable();
            $table->string('last_updated_by')->nullable();
            $table->timestamps();
        });

        // Insert initial record with current balance
        DB::table('bav_credits')->insert([
            'credits_total' => 2500,
            'credits_used' => 2420,
            'expires_at' => '2026-12-15 00:00:00',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function down(): void
    {
        Schema::dropIfExists('bav_credits');
    }
};
