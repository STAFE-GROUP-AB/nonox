<?php

declare(strict_types=1);

use App\Http\Middleware\EnsureUserHasCompany;
use App\Migration\Import\Http\FlyttGuideController;
use App\Migration\Import\Http\RegisterImportController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth:web', config('jetstream.auth_session'), 'verified', EnsureUserHasCompany::class])->prefix('bokforing/las-in-uppgifter')->name('import.registers.')->group(function () {
    Route::get('/', [RegisterImportController::class, 'index'])->name('index');
    Route::post('/', [RegisterImportController::class, 'store'])->name('store');
    Route::get('/mall/{kind}', [RegisterImportController::class, 'template'])->where('kind', '[a-z_]+')->name('template');
    Route::get('/{registerImport}', [RegisterImportController::class, 'show'])->whereNumber('registerImport')->name('show');
    Route::post('/{registerImport}/granska', [RegisterImportController::class, 'analyse'])->whereNumber('registerImport')->name('analyse');
    Route::post('/{registerImport}/las-in', [RegisterImportController::class, 'confirm'])->whereNumber('registerImport')->name('confirm');
});

// The guides per program (ADR-076): public, and 404 until the instance setting is turned on.
Route::prefix('flytta-fran')->name('migrationFrom.')->controller(FlyttGuideController::class)->group(function () {
    Route::get('/', 'index')->name('index');
    Route::get('/{program}', 'show')->where('program', '[a-z0-9-]+')->name('show');
});
