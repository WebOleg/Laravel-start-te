<?php

/**
 * Adds BAV (Bank Account Verification) fields to debtors table.
 * Enables name matching verification via iban.com BAV API.
 */

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('debtors', function (Blueprint $table) {
            // VOP verification status: pending, verified, error
            $table->string('vop_status', 20)
                ->default('pending')
                ->after('vop_verified_at');
            
            // Name match result from BAV API (null = not checked)
            $table->boolean('vop_match')
                ->nullable()
                ->after('vop_status');
            
            // Whether this debtor was selected for BAV verification (sampling)
            $table->boolean('bav_selected')
                ->default(false)
                ->after('vop_match');
            
            // Indexes
            $table->index('vop_status');
            $table->index('bav_selected');
            $table->index(['upload_id', 'vop_status']);
        });

        // Set existing debtors with VopLogs to 'verified'
        DB::table('debtors')
            ->whereExists(function ($query) {
                $query->select(DB::raw(1))
                    ->from('vop_logs')
                    ->whereColumn('vop_logs.debtor_id', 'debtors.id');
            })
            ->update(['vop_status' => 'verified']);
    }

    public function down(): void
    {
        Schema::table('debtors', function (Blueprint $table) {
            $table->dropIndex(['debtors_vop_status_index']);
            $table->dropIndex(['debtors_bav_selected_index']);
            $table->dropIndex(['debtors_upload_id_vop_status_index']);
            
            $table->dropColumn([
                'vop_status',
                'vop_match',
                'bav_selected',
            ]);
        });
    }
};
