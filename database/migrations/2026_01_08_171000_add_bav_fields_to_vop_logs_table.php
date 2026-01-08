<?php
/**
 * Adds BAV (Bank Account Verification) fields to vop_logs table.
 * Stores name matching results from iban.com BAV API.
 */
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('vop_logs', function (Blueprint $table) {
            $table->string('name_match', 20)->nullable()->after('result');
            $table->unsignedTinyInteger('name_match_score')->nullable()->after('name_match');
            $table->boolean('bav_verified')->default(false)->after('name_match_score');

            $table->index('name_match');
            $table->index('bav_verified');
        });
    }

    public function down(): void
    {
        Schema::table('vop_logs', function (Blueprint $table) {
            $table->dropIndex(['vop_logs_name_match_index']);
            $table->dropIndex(['vop_logs_bav_verified_index']);
            
            $table->dropColumn([
                'name_match',
                'name_match_score',
                'bav_verified',
            ]);
        });
    }
};
