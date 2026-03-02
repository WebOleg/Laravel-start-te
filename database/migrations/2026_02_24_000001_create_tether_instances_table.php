<?php

/**
 * Create tether_instances table.
 * Acquirer-agnostic instance config replacing direct emp_account coupling.
 */

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tether_instances', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('slug')->unique();
            $table->string('acquirer_type', 50)->default('emp');
            $table->foreignId('acquirer_account_id')->nullable()->constrained('emp_accounts')->nullOnDelete();
            $table->jsonb('acquirer_config')->nullable();
            $table->string('proxy_ip', 45)->nullable();
            $table->string('queue_prefix', 100)->nullable();
            $table->boolean('is_active')->default(true);
            $table->string('status', 50)->default('active');
            $table->integer('sort_order')->default(0);
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->index(['acquirer_type', 'acquirer_account_id']);
        });

        // Seed Instance 1 pointing to current active EmpAccount
        $activeAccount = DB::table('emp_accounts')->where('is_active', true)->first();
        DB::table('tether_instances')->insert([
            'name' => $activeAccount ? $activeAccount->name : 'Default',
            'slug' => $activeAccount ? $activeAccount->slug : 'default',
            'acquirer_type' => 'emp',
            'acquirer_account_id' => $activeAccount?->id,
            'is_active' => true,
            'status' => 'active',
            'sort_order' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function down(): void
    {
        Schema::dropIfExists('tether_instances');
    }
};
