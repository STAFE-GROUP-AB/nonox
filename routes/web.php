<?php

declare(strict_types=1);

use App\Http\Controllers\Accounting\AnnualReportController;
use App\Http\Controllers\Accounting\ArchivePackageController;
use App\Http\Controllers\Accounting\IncomeTaxSruController;
use App\Http\Controllers\Accounting\ReportCsvController;
use App\Http\Controllers\Accounting\SieExportController;
use App\Http\Controllers\Accounting\SieImportController;
use App\Http\Controllers\Accounting\VoucherController;
use App\Http\Controllers\Accounting\VoucherSeriesController;
use App\Http\Controllers\AlternativeController;
use App\Http\Controllers\Auth\LogoutController;
use App\Http\Controllers\CompanyController;
use App\Http\Controllers\CompanyInvitationController;
use App\Http\Controllers\CompanyLogoController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\DataSubjectExportController;
use App\Http\Controllers\GettingStartedController;
use App\Http\Controllers\Invoicing\AgreementController;
use App\Http\Controllers\Invoicing\InvoiceController;
use App\Http\Controllers\Invoicing\SalesDocumentController;
use App\Http\Controllers\LegalPageController;
use App\Http\Controllers\MigrationGuideController;
use App\Http\Controllers\OnboardingController;
use App\Http\Controllers\Payables\PaymentBatchFileController;
use App\Http\Controllers\Payables\SupplierInvoiceController;
use App\Http\Controllers\SitemapController;
use App\Http\Controllers\StartController;
use App\Http\Controllers\Vat\VatFileController;
use App\Http\Middleware\EnsureUserHasCompany;
use App\Models\SalesDocument;
use App\Support\Landing\LandingPage;
use Illuminate\Support\Facades\Route;
use Laravel\Jetstream\Http\Controllers\Livewire\UserProfileController;

/*
 * Every user-facing path is Swedish (AGENTS.md §11.4). Route names stay
 * English, so Jetstream's views and components keep working.
 */

