<?php

namespace App\Traits;

use Carbon\Carbon;

trait HasDatePeriods
{
    protected function getStartDateFromPeriod(string $period): Carbon
    {
        return match ($period) {
            '24h' => now()->subDay(),
            '7d' => now()->subDays(7),
            '30d' => now()->subDays(30),
            '90d' => now()->subDays(90),
            'all' => Carbon::parse('1970-01-01'),
            default => now()->subDays(7),
        };
    }
}