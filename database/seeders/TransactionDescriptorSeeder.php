<?php

namespace Database\Seeders;

use App\Models\EmpAccount;
use App\Models\TransactionDescriptor;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class TransactionDescriptorSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        // Get active EMP accounts
        $empAccounts = EmpAccount::where('is_active', true)->get();

        if ($empAccounts->isEmpty()) {
            // Create a default account if none exist
            $empAccounts = EmpAccount::factory(1)->active()->create();
        }

        $descriptors = [
            [
                'year'               => 2025,
                'month'              => 1,
                'descriptor_name'    => 'LUNARO SERVICES',
                'descriptor_city'    => 'NEW YORK',
                'descriptor_country' => 'USA',
                'is_default'         => false,
                'emp_account_id'     => $empAccounts->first()->id,
            ],
            [
                'year'               => 2025,
                'month'              => 2,
                'descriptor_name'    => 'LUNARO SERV',
                'descriptor_city'    => 'LONDON',
                'descriptor_country' => 'GBR',
                'is_default'         => false,
                'emp_account_id'     => $empAccounts->first()->id,
            ],
            [
                'year'               => 2025,
                'month'              => 3,
                'descriptor_name'    => 'LUNARO TECH',
                'descriptor_city'    => 'DUBLIN',
                'descriptor_country' => 'IRL',
                'is_default'         => false,
                'emp_account_id'     => $empAccounts->first()->id,
            ],
            [
                'year'               => null,
                'month'              => null,
                'descriptor_name'    => 'LUNARO PAYMENT',
                'descriptor_city'    => null,
                'descriptor_country' => null,
                'is_default'         => true,
                'emp_account_id'     => $empAccounts->first()->id,
            ],
        ];

        foreach ($descriptors as $descriptor) {
            TransactionDescriptor::updateOrCreate(
                [
                    'year'  => $descriptor['year'],
                    'month' => $descriptor['month'],
                ],
                $descriptor
            );
        }

        // Create additional random entries for testing with unique year/month combinations
        $usedCombinations = [
            [2025, 1],
            [2025, 2],
            [2025, 3],
            [null, null],
        ];

        for ($i = 0; $i < 5; $i++) {
            do {
                $year = rand(2026, 2030);
                $month = rand(1, 12);
                $combination = [$year, $month];
            } while (in_array($combination, $usedCombinations));

            TransactionDescriptor::factory()
                ->create([
                    'year'           => $year,
                    'month'          => $month,
                    'emp_account_id' => $empAccounts->random()->id,
                ]);

            $usedCombinations[] = $combination;
        }
    }
}
