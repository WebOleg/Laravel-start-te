<?php

/**
 * Global BAV verification cache.
 * Stores every successfully verified IBAN from all sources (upload page, standalone batch, artisan).
 * Used for deduplication — prevents spending credits on already-verified IBANs.
 */

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('bav_verified_ibans', function (Blueprint $table) {
            $table->id();
            $table->string('iban_hash', 64)->unique()->comment('SHA256 hash for fast lookup and dedup');
            $table->string('iban_masked', 40)->comment('Masked IBAN for display');
            $table->string('full_name')->nullable()->comment('Name used during verification');
            $table->string('name_match', 20)->nullable()->comment('yes/partial/no/unavailable');
          $table->string('bic', 11)->nullable();
            $table->unsignedSmallInteger('bav_score')->default(0);
            $table->string('bav_result', 30)->nullable()->comment('verified/likely_verified/mismatch/etc');
            $table->string('source', 30)->comment('upload_bav/standalone_batch/artisan');
            $table->unsignedBigInteger('source_id')->nullable()->comment('upload_id or bav_batch_id');
            $table->timestamp('verified_at')->nullable();
            $table->timestamps();

            $table->index('source');
            $table->index(['iban_hash', 'name_match']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('bav_verified_ibans');
    }
};
