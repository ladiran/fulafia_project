<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Schedule::command('advisers:send-expiry-reminders')->dailyAt('06:00');
Schedule::command('deferments:flag-lapsed')->dailyAt('06:10');
Schedule::command('imports:purge')->dailyAt('03:00');

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');
