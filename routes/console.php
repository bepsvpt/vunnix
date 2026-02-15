<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Schedule::command('metrics:aggregate')
    ->everyFifteenMinutes()
    ->withoutOverlapping()
    ->runInBackground();

Schedule::command('cost-alerts:evaluate')
    ->everyFifteenMinutes()
    ->withoutOverlapping();

Schedule::command('overreliance:evaluate')
    ->weekly()
    ->mondays()
    ->at('09:00')
    ->withoutOverlapping();
