<?php

/**
 * Main database seeder that runs all seeders.
 */

namespace Database\Seeders;

use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $this->call([
            UploadSeeder::class,
            BankReferenceSeeder::class,
        ]);
    }
}
