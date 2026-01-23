<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Schedule::command('billing:dispatch')->everyMinute();
Schedule::command('emp:fetch-chargeback-code --empty --chunk=300')->everySixHours();
Schedule::command('emp:sync-chargebacks --days=1')->dailyAt('06:00');
