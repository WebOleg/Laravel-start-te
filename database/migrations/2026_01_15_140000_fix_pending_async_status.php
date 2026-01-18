<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Fix billing attempts with raw 'pending_async' status.
     * Should be 'pending' after EMP status mapping.
     */
    public function up(): void
    {
        $updated = DB::table('billing_attempts')
            ->where('status', 'pending_async')
            ->update(['status' => 'pending']);

        if ($updated > 0) {
            logger()->info("Fixed {$updated} billing attempts from pending_async to pending");
        }
    }

    /**
     * Reverse is not needed - pending_async was a bug, not intended state.
     */
    public function down(): void
    {
        // No rollback - pending_async was incorrect
    }
};
