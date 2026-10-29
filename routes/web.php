<?php

declare(strict_types=1);

use App\Http\Controllers\Accounting\ReportCsvController;
use App\Http\Controllers\Accounting\SieExportController;
use App\Http\Controllers\Accounting\SieImportController;
use App\Http\Controllers\Accounting\VoucherController;
use App\Http\Controllers\Accounting\VoucherSeriesController;
use App\Http\Controllers\Auth\LogoutController;
use App\Http\Controllers\CompanyController;
use App\Http\Controllers\CompanyInvitationController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\OnboardingController;
use App\Http\Controllers\StartController;
use App\Http\Controllers\Vat\VatFileController;
use App\Http\Middleware\EnsureUserHasCompany;
use Illuminate\Support\Facades\Route;
use Laravel\Jetstream\Http\Controllers\Livewire\UserProfileController;

/*
 * Every user-facing path is Swedish (AGENTS.md §11.4). Route names stay
 * English, so Jetstream's views and components keep working.
 */

Route::get('/', StartController::class)->name('start');

Route::middleware('guest')->group(function () {
    Route::view('/logga-in', 'auth.login')->name('login');
    Route::redirect('/registrera', '/logga-in')->name('register');
});

Route::post('/logga-ut', LogoutController::class)->middleware('auth')->name('logout');

Route::middleware([
    'auth:sanctum',
    config('jetstream.auth_session'),
    'verified',
])->group(function () {
    Route::get('/kom-igang', [OnboardingController::class, 'show'])->name('onboarding');
    Route::get('/profil', [UserProfileController::class, 'show'])->name('profile.show');

    Route::get('/foretag/nytt', [CompanyController::class, 'create'])->name('teams.create');
    Route::get('/foretag/{company}', [CompanyController::class, 'show'])->whereNumber('company')->name('teams.show');
    Route::put('/aktuellt-foretag', [CompanyController::class, 'switch'])->name('current-team.update');

    Route::get('/foretagsinbjudningar/{invitation}', [CompanyInvitationController::class, 'accept'])
        ->whereNumber('invitation')
        ->middleware('signed')
        ->name('team-invitations.accept');
    Route::post('/foretagsinbjudningar/{invitation}/acceptera', [CompanyInvitationController::class, 'accept'])
        ->whereNumber('invitation')
        ->name('team-invitations.accept-from-onboarding');

    Route::middleware(EnsureUserHasCompany::class)->group(function () {
        Route::get('/oversikt', DashboardController::class)->name('dashboard');

        Route::get('/verifikationer', [VoucherController::class, 'index'])->name('accounting.vouchers.index');
        Route::get('/verifikationer/ny', [VoucherController::class, 'create'])->name('accounting.vouchers.create');
        Route::get('/verifikationer/{voucher}', [VoucherController::class, 'show'])->whereUuid('voucher')->name('accounting.vouchers.show');
        Route::get('/verifikationer/{voucher}/andra', [VoucherController::class, 'edit'])->whereUuid('voucher')->name('accounting.vouchers.edit');
        Route::get('/verifikationer/{voucher}/underlag/{attachment}', [VoucherController::class, 'attachment'])
            ->whereUuid('voucher')
            ->whereNumber('attachment')
            ->name('accounting.vouchers.attachment');

        Route::view('/kontoplan', 'accounting.accounts')->name('accounting.accounts');
        Route::view('/rakenskapsar', 'accounting.fiscal-years')->name('accounting.fiscalYears');
        Route::get('/rakenskapsar/{fiscalYear}/sie-fil', [SieExportController::class, 'show'])
            ->whereNumber('fiscalYear')
            ->name('accounting.fiscalYears.sie');
        Route::get('/verifikationsserier', VoucherSeriesController::class)->name('accounting.voucherSeries');
        Route::view('/rapporter', 'accounting.reports')->name('accounting.reports');
        Route::view('/moms', 'vat.index')->name('vat.index');
        Route::get('/moms/{fiscalYear}/fil', VatFileController::class)->whereNumber('fiscalYear')->name('vat.file');
        Route::get('/rapporter/csv', ReportCsvController::class)->name('accounting.reports.csv');
        Route::view('/kostnadsstallen', 'accounting.dimensions', ['dimension' => 'kostnadsstallen'])->name('accounting.costCenters');
        Route::view('/projekt', 'accounting.dimensions', ['dimension' => 'projekt'])->name('accounting.projects');
        Route::get('/importera-sie', [SieImportController::class, 'index'])->name('accounting.sieImports.index');
        Route::post('/importera-sie', [SieImportController::class, 'store'])->name('accounting.sieImports.store');
        Route::get('/importera-sie/{sieImport}', [SieImportController::class, 'show'])
            ->whereNumber('sieImport')
            ->name('accounting.sieImports.show');
    });
});
