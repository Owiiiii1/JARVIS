<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Schedule::command('jarvis:reminders:dispatch')
    ->everyMinute()
    ->withoutOverlapping(10);

Schedule::command('jarvis:attachments:purge-ephemeral')
    ->hourly()
    ->withoutOverlapping(55);

Schedule::command('queue:work database --queue=memory,default --stop-when-empty --max-time=50 --tries=3')
    ->everyMinute()
    ->withoutOverlapping(1);
