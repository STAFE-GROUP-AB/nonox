<?php

declare(strict_types=1);

use App\Modules\Connect\Http\Controllers\Api\V1\ArticleController;
use App\Modules\Connect\Http\Controllers\Api\V1\CrmController;
use App\Modules\Connect\Http\Controllers\Api\V1\CrmDealController;
use App\Modules\Connect\Http\Controllers\Api\V1\CrmSigningController;
use App\Modules\Connect\Http\Controllers\Api\V1\CustomerController;
use App\Modules\Connect\Http\Controllers\Api\V1\DimensionController;
use App\Modules\Connect\Http\Controllers\Api\V1\FiscalYearController;
use App\Modules\Connect\Http\Controllers\Api\V1\InventoryController;
use App\Modules\Connect\Http\Controllers\Api\V1\InvoiceController;
use App\Modules\Connect\Http\Controllers\Api\V1\PaymentController;
use App\Modules\Connect\Http\Controllers\Api\V1\PlanningController;
use App\Modules\Connect\Http\Controllers\Api\V1\ReferenceController;
use App\Modules\Connect\Http\Controllers\Api\V1\ReportController;
use App\Modules\Connect\Http\Controllers\Api\V1\SupplierController;
use App\Modules\Connect\Http\Controllers\Api\V1\SupplierInvoiceController;
use App\Modules\Connect\Http\Controllers\Api\V1\VatController;
use App\Modules\Connect\Http\Controllers\Api\V1\VoucherAttachmentController;
use App\Modules\Connect\Http\Controllers\Api\V1\VoucherController;
use Illuminate\Support\Facades\Route;

