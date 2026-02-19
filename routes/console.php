<?php
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

$cronLog = storage_path('logs/cron.log');

$logTimestamp = function (string $command) use ($cronLog): void {
    file_put_contents(
        $cronLog,
        "\n[" . now()->format('Y-m-d H:i:s') . "] Running: {$command}\n",
        FILE_APPEND
    );
};

Schedule::command('billing:dispatch')->everyMinute();

Schedule::command('batches:cleanup')
    ->hourly()
    ->before(fn () => $logTimestamp('batches:cleanup'))
    ->appendOutputTo($cronLog);

Schedule::command('emp:fetch-chargeback-codes --empty --chunk=200')
    ->everyTwoHours(20)
    ->before(fn () => $logTimestamp('emp:fetch-chargeback-codes --empty --chunk=200'))
    ->appendOutputTo($cronLog)
    ->withoutOverlapping();

Schedule::command('bic-blacklist:auto --period=30')
    ->dailyAt('00:00')
    ->before(fn () => $logTimestamp('bic-blacklist:auto --period=30'))
    ->appendOutputTo($cronLog)
    ->withoutOverlapping();

Schedule::command('emp:refresh', [
    '--from' => now()->subDays(30)->format('Y-m-d'),
    '--to' => now()->subDay()->format('Y-m-d')
])->name('emp:refresh-callback')
    ->dailyAt('01:00')
    ->before(fn () => $logTimestamp('emp:refresh'))
    ->appendOutputTo($cronLog)
    ->withoutOverlapping();

Schedule::command('emp:sync-chargebacks --days=1')
    ->dailyAt('02:00')
    ->before(fn () => $logTimestamp('emp:sync-chargebacks --days=1'))
    ->appendOutputTo($cronLog)
    ->withoutOverlapping();

Schedule::command('emp:fetch-chargeback-codes --empty --chunk=1000')
    ->dailyAt('02:30')
    ->before(fn () => $logTimestamp('emp:fetch-chargeback-codes --empty --chunk=1000'))
    ->appendOutputTo($cronLog)
    ->withoutOverlapping();
