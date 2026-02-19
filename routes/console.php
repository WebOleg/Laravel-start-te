<?php
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;
Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');
Schedule::command('billing:dispatch')->everyMinute();
Schedule::command('emp:fetch-chargeback-codes --empty --chunk=200')->everyTwoHours(20);
// Morning bulk fetch: catch all remaining CB codes after nightly sync
Schedule::command('emp:fetch-chargeback-codes --empty --chunk=1000')->dailyAt('06:30');
Schedule::command('emp:sync-chargebacks --days=1')->dailyAt('06:00');
// Cleanup broken batches (negative pending_jobs) every hour
Schedule::command('batches:cleanup')->hourly();
// Auto-blacklist BICs with high chargeback rates (30-day window)
Schedule::command('bic-blacklist:auto --period=30')->dailyAt('04:00');
// Refresh EMP data for last 30 days
Schedule::call(function () {
    Artisan::call('emp:refresh', [
        '--from' => now()->subDays(30)->format('Y-m-d'),
        '--to' => now()->subDay()->format('Y-m-d')
    ]);
})->dailyAt('05:00');
