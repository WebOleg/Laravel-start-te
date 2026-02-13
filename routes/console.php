<?php
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;
Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');
Schedule::command('billing:dispatch')->everyMinute();
Schedule::command('emp:fetch-chargeback-codes --empty --chunk=200')->everyTwoHours();
Schedule::command('emp:sync-chargebacks --days=1')->dailyAt('06:00');
// Cleanup broken batches (negative pending_jobs) every hour
Schedule::command('batches:cleanup')->hourly();
// Auto-blacklist BICs with high chargeback rates (30-day window)
Schedule::command('bic-blacklist:auto --period=30')->dailyAt('04:00');