Route::get('/', StartController::class)->name('start');
Route::get('/flytta-hit', MigrationGuideController::class)->name('migrationGuide');
Route::get('/integritetspolicy', LegalPageController::class)->defaults('document', 'privacy')->name('legal.privacy');
Route::get('/villkor', LegalPageController::class)->defaults('document', 'terms')->name('legal.terms');
Route::get('/personuppgiftsbitradesavtal', LegalPageController::class)->defaults('document', 'dpa')->name('legal.dpa');
Route::get('/underbitraden', LegalPageController::class)->defaults('document', 'subprocessors')->name('legal.subprocessors');
Route::get('/cookies', fn () => view('start.cookies', LandingPage::data()))->name('cookies');
Route::get('/alternativ-till-{program}', AlternativeController::class)->where('program', '[a-z-]+')->name('alternative');
Route::get('/sitemap.xml', [SitemapController::class, 'sitemap'])->name('sitemap');
Route::get('/robots.txt', [SitemapController::class, 'robots'])->name('robots');

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
    Route::get('/foretag/{company}/logotyp', CompanyLogoController::class)->whereNumber('company')->name('teams.logo');
    Route::put('/aktuellt-foretag', [CompanyController::class, 'switch'])->name('current-team.update');
    Route::view('/mina-foretag', 'companies.overview')->name('companies.overview');

    Route::get('/foretagsinbjudningar/{invitation}', [CompanyInvitationController::class, 'accept'])
        ->whereNumber('invitation')
        ->middleware('signed')
        ->name('team-invitations.accept');
    Route::post('/foretagsinbjudningar/{invitation}/acceptera', [CompanyInvitationController::class, 'accept'])
        ->whereNumber('invitation')
        ->name('team-invitations.accept-from-onboarding');

    Route::get('/profil/mina-uppgifter', [DataSubjectExportController::class, 'me'])->name('privacy.export.me');

    Route::middleware(EnsureUserHasCompany::class)->group(function () {
        Route::get('/oversikt', DashboardController::class)->name('dashboard');
        Route::get('/registerutdrag/kund/{customer}', [DataSubjectExportController::class, 'customer'])->whereNumber('customer')->name('privacy.export.customer');
        Route::get('/registerutdrag/leverantor/{supplier}', [DataSubjectExportController::class, 'supplier'])->whereNumber('supplier')->name('privacy.export.supplier');
        Route::get('/registerutdrag/anstalld/{employee}', [DataSubjectExportController::class, 'employee'])->whereNumber('employee')->name('privacy.export.employee');
        Route::put('/oversikt/kom-igang', [GettingStartedController::class, 'update'])->name('dashboard.gettingStarted');

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
        Route::get('/rakenskapsar/{fiscalYear}/arkivpaket/{archivePackage}', [ArchivePackageController::class, 'show'])
            ->whereNumber('fiscalYear')
            ->whereNumber('archivePackage')
            ->name('accounting.fiscalYears.archive');
        Route::get('/verifikationsserier', VoucherSeriesController::class)->name('accounting.voucherSeries');
        Route::view('/fakturor', 'invoicing.invoices.index')->name('invoicing.invoices.index');
        Route::view('/fakturor/ny', 'invoicing.invoices.edit', ['invoice' => null])->name('invoicing.invoices.create');
        Route::get('/fakturor/{invoice}', [InvoiceController::class, 'show'])->whereUuid('invoice')->name('invoicing.invoices.show');
        Route::get('/fakturor/{invoice}/andra', [InvoiceController::class, 'edit'])->whereUuid('invoice')->name('invoicing.invoices.edit');
        Route::get('/fakturor/{invoice}/pdf', [InvoiceController::class, 'pdf'])->whereUuid('invoice')->name('invoicing.invoices.pdf');
        foreach (['offerter' => ['invoicing.quotes', SalesDocument::QUOTE], 'ordrar' => ['invoicing.orders', SalesDocument::ORDER]] as $path => [$name, $kind]) {
            Route::view("/{$path}", 'invoicing.sales-documents.index', ['kind' => $kind])->name($name);
            Route::view("/{$path}/ny", 'invoicing.sales-documents.show', ['kind' => $kind, 'document' => null])->name("{$name}.create");
            Route::get("/{$path}/{document}", [SalesDocumentController::class, 'show'])->whereUuid('document')->name("{$name}.show");
            Route::get("/{$path}/{document}/pdf", [SalesDocumentController::class, 'pdf'])->whereUuid('document')->name("{$name}.pdf");
        }

        Route::view('/inbetalningar', 'invoicing.incoming-payments')->name('invoicing.incomingPayments');
        Route::view('/avtal', 'invoicing.agreements.index')->name('invoicing.agreements');
        Route::view('/avtal/nytt', 'invoicing.agreements.show', ['agreement' => null])->name('invoicing.agreements.create');
        Route::get('/avtal/{agreement}', AgreementController::class)->whereUuid('agreement')->name('invoicing.agreements.show');
        Route::view('/kunder', 'invoicing.customers')->name('invoicing.customers');
        Route::view('/leverantorer', 'payables.suppliers')->name('payables.suppliers');
        Route::view('/leverantorsfakturor', 'payables.invoices.index')->name('payables.invoices.index');
        Route::view('/leverantorsfakturor/ny', 'payables.invoices.edit', ['invoice' => null])->name('payables.invoices.create');
        Route::view('/leverantorsfakturor/betalfiler', 'payables.batches')->name('payables.batches.index');
        Route::get('/leverantorsfakturor/betalfiler/{batch}', PaymentBatchFileController::class)->whereUuid('batch')->name('payables.batches.file');
        Route::get('/leverantorsfakturor/{supplierInvoice}', [SupplierInvoiceController::class, 'show'])->whereUuid('supplierInvoice')->name('payables.invoices.show');
        Route::get('/leverantorsfakturor/{supplierInvoice}/andra', [SupplierInvoiceController::class, 'edit'])->whereUuid('supplierInvoice')->name('payables.invoices.edit');
        Route::get('/leverantorsfakturor/{supplierInvoice}/faktura', [SupplierInvoiceController::class, 'file'])->whereUuid('supplierInvoice')->name('payables.invoices.file');
        Route::view('/artiklar', 'invoicing.articles')->name('invoicing.articles');

        Route::view('/rapporter', 'accounting.reports')->name('accounting.reports');
        Route::view('/moms', 'vat.index')->name('vat.index');
        Route::get('/moms/{fiscalYear}/fil', VatFileController::class)->whereNumber('fiscalYear')->name('vat.file');
        Route::view('/eu-rapporter', 'vat.eu-reports')->name('vat.euReports');
        Route::get('/rapporter/csv', ReportCsvController::class)->name('accounting.reports.csv');
        Route::view('/kostnadsstallen', 'accounting.dimensions', ['dimension' => 'kostnadsstallen'])->name('accounting.costCenters');
        Route::view('/projekt', 'accounting.dimensions', ['dimension' => 'projekt'])->name('accounting.projects');
        Route::view('/anlaggningar', 'accounting.fixed-assets')->name('accounting.fixedAssets');
        Route::view('/periodiseringar', 'accounting.accruals')->name('accounting.accruals');
        Route::view('/bankavstamning', 'accounting.bank-reconciliation')->name('accounting.bankReconciliation');
        Route::view('/bokslut', 'accounting.closing')->name('accounting.closing');
        Route::view('/arsredovisning', 'accounting.annual-report')->name('accounting.annualReport');
        Route::get('/arsredovisning/{fiscalYear}', [AnnualReportController::class, 'show'])->whereNumber('fiscalYear')->name('accounting.annualReport.show');
        Route::get('/arsredovisning/{fiscalYear}/pdf', [AnnualReportController::class, 'pdf'])->whereNumber('fiscalYear')->name('accounting.annualReport.pdf');
        Route::view('/inkomstdeklaration', 'accounting.income-tax')->name('accounting.incomeTax');
        Route::get('/inkomstdeklaration/{fiscalYear}/sru', IncomeTaxSruController::class)->whereNumber('fiscalYear')->name('accounting.incomeTax.sru');
        Route::get('/importera-sie', [SieImportController::class, 'index'])->name('accounting.sieImports.index');
        Route::post('/importera-sie', [SieImportController::class, 'store'])->name('accounting.sieImports.store');
        Route::get('/importera-sie/{sieImport}', [SieImportController::class, 'show'])
            ->whereNumber('sieImport')
            ->name('accounting.sieImports.show');
    });
});
