<?php
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

$cronLog = storage_path('logs/cron.log');

Schedule::command('billing:dispatch')->everyMinute();

Schedule::command('emp:fetch-chargeback-codes --empty --chunk=200')
    ->everyTwoHours(20)
    ->appendOutputTo($cronLog)
    ->withoutOverlapping();

Schedule::command('emp:fetch-chargeback-codes --empty --chunk=1000')
    ->dailyAt('06:30')
    ->appendOutputTo($cronLog)
    ->withoutOverlapping();

Schedule::command('emp:sync-chargebacks --days=1')
    ->dailyAt('06:00')
    ->appendOutputTo($cronLog)
    ->withoutOverlapping();

Schedule::command('batches:cleanup')
    ->hourly()
    ->appendOutputTo($cronLog);

Schedule::command('bic-blacklist:auto --period=30')
    ->dailyAt('04:00')
    ->appendOutputTo($cronLog)
    ->withoutOverlapping();

Schedule::call(function () {
    Artisan::call('emp:refresh', [
        '--from' => now()->subDays(30)->format('Y-m-d'),
        '--to' => now()->subDay()->format('Y-m-d')
    ]);
})->dailyAt('05:00')
  ->appendOutputTo($cronLog)
  ->withoutOverlapping();
