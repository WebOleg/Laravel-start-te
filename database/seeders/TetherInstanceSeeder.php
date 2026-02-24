<?php

/**
 * Seeder for Tether Instances (multi-acquirer support).
 * One instance per acquirer gateway, not per terminal.
 */

namespace Database\Seeders;

use App\Models\TetherInstance;
use Illuminate\Database\Seeder;

class TetherInstanceSeeder extends Seeder
{
    public function run(): void
    {
        $instances = [
            [
                'name' => 'EMP (emerchantpay)',
                'slug' => 'emp',
                'acquirer_type' => 'emp',
                'is_active' => true,
                'sort_order' => 1,
                'notes' => 'Primary SEPA Direct Debit gateway via emerchantpay',
            ],
        ];

        foreach ($instances as $instance) {
            TetherInstance::updateOrCreate(
                ['slug' => $instance['slug']],
                $instance
            );
        }
    }
}
