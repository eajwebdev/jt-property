<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Schedule::command('billing:generate-scheduled')->dailyAt('06:00')->timezone('Asia/Manila')->withoutOverlapping();
Schedule::command('billing:send-reminders')->dailyAt('07:00')->timezone('Asia/Manila')->withoutOverlapping();
