<?php

namespace Database\Seeders;

use App\Models\BankReference;
use App\Models\VopLog;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class BankReferenceSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $vopLogBics = VopLog::whereNotNull('bic')
            ->distinct()
            ->pluck('bic')
            ->values();

        foreach ($vopLogBics as $bic) {
            $randVal = rand(0, 10);
            if($randVal % 2 === 0) {
                BankReference::factory()->create(['bic' => $bic]);
            }
        }

        BankReference::factory()->count(10)->create();
    }
}
