<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // Get debtor IDs that have billing attempts
        $debtorsWithBilling = DB::table('billing_attempts')
            ->whereNotNull('debtor_id')
            ->pluck('debtor_id')
            ->unique()
            ->toArray();

        // Update pending debtors without billing attempts to uploaded
        $query = DB::table('debtors')
            ->where('status', 'pending')
            ->whereNull('deleted_at');

        if (!empty($debtorsWithBilling)) {
            $query->whereNotIn('id', $debtorsWithBilling);
        }

        $query->update([
            'status' => 'uploaded',
            'updated_at' => now(),
        ]);
    }

    public function down(): void
    {
        // Get debtor IDs that have billing attempts
        $debtorsWithBilling = DB::table('billing_attempts')
            ->whereNotNull('debtor_id')
            ->pluck('debtor_id')
            ->unique()
            ->toArray();

        // Revert uploaded back to pending
        $query = DB::table('debtors')
            ->where('status', 'uploaded')
            ->whereNull('deleted_at');

        if (!empty($debtorsWithBilling)) {
            $query->whereNotIn('id', $debtorsWithBilling);
        }

        $query->update([
            'status' => 'pending',
            'updated_at' => now(),
        ]);
    }
};
