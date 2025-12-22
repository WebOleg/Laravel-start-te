<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('bank_references', function (Blueprint $table) {
            $table->id();
            $table->string('country_iso', 2)->index();
            $table->string('bank_code', 20)->index();
            $table->string('bic', 11)->nullable()->index();
            $table->string('bank_name', 255);
            $table->string('branch', 255)->nullable();
            $table->string('address', 255)->nullable();
            $table->string('city', 128)->nullable();
            $table->string('zip', 20)->nullable();
            $table->boolean('sepa_sct')->default(false);
            $table->boolean('sepa_sdd')->default(false);
            $table->boolean('sepa_cor1')->default(false);
            $table->boolean('sepa_b2b')->default(false);
            $table->boolean('sepa_scc')->default(false);
            $table->timestamps();

            $table->unique(['country_iso', 'bank_code']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('bank_references');
    }
};
