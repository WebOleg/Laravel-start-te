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
        Schema::table('uploads', function (Blueprint $table) {
            // legacy|flywheel|recovery
            $table->string('billing_model', 20)->default(BillingModel::Legacy->value)->after('status');
            $table->index('billing_model');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('uploads', function (Blueprint $table) {
            $table->dropIndex(['billing_model']);
            $table->dropColumn('billing_model');
        });
    }
};
