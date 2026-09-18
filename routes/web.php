<?php

use Illuminate\Support\Facades\Route;

// Public website
Route::inertia('/', 'Public/Home')
     ->name('home');

// Admin website
Route::prefix('admin')
     ->name('admin.')
     ->middleware(['auth', 'verified'])
     ->group(function () {
         Route::inertia('dashboard', 'Admin/Dashboard')
              ->name('dashboard');
     });


require __DIR__ . '/settings.php';
