<?php

use Illuminate\Support\Facades\Schedule;

$year = config('elections.results_year');

Schedule::command("app:poll-election-results {$year}")
    ->everyMinute()
        // ->everyFiveSeconds()
    ->withoutOverlapping()
    ->appendOutputTo(storage_path('logs/election-results.log'));

Schedule::command('app:start-election-results 2026')
    ->at('20:00')
    ->when(fn () => now()->format('Y-m-d') === '2026-10-05');
