<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('bic_blacklists', function (Blueprint $table) {
            $table->id();
            $table->string('bic', 11)->index();
            $table->boolean('is_prefix')->default(false);
            $table->string('reason')->nullable();
            $table->string('source')->default('manual');
            $table->string('auto_criteria')->nullable();
            $table->json('stats_snapshot')->nullable();
            $table->string('blacklisted_by')->nullable();
            $table->timestamps();

            $table->unique(['bic', 'is_prefix']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('bic_blacklists');
    }
};
