<?php

use App\Http\Controllers\Api\ElectionResultsSummaryController;
use Illuminate\Support\Facades\Route;

Route::get(
    '/elections/{election:year}/results/summary',
    ElectionResultsSummaryController::class
)->name('api.elections.results.summary');
