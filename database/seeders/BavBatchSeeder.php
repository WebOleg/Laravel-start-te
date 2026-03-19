<?php

/**
 * Seeder for BavBatch: creates 100 randomised BAV batch records across all statuses.
 */

namespace Database\Seeders;

use App\Models\BavBatch;
use App\Models\User;
use Illuminate\Database\Seeder;

class BavBatchSeeder extends Seeder
{
    public function run(): void
    {
        // Reuse the canonical test admin or create it
        $admin = User::firstOrCreate(
            ['email' => 'admin@tether.test'],
            [
                'name'     => 'Admin User',
                'password' => bcrypt('password'),
            ]
        );

        // A small pool of extra users to distribute batches across
        $users = User::factory()->count(4)->create();
        $users->push($admin);

        // 35 completed batches
        BavBatch::factory()
            ->count(35)
            ->completed()
            ->recycle($users)
            ->create();

        // 30 pending batches (uploaded, not yet started)
        BavBatch::factory()
            ->count(30)
            ->pending()
            ->recycle($users)
            ->create();

        // 20 in-progress batches
        BavBatch::factory()
            ->count(20)
            ->processing()
            ->recycle($users)
            ->create();

        // 15 failed batches
        BavBatch::factory()
            ->count(15)
            ->failed()
            ->recycle($users)
            ->create();

        $this->command->info('BavBatchSeeder: 100 BAV batch records created (35 completed, 30 pending, 20 processing, 15 failed).');
    }
}
