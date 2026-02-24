<?php

/**
 * Seeder for Tether Instances (multi-acquirer support).
 * Maps each EMP account to a TetherInstance.
 */

namespace Database\Seeders;

use App\Models\TetherInstance;
use App\Models\EmpAccount;
use Illuminate\Database\Seeder;

class TetherInstanceSeeder extends Seeder
{
    public function run(): void
    {
        $instances = [
            [
                'name' => 'Elariosso',
                'slug' => 'elariosso',
                'acquirer' => 'emp',
                'emp_account_slug' => 'elariosso',
                'is_active' => false,
            ],
            [
                'name' => 'Optivest',
                'slug' => 'optivest',
                'acquirer' => 'emp',
                'emp_account_slug' => 'optivest',
                'is_active' => false,
            ],
            [
                'name' => 'Lunaro',
                'slug' => 'lunaro',
                'acquirer' => 'emp',
                'emp_account_slug' => 'lunaro',
                'is_active' => true,
            ],
            [
                'name' => 'Corellia Ads',
                'slug' => 'corellia-ads',
                'acquirer' => 'emp',
                'emp_account_slug' => 'corellia-ads',
                'is_active' => false,
            ],
            [
                'name' => 'SmartThings Ventures',
                'slug' => 'smartthings-ventures',
                'acquirer' => 'emp',
                'emp_account_slug' => 'smartthings-ventures',
                'is_active' => false,
            ],
            [
                'name' => 'Danieli Soft',
                'slug' => 'danieli-soft',
                'acquirer' => 'emp',
                'emp_account_slug' => 'danieli-soft',
                'is_active' => false,
            ],
        ];

        foreach ($instances as $instance) {
            $empAccount = EmpAccount::where('slug', $instance['emp_account_slug'])->first();

            if (!$empAccount) {
                continue;
            }

            TetherInstance::updateOrCreate(
                ['slug' => $instance['slug']],
                [
                    'name' => $instance['name'],
                    'acquirer' => $instance['acquirer'],
                    'acquirer_account_id' => $empAccount->id,
                    'is_active' => $instance['is_active'],
                    'settings' => [],
                ]
            );
        }
    }
}
