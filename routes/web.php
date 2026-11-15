<?php

use App\Http\Controllers\PropertyController;
use App\Http\Controllers\RentalAgreementController;
use App\Http\Controllers\SecurityDepositController;
use App\Http\Controllers\TenantController;
use App\Http\Controllers\UnitController;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;

// No public landing page: guests are sent to login by the dashboard's auth middleware.
Route::redirect('/', '/dashboard')->name('home');

Route::middleware(['auth'])->group(function () {
    Route::get('dashboard', function () {
        return Inertia::render('dashboard');
    })->name('dashboard');

    Route::resource('properties', PropertyController::class)->except('destroy');
    Route::resource('properties.units', UnitController::class)->shallow()->only(['store', 'update']);
    Route::resource('tenants', TenantController::class)->except('destroy');
    Route::patch('tenants/{tenant}/archive', [TenantController::class, 'archive'])->name('tenants.archive');
    Route::post('tenants/{tenant}/agreements', [RentalAgreementController::class, 'store'])->name('tenants.agreements.store');
    Route::post('agreements/{agreement}/rent', [RentalAgreementController::class, 'changeRent'])->name('agreements.rent.store');
    Route::post('agreements/{agreement}/move-out', [RentalAgreementController::class, 'moveOut'])->name('agreements.move-out');
    Route::post('agreements/{agreement}/transfer', [RentalAgreementController::class, 'transfer'])->name('agreements.transfer');
    Route::post('accounts/{account}/deposits', [SecurityDepositController::class, 'store'])->name('accounts.deposits.store');
});

require __DIR__.'/settings.php';
require __DIR__.'/auth.php';
