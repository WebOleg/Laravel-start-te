<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

/**
 * Blacklist seeder - placeholder.
 * Use artisan commands for blacklist management:
 *   php artisan blacklist:add
 *   php artisan blacklist:list
 *   php artisan blacklist:remove
 */
class BlacklistSeeder extends Seeder
{
    public function run(): void
    {
        $this->command->info('Blacklist is managed via artisan commands.');
        $this->command->info('Use: php artisan blacklist:add --iban=... --reason=...');
    }
}
