<?php

use App\Http\Controllers\Api\AssemblyDissolutionController;
use App\Http\Controllers\Api\ElectionResultsSummaryController;
use Illuminate\Support\Facades\Route;

Route::get(
    '/elections/{election:year}/results/summary',
    ElectionResultsSummaryController::class
)->name('api.elections.results.summary');

Route::get(
    '/assembly/dissolution',
    AssemblyDissolutionController::class
)->name('api.assembly.dissolution');
