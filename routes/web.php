<?php

use Illuminate\Support\Facades\Route;
use Inertia\Inertia;

// No public landing page: guests are sent to login by the dashboard's auth middleware.
Route::redirect('/', '/dashboard')->name('home');

Route::middleware(['auth'])->group(function () {
    Route::get('dashboard', function () {
        return Inertia::render('dashboard');
    })->name('dashboard');
});

require __DIR__.'/settings.php';
require __DIR__.'/auth.php';