/*
 * NONOX API, API v1 (ADR-032). Every route names its scope; every POST
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
    Route::get('/fiscal-years/{fiscalYear}/periods', [FiscalYearController::class, 'periods'])->whereNumber('fiscalYear')->name('fiscal-years.periods');
    Route::get('/cost-centers', [DimensionController::class, 'costCenters'])->name('cost-centers.index');
    Route::get('/cost-centers/{costCenter}', [DimensionController::class, 'costCenter'])->whereNumber('costCenter')->name('cost-centers.show');
    Route::get('/projects', [DimensionController::class, 'projects'])->name('projects.index');
    Route::get('/projects/{project}', [DimensionController::class, 'project'])->whereNumber('project')->name('projects.show');
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

Route::middleware('connect.scope:accounts:manage')->group(function () use ($idempotent): void {
    Route::post('/cost-centers', [DimensionController::class, 'storeCostCenter'])->middleware($idempotent)->name('cost-centers.store');
    Route::patch('/cost-centers/{costCenter}', [DimensionController::class, 'updateCostCenter'])->whereNumber('costCenter')->name('cost-centers.update');
    Route::post('/projects', [DimensionController::class, 'storeProject'])->middleware($idempotent)->name('projects.store');
    Route::patch('/projects/{project}', [DimensionController::class, 'updateProject'])->whereNumber('project')->name('projects.update');
});

Route::middleware('connect.scope:reports:view')->group(function (): void {
    Route::get('/reports/trial-balance', [ReportController::class, 'trialBalance'])->name('reports.trial-balance');
    Route::get('/reports/general-ledger', [ReportController::class, 'generalLedger'])->name('reports.general-ledger');
    Route::get('/reports/balance-sheet', [ReportController::class, 'balanceSheet'])->name('reports.balance-sheet');
    Route::get('/reports/income-statement', [ReportController::class, 'incomeStatement'])->name('reports.income-statement');
});

Route::middleware('connect.scope:vat:prepare')->group(function (): void {
    Route::get('/vat-periods', [VatController::class, 'periods'])->name('vat-periods.index');
    Route::get('/vat-return', [VatController::class, 'vatReturn'])->name('vat-return.show');
});

Route::get('/fiscal-years/{fiscalYear}/sie', [FiscalYearController::class, 'sie'])->whereNumber('fiscalYear')->middleware('connect.scope:export:sie')->name('fiscal-years.sie');

Route::middleware('connect.scope:invoices:view')->group(function (): void {
    Route::get('/customers', [CustomerController::class, 'index'])->name('customers.index');
    Route::get('/customers/{customer}', [CustomerController::class, 'show'])->whereNumber('customer')->name('customers.show');
    Route::get('/units', [ReferenceController::class, 'units'])->name('units.index');
    Route::get('/articles', [ArticleController::class, 'index'])->name('articles.index');
    Route::get('/articles/{article}', [ArticleController::class, 'show'])->whereNumber('article')->name('articles.show');
    Route::get('/invoices', [InvoiceController::class, 'index'])->name('invoices.index');
    Route::get('/invoices/{invoice}', [InvoiceController::class, 'show'])->whereUuid('invoice')->name('invoices.show');
    Route::get('/invoices/{invoice}/pdf', [InvoiceController::class, 'pdf'])->whereUuid('invoice')->name('invoices.pdf');
    Route::get('/payments', [PaymentController::class, 'incoming'])->name('payments.index');
});

Route::middleware('connect.scope:invoices:create')->group(function () use ($idempotent): void {
    Route::post('/customers', [CustomerController::class, 'store'])->middleware($idempotent)->name('customers.store');
    Route::patch('/customers/{customer}', [CustomerController::class, 'update'])->whereNumber('customer')->name('customers.update');
    Route::post('/articles', [ArticleController::class, 'store'])->middleware($idempotent)->name('articles.store');
    Route::patch('/articles/{article}', [ArticleController::class, 'update'])->whereNumber('article')->name('articles.update');
    Route::post('/invoices', [InvoiceController::class, 'store'])->middleware($idempotent)->name('invoices.store');
    Route::patch('/invoices/{invoice}', [InvoiceController::class, 'update'])->whereUuid('invoice')->name('invoices.update');
});

// Issuing, registering and paying book a voucher as the person behind the credential, so they need the booking scopes too.
$books = '+vouchers:create+vouchers:post';

Route::post('/invoices/{invoice}/issue', [InvoiceController::class, 'issue'])->whereUuid('invoice')->middleware(['connect.scope:invoices:send'.$books, $idempotent])->name('invoices.issue');
Route::post('/invoices/{invoice}/send', [InvoiceController::class, 'send'])->whereUuid('invoice')->middleware(['connect.scope:invoices:send', $idempotent])->name('invoices.send');

Route::post('/invoices/{invoice}/credit', [InvoiceController::class, 'credit'])->whereUuid('invoice')->middleware(['connect.scope:invoices:credit', $idempotent])->name('invoices.credit');
Route::post('/invoices/{invoice}/payments', [InvoiceController::class, 'registerPayment'])->whereUuid('invoice')->middleware(['connect.scope:payments:create'.$books, $idempotent])->name('invoices.payments.store');

Route::middleware('connect.scope:supplier-invoices:create|supplier-invoices:approve')->group(function (): void {
    Route::get('/suppliers', [SupplierController::class, 'index'])->name('suppliers.index');
    Route::get('/suppliers/{supplier}', [SupplierController::class, 'show'])->whereNumber('supplier')->name('suppliers.show');
});

Route::middleware('connect.scope:supplier-invoices:create|supplier-invoices:approve|payments:create')->group(function (): void {
    Route::get('/supplier-invoices', [SupplierInvoiceController::class, 'index'])->name('supplier-invoices.index');
    Route::get('/supplier-invoices/{supplierInvoice}', [SupplierInvoiceController::class, 'show'])->whereUuid('supplierInvoice')->name('supplier-invoices.show');
    Route::get('/supplier-payments', [PaymentController::class, 'outgoing'])->name('supplier-payments.index');
});

Route::middleware('connect.scope:supplier-invoices:create')->group(function () use ($idempotent): void {
    Route::post('/suppliers', [SupplierController::class, 'store'])->middleware($idempotent)->name('suppliers.store');
    Route::patch('/suppliers/{supplier}', [SupplierController::class, 'update'])->whereNumber('supplier')->name('suppliers.update');
    Route::post('/supplier-invoices', [SupplierInvoiceController::class, 'store'])->middleware($idempotent)->name('supplier-invoices.store');
    Route::patch('/supplier-invoices/{supplierInvoice}', [SupplierInvoiceController::class, 'update'])->whereUuid('supplierInvoice')->name('supplier-invoices.update');
    Route::delete('/supplier-invoices/{supplierInvoice}', [SupplierInvoiceController::class, 'destroy'])->whereUuid('supplierInvoice')->name('supplier-invoices.destroy');
    Route::post('/supplier-invoices/{supplierInvoice}/file', [SupplierInvoiceController::class, 'file'])->whereUuid('supplierInvoice')->middleware($idempotent)->defaults('connect.multipart', true)->name('supplier-invoices.file');
});

Route::post('/supplier-invoices/{supplierInvoice}/register', [SupplierInvoiceController::class, 'register'])->whereUuid('supplierInvoice')->middleware(['connect.scope:supplier-invoices:create'.$books, $idempotent])->name('supplier-invoices.register');
Route::post('/supplier-invoices/{supplierInvoice}/approve', [SupplierInvoiceController::class, 'approve'])->whereUuid('supplierInvoice')->middleware(['connect.scope:supplier-invoices:approve', $idempotent])->name('supplier-invoices.approve');
Route::post('/supplier-invoices/{supplierInvoice}/payments', [SupplierInvoiceController::class, 'pay'])->whereUuid('supplierInvoice')->middleware(['connect.scope:payments:create'.$books, $idempotent])->name('supplier-invoices.payments.store');

// Planering (PLANNING_MODULE.md §9): read only. planning:view is false while the module is off.
Route::middleware('connect.scope:planning:view')->group(function (): void {
    Route::get('/planning/shifts', [PlanningController::class, 'shifts'])->name('planning.shifts.index');
    Route::get('/planning/areas', [PlanningController::class, 'areas'])->name('planning.areas.index');
    Route::get('/planning/publications', [PlanningController::class, 'publications'])->name('planning.publications.index');
});

// Lager (ADR-039). inventory:view and inventory:manage are false while the module is off.
Route::middleware('connect.scope:inventory:view')->group(function (): void {
    Route::get('/inventory/articles', [InventoryController::class, 'articles'])->name('inventory.articles.index');
    Route::get('/inventory/articles/{article}/images/{image}/content', [InventoryController::class, 'image'])->whereNumber(['article', 'image'])->name('inventory.articles.images.content');
    Route::get('/inventory/locations', [InventoryController::class, 'locations'])->name('inventory.locations.index');
    Route::get('/inventory/stock-levels', [InventoryController::class, 'levels'])->name('inventory.stock-levels.index');
});

Route::post('/inventory/receipts', [InventoryController::class, 'storeReceipt'])->middleware(['connect.scope:inventory:manage', $idempotent])->name('inventory.receipts.store');

// CRM (ADR-043). crm:view and crm:manage are false while the module is off.
Route::middleware('connect.scope:crm:view')->group(function (): void {
    Route::get('/crm/accounts', [CrmController::class, 'accounts'])->name('crm.accounts.index');
    Route::get('/crm/accounts/{account}', [CrmController::class, 'account'])->whereNumber('account')->name('crm.accounts.show');
    Route::get('/crm/people', [CrmController::class, 'people'])->name('crm.people.index');
    Route::get('/crm/people/{person}', [CrmController::class, 'person'])->whereNumber('person')->name('crm.people.show');
    Route::get('/crm/activities', [CrmController::class, 'activities'])->name('crm.activities.index');
    Route::get('/crm/activities/{activity}', [CrmController::class, 'activity'])->whereNumber('activity')->name('crm.activities.show');
    // Stage 2: deals, and the pipelines, stages and loss reasons they use (read only).
    Route::get('/crm/deals', [CrmDealController::class, 'deals'])->name('crm.deals.index');
    Route::get('/crm/deals/{deal}', [CrmDealController::class, 'deal'])->whereNumber('deal')->name('crm.deals.show');
    Route::get('/crm/pipelines', [CrmDealController::class, 'pipelines'])->name('crm.pipelines.index');
    Route::get('/crm/pipelines/{pipeline}', [CrmDealController::class, 'pipeline'])->whereNumber('pipeline')->name('crm.pipelines.show');
    Route::get('/crm/stages', [CrmDealController::class, 'stages'])->name('crm.stages.index');
    Route::get('/crm/loss-reasons', [CrmDealController::class, 'lossReasons'])->name('crm.loss-reasons.index');
});

Route::middleware('connect.scope:crm:manage')->group(function () use ($idempotent): void {
    Route::post('/crm/accounts', [CrmController::class, 'storeAccount'])->middleware($idempotent)->name('crm.accounts.store');
    Route::patch('/crm/accounts/{account}', [CrmController::class, 'updateAccount'])->whereNumber('account')->name('crm.accounts.update');
    Route::post('/crm/people', [CrmController::class, 'storePerson'])->middleware($idempotent)->name('crm.people.store');
    Route::patch('/crm/people/{person}', [CrmController::class, 'updatePerson'])->whereNumber('person')->name('crm.people.update');
    Route::post('/crm/activities', [CrmController::class, 'storeActivity'])->middleware($idempotent)->name('crm.activities.store');
    Route::patch('/crm/activities/{activity}', [CrmController::class, 'updateActivity'])->whereNumber('activity')->name('crm.activities.update');
    Route::post('/crm/deals', [CrmDealController::class, 'storeDeal'])->middleware($idempotent)->name('crm.deals.store');
    Route::patch('/crm/deals/{deal}', [CrmDealController::class, 'updateDeal'])->whereNumber('deal')->name('crm.deals.update');
});

// CRM stage 3 (ADR-046): signing and the agreement register. Sending a quote for signing needs what the quote screen needs.
Route::get('/quotes/{quote}/signing', [CrmSigningController::class, 'quoteSignings'])->whereUuid('quote')->middleware('connect.scope:invoices:view')->name('quotes.signing.index');
Route::post('/quotes/{quote}/signing', [CrmSigningController::class, 'sendQuote'])->whereUuid('quote')->middleware(['connect.scope:invoices:create+invoices:send', $idempotent])->name('quotes.signing.store');
Route::middleware('connect.scope:crm:view')->group(function (): void {
    Route::get('/crm/agreements', [CrmSigningController::class, 'agreements'])->name('crm.agreements.index');
    Route::get('/crm/agreements/{agreement}', [CrmSigningController::class, 'agreement'])->whereNumber('agreement')->name('crm.agreements.show');
    Route::get('/crm/agreements/{agreement}/signing', [CrmSigningController::class, 'agreementSignings'])->whereNumber('agreement')->name('crm.agreements.signing.index');
});
Route::middleware('connect.scope:crm:manage')->group(function () use ($idempotent): void {
    Route::post('/crm/agreements', [CrmSigningController::class, 'storeAgreement'])->middleware($idempotent)->name('crm.agreements.store');
    Route::patch('/crm/agreements/{agreement}', [CrmSigningController::class, 'updateAgreement'])->whereNumber('agreement')->name('crm.agreements.update');
    Route::post('/crm/agreements/{agreement}/signing', [CrmSigningController::class, 'sendAgreement'])->whereNumber('agreement')->middleware($idempotent)->name('crm.agreements.signing.store');
});
