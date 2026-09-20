<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

$year = config('elections.results_year');

Schedule::command("app:poll-election-results {$year}")
        ->everyMinute()
        ->withoutOverlapping()
        ->appendOutputTo(storage_path('logs/election-results.log'));
