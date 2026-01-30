<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('transaction_descriptors', function (Blueprint $table) {
            $table->id();

            // Scheduling columns (Nullable because 'Default' rows won't have dates)
            $table->unsignedSmallInteger('year')->nullable();
            $table->unsignedTinyInteger('month')->nullable();

            // Descriptor Data
            $table->string('descriptor_name', 25); // strict max 25 chars
            $table->string('descriptor_city', 13)->nullable();
            $table->string('descriptor_country', 3)->nullable(); // ISO 3166-1 alpha-3

            // Status flag
            $table->boolean('is_default')->default(false);

            $table->timestamps();

            $table->unique(['year', 'month']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('transaction_descriptors');
    }
};
