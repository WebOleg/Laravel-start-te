<?php

use App\Enums\BillingModel;
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
        Schema::create('debtor_profiles', function (Blueprint $table) {
            $table->id();

            // Primary key rule: IBAN (store as hash for privacy/consistency)
            $table->string('iban_hash', 64)->unique();
            $table->string('iban_masked')->nullable();

            // legacy|flywheel|recovery
            $table->string('billing_model', 20)->default(BillingModel::Legacy->value);
            $table->boolean('is_active')->default(true);

            $table->decimal('billing_amount', 12, 2)->nullable();

            $table->decimal('lifetime_charged_amount', 12, 2)->default(0);
            $table->string('currency', 3)->default('EUR');

            // Automation anchors
            $table->timestamp('last_billed_at')->nullable();
            $table->timestamp('last_success_at')->nullable();
            $table->timestamp('next_bill_at')->nullable();

            $table->timestamps();

            $table->index(['billing_model', 'is_active']);
            $table->index('next_bill_at');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('debtor_profiles');
    }
};
