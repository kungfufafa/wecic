<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Schedule::command('queue:work --queue=campus-sessions,campus-uploads,campus-refresh,campus-notifications,default --stop-when-empty')
    ->everyMinute()
    ->withoutOverlapping();
