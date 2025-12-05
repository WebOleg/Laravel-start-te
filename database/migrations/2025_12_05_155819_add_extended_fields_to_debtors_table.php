<?php

/**
 * Adds extended fields to debtors table to match client upload format.
 * Based on client1_test1_ES.xlsx structure.
 */

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('debtors', function (Blueprint $table) {
            // National ID (DNI/NIE for Spain, etc.)
            $table->string('national_id')->nullable()->after('phone');
            
            // Extended address fields
            $table->string('street')->nullable()->after('address');
            $table->string('street_number')->nullable()->after('street');
            $table->string('floor')->nullable()->after('street_number');
            $table->string('door')->nullable()->after('floor');
            $table->string('apartment')->nullable()->after('door');
            $table->string('province')->nullable()->after('city');
            $table->string('postcode')->nullable()->after('province');
            
            // Bank details
            $table->string('bank_name')->nullable()->after('iban_hash');
            $table->string('bank_code')->nullable()->after('bank_name');
            $table->string('bic')->nullable()->after('bank_code');
            
            // Additional personal info
            $table->date('birth_date')->nullable()->after('national_id');
            $table->string('phone_2')->nullable()->after('phone');
            $table->string('phone_3')->nullable()->after('phone_2');
            $table->string('phone_4')->nullable()->after('phone_3');
            $table->string('primary_phone')->nullable()->after('phone_4');
            
            // VOP pre-validation results (from client file)
            $table->boolean('iban_valid')->nullable()->after('status');
            $table->boolean('name_matched')->nullable()->after('iban_valid');
            
            // Old IBAN for migrations
            $table->string('old_iban')->nullable()->after('iban');
            
            // SEPA type
            $table->string('sepa_type')->nullable()->after('currency');
            
            // Indexes for new fields
            $table->index('national_id');
            $table->index('bic');
            $table->index('birth_date');
        });
    }

    public function down(): void
    {
        Schema::table('debtors', function (Blueprint $table) {
            $table->dropIndex(['national_id']);
            $table->dropIndex(['bic']);
            $table->dropIndex(['birth_date']);
            
            $table->dropColumn([
                'national_id',
                'street',
                'street_number',
                'floor',
                'door',
                'apartment',
                'province',
                'postcode',
                'bank_name',
                'bank_code',
                'bic',
                'birth_date',
                'phone_2',
                'phone_3',
                'phone_4',
                'primary_phone',
                'iban_valid',
                'name_matched',
                'old_iban',
                'sepa_type',
            ]);
        });
    }
};
