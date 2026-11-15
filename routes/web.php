<?php

use App\Http\Controllers\ChargeCategoryController;
use App\Http\Controllers\ChargeController;
use App\Http\Controllers\CollectionsController;
use App\Http\Controllers\InvoiceStatementController;
use App\Http\Controllers\OccupancyController;
use App\Http\Controllers\OneTimeChargeController;
use App\Http\Controllers\OpeningBalanceController;
use App\Http\Controllers\PaymentController;
use App\Http\Controllers\PropertyController;
use App\Http\Controllers\RecurringChargeController;
use App\Http\Controllers\RentalAgreementController;
use App\Http\Controllers\SecurityDepositController;
use App\Http\Controllers\TenantController;
use App\Http\Controllers\UnitController;
use App\Http\Controllers\UtilityBillController;
use App\Http\Controllers\UtilityController;
use App\Http\Controllers\UtilityExclusionController;
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
    Route::post('occupancies/{occupancy}/move-out', [OccupancyController::class, 'moveOut'])->name('occupancies.move-out');
    Route::post('accounts/{account}/deposits', [SecurityDepositController::class, 'store'])->name('accounts.deposits.store');
    Route::get('charges', [ChargeController::class, 'index'])->name('charges.index');
    Route::post('agreements/{agreement}/recurring-charges', [RecurringChargeController::class, 'store'])->name('agreements.recurring-charges.store');
    Route::patch('recurring-charges/{charge}', [RecurringChargeController::class, 'update'])->name('recurring-charges.update');
    Route::post('recurring-charges/{charge}/end', [RecurringChargeController::class, 'end'])->name('recurring-charges.end');
    Route::post('one-time-charges', [OneTimeChargeController::class, 'store'])->name('one-time-charges.store');
    Route::get('utilities', [UtilityController::class, 'index'])->name('utilities.index');
    Route::put('units/{unit}/utilities/{type}', [UtilityController::class, 'configure'])->whereIn('type', ['electricity', 'water'])->name('utilities.configure');
    Route::post('utility-bills', [UtilityBillController::class, 'store'])->name('utility-bills.store');
    Route::get('utility-bills/{bill}/evidence', [UtilityBillController::class, 'evidence'])->name('utility-bills.evidence');
    Route::post('agreements/{agreement}/utility-exclusions', [UtilityExclusionController::class, 'store'])->name('agreements.utility-exclusions.store');
    Route::delete('utility-exclusions/{exclusion}', [UtilityExclusionController::class, 'destroy'])->name('utility-exclusions.destroy');
    Route::get('opening-balances', [OpeningBalanceController::class, 'index'])->name('opening-balances.index');
    Route::post('opening-balances', [OpeningBalanceController::class, 'store'])->name('opening-balances.store');
    Route::post('opening-balances/import', [OpeningBalanceController::class, 'import'])->name('opening-balances.import');
    Route::post('opening-balances/{openingBalance}/approve', [OpeningBalanceController::class, 'approve'])->name('opening-balances.approve');
    Route::post('opening-balances/{openingBalance}/reverse', [OpeningBalanceController::class, 'reverse'])->name('opening-balances.reverse');
    Route::get('collections', [CollectionsController::class, 'index'])->name('collections.index');
    Route::get('accounts/{account}/payments/preview', [PaymentController::class, 'preview'])->name('accounts.payments.preview');
    Route::post('accounts/{account}/payments', [PaymentController::class, 'store'])->name('accounts.payments.store');
    Route::post('accounts/{account}/refunds', [PaymentController::class, 'refund'])->name('accounts.refunds.store');
    Route::post('ledger-entries/{entry}/write-off', [PaymentController::class, 'writeOff'])->name('ledger-entries.write-off');
    Route::get('payments/{payment}', [PaymentController::class, 'show'])->name('payments.show');
    Route::get('payments/{payment}/proof', [PaymentController::class, 'proof'])->name('payments.proof');
    Route::post('payments/{payment}/approve', [PaymentController::class, 'approve'])->name('payments.approve');
    Route::post('payments/{payment}/reverse', [PaymentController::class, 'reverse'])->name('payments.reverse');
    Route::post('payments/{payment}/allocate', [PaymentController::class, 'allocate'])->name('payments.allocate');
    Route::get('invoices/{invoice}/statement', [InvoiceStatementController::class, 'show'])->name('invoices.statement');
    Route::get('invoices/{invoice}/statement.pdf', [InvoiceStatementController::class, 'download'])->name('invoices.statement.pdf');
    Route::get('invoices/{invoice}/statement-copy.pdf', [InvoiceStatementController::class, 'copy'])->name('invoices.statement.copy');
    Route::post('charge-categories', [ChargeCategoryController::class, 'store'])->name('charge-categories.store');
    Route::patch('charge-categories/{category}', [ChargeCategoryController::class, 'update'])->name('charge-categories.update');
});

require __DIR__.'/settings.php';
require __DIR__.'/auth.php';
