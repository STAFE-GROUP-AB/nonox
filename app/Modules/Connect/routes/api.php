<?php

declare(strict_types=1);

use App\Modules\Connect\Http\Controllers\Api\V1\CustomerController;
use App\Modules\Connect\Http\Controllers\Api\V1\ReferenceController;
use App\Modules\Connect\Http\Controllers\Api\V1\SupplierController;
use App\Modules\Connect\Http\Controllers\Api\V1\VoucherAttachmentController;
use App\Modules\Connect\Http\Controllers\Api\V1\VoucherController;
use Illuminate\Support\Facades\Route;

/*
 * NONOX Connect, API v1 (ADR-032). Every route names its scope; every POST
 * needs an Idempotency-Key. docs/api/openapi.yaml describes each route and
 * tests/Feature/Connect/OpenApiSpecTest.php fails when one is missing there.
 */

$idempotent = 'connect.idempotent';

Route::get('/company', [ReferenceController::class, 'company'])->name('company');

Route::middleware('connect.scope:vouchers:view')->group(function (): void {
    Route::get('/accounts', [ReferenceController::class, 'accounts'])->name('accounts.index');
    Route::get('/accounts/{number}', [ReferenceController::class, 'account'])->whereNumber('number')->name('accounts.show');
    Route::get('/vat-codes', [ReferenceController::class, 'vatCodes'])->name('vat-codes.index');
    Route::get('/fiscal-years', [ReferenceController::class, 'fiscalYears'])->name('fiscal-years.index');
    Route::get('/fiscal-years/{fiscalYear}', [ReferenceController::class, 'fiscalYear'])->whereNumber('fiscalYear')->name('fiscal-years.show');
    Route::get('/voucher-series', [ReferenceController::class, 'voucherSeries'])->name('voucher-series.index');
    Route::get('/vouchers', [VoucherController::class, 'index'])->name('vouchers.index');
    Route::get('/vouchers/{voucher}', [VoucherController::class, 'show'])->whereUuid('voucher')->name('vouchers.show');
    Route::get('/vouchers/{voucher}/attachments', [VoucherAttachmentController::class, 'index'])->whereUuid('voucher')->name('vouchers.attachments.index');
    Route::get('/vouchers/{voucher}/attachments/{attachment}/content', [VoucherAttachmentController::class, 'download'])->whereUuid('voucher')->whereNumber('attachment')->name('vouchers.attachments.content');
});

Route::middleware('connect.scope:vouchers:create')->group(function () use ($idempotent): void {
    Route::post('/vouchers', [VoucherController::class, 'store'])->middleware($idempotent)->name('vouchers.store');
    Route::patch('/vouchers/{voucher}', [VoucherController::class, 'update'])->whereUuid('voucher')->name('vouchers.update');
    Route::delete('/vouchers/{voucher}', [VoucherController::class, 'destroy'])->whereUuid('voucher')->name('vouchers.destroy');
    Route::post('/vouchers/{voucher}/attachments', [VoucherAttachmentController::class, 'store'])->whereUuid('voucher')->middleware($idempotent)->defaults('connect.multipart', true)->name('vouchers.attachments.store');
});

Route::post('/vouchers/{voucher}/post', [VoucherController::class, 'post'])->whereUuid('voucher')->middleware(['connect.scope:vouchers:post', $idempotent])->name('vouchers.post');
Route::post('/vouchers/{voucher}/correct', [VoucherController::class, 'correct'])->whereUuid('voucher')->middleware(['connect.scope:vouchers:correct', $idempotent])->name('vouchers.correct');

Route::middleware('connect.scope:invoices:view')->group(function (): void {
    Route::get('/customers', [CustomerController::class, 'index'])->name('customers.index');
    Route::get('/customers/{customer}', [CustomerController::class, 'show'])->whereNumber('customer')->name('customers.show');
});

Route::middleware('connect.scope:invoices:create')->group(function () use ($idempotent): void {
    Route::post('/customers', [CustomerController::class, 'store'])->middleware($idempotent)->name('customers.store');
    Route::patch('/customers/{customer}', [CustomerController::class, 'update'])->whereNumber('customer')->name('customers.update');
});

Route::middleware('connect.scope:supplier-invoices:create|supplier-invoices:approve')->group(function (): void {
    Route::get('/suppliers', [SupplierController::class, 'index'])->name('suppliers.index');
    Route::get('/suppliers/{supplier}', [SupplierController::class, 'show'])->whereNumber('supplier')->name('suppliers.show');
});

Route::middleware('connect.scope:supplier-invoices:create')->group(function () use ($idempotent): void {
    Route::post('/suppliers', [SupplierController::class, 'store'])->middleware($idempotent)->name('suppliers.store');
    Route::patch('/suppliers/{supplier}', [SupplierController::class, 'update'])->whereNumber('supplier')->name('suppliers.update');
});
