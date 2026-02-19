<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function (): void {
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

Schedule::command('system-alerts:evaluate')
    ->everyFiveMinutes()
    ->withoutOverlapping();

Schedule::command('security:check-pat-rotation')
    ->weekly()
    ->mondays()
    ->at('10:00')
    ->withoutOverlapping();

Schedule::command('backup:database')
    ->dailyAt('02:00')
    ->withoutOverlapping()
    ->runInBackground()
    ->onOneServer();

Schedule::command('memory:analyze-patterns')
    ->weekly()
    ->mondays()
    ->at('03:00')
    ->withoutOverlapping()
    ->when(fn (): bool => (bool) config('vunnix.memory.enabled', true) && (bool) config('vunnix.memory.cross_mr_patterns', true));

Schedule::command('memory:archive-expired')
    ->dailyAt('04:00')
    ->withoutOverlapping();
