<?php

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Route;

Route::get('/health', function () {
    Cache::put('health-check', 'ok', 5);

    return response()->json([
        'status' => 'ok',
        'cache' => Cache::get('health-check'),
    ]);
})->name('health');

Route::inertia('/', 'welcome')->name('home');

Route::middleware(['auth', 'verified'])->group(function () {
    Route::inertia('dashboard', 'dashboard')->name('dashboard');
});

require __DIR__.'/settings.php';
