<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

Route::get('/test/resultats2022.json', function (Request $request) {
    $path = public_path('resultats2022.json');

    abort_unless(file_exists($path), 404);

    $etag = hash_file('sha256', $path);

    $response = new BinaryFileResponse($path);

    $response->headers->set('Content-Type', 'application/json');
    $response->setEtag($etag);
    $response->setPublic();

    $response->isNotModified($request);

    return $response;
});

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
