<?php

declare(strict_types=1);

use App\Http\Controllers\Accounting\AnnualReportController;
use App\Http\Controllers\Accounting\ArchivePackageController;
use App\Http\Controllers\Accounting\IncomeTaxSruController;
use App\Http\Controllers\Accounting\K10Controller;
use App\Http\Controllers\Accounting\ReportCsvController;
use App\Http\Controllers\Accounting\SieExportController;
use App\Http\Controllers\Accounting\SieImportController;
use App\Http\Controllers\Accounting\VoucherController;
use App\Http\Controllers\Accounting\VoucherSeriesController;
use App\Http\Controllers\AlternativeController;
use App\Http\Controllers\Auth\LogoutController;
use App\Http\Controllers\ChangelogController;
use App\Http\Controllers\CompanyController;
use App\Http\Controllers\CompanyInvitationController;
use App\Http\Controllers\CompanyLogoController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\DataSubjectExportController;
use App\Http\Controllers\DemoController;
use App\Http\Controllers\DeveloperPortalController;
use App\Http\Controllers\FirmLogoController;
use App\Http\Controllers\GettingStartedController;
use App\Http\Controllers\Group\GroupCsvController;
use App\Http\Controllers\GuideController;
use App\Http\Controllers\GuideTipsController;
use App\Http\Controllers\IndustryController;
use App\Http\Controllers\Invoicing\AgreementController;
use App\Http\Controllers\Invoicing\InvoiceController;
use App\Http\Controllers\Invoicing\SalesDocumentController;
use App\Http\Controllers\LegalPageController;
use App\Http\Controllers\ManualController;
use App\Http\Controllers\MigrationGuideController;
use App\Http\Controllers\OnboardingController;
use App\Http\Controllers\Payables\PaymentBatchFileController;
use App\Http\Controllers\Payables\SupplierInvoiceController;
use App\Http\Controllers\RoadmapController;
use App\Http\Controllers\RunMigrationsController;
use App\Http\Controllers\Search\RecentItemController;
use App\Http\Controllers\Search\SearchController;
use App\Http\Controllers\SecureChatController;
use App\Http\Controllers\SecurityPageController;
use App\Http\Controllers\Signing\SigningFileController;
use App\Http\Controllers\SitemapController;
use App\Http\Controllers\Skatteverket\SkatteverketAuthController;
use App\Http\Controllers\StartController;
use App\Http\Controllers\StatusPageController;
use App\Http\Controllers\TaskController;
use App\Http\Controllers\Vat\VatFileController;
use App\Http\Controllers\WaitlistController;
use App\Http\Middleware\EnsureHqAdmin;
use App\Http\Middleware\EnsureUserHasCompany;
use App\Models\SalesDocument;
use App\Support\Developer\Guides;
use App\Support\Guide\Guide;
use App\Support\Landing\Industries;
use App\Support\Landing\LandingPage;
use Illuminate\Support\Facades\Route;
use Laravel\Jetstream\Http\Controllers\Livewire\UserProfileController;

/*
 * Every user-facing path is Swedish (AGENTS.md §11.4). Route names stay
 * English, so Jetstream's views and components keep working.
 */

Route::get('/', StartController::class)->name('start');
// Runs pending migrations from the start page, only locally and from the same computer (RunMigrationsController).
Route::post('/start/uppdatera-databasen', RunMigrationsController::class)->name('setup.migrate');
Route::get('/flytta-hit', MigrationGuideController::class)->name('migrationGuide');
// Try without registering (ADR-077): a demo company of one's own for two hours. 404 while the instance setting is off.
Route::get('/prova', [DemoController::class, 'show'])->name('demo.show');
Route::post('/prova', [DemoController::class, 'start'])->name('demo.start');
Route::get('/prova/bygger/{token}', [DemoController::class, 'building'])->where('token', '[A-Za-z0-9]{40}')->name('demo.building');
Route::get('/prova/bygger/{token}/status', [DemoController::class, 'status'])->where('token', '[A-Za-z0-9]{40}')->name('demo.status');
// A guest request (UseDemoDatabase): the session of the demo starts here, in the demo database.
Route::get('/prova/oppna/{token}', [DemoController::class, 'open'])->where('token', '[A-Za-z0-9]{40}')->name('demo.open');
// The waiting list of the open beta (ADR-038): the way in for new users of the hosted service.
Route::get('/ko', WaitlistController::class)->name('waitlist');
Route::get('/integritetspolicy', LegalPageController::class)->defaults('document', 'privacy')->name('legal.privacy');
Route::get('/villkor', LegalPageController::class)->defaults('document', 'terms')->name('legal.terms');
Route::get('/personuppgiftsbitradesavtal', LegalPageController::class)->defaults('document', 'dpa')->name('legal.dpa');
Route::get('/underbitraden', LegalPageController::class)->defaults('document', 'subprocessors')->name('legal.subprocessors');
Route::get('/egen-drift', LegalPageController::class)->defaults('document', 'selfHosting')->name('legal.selfHosting');
Route::get('/tillganglighet', LegalPageController::class)->defaults('document', 'accessibility')->name('legal.accessibility');
Route::get('/sakerhet', SecurityPageController::class)->name('security');
// Responsible disclosure (ADR-078) and the status page; the security.txt of RFC 9116 sits where the RFC says.
Route::get('/sakerhetspolicy', LegalPageController::class)->defaults('document', 'securityPolicy')->name('security.policy');
Route::get('/status', [StatusPageController::class, 'status'])->name('status');
Route::get('/.well-known/security.txt', [StatusPageController::class, 'securityTxt'])->name('security.txt');
Route::get('/security.txt', [StatusPageController::class, 'legacySecurityTxt']);
Route::get('/cookies', fn () => view('start.cookies', LandingPage::data()))->name('cookies');
// What has shipped and what is coming, for people who never open the code repository. The feed keeps the .xml ending feed readers expect.
Route::get('/andringslogg', [ChangelogController::class, 'index'])->name('changelog');
Route::get('/andringslogg/rss.xml', [ChangelogController::class, 'feed'])->name('changelog.feed');
Route::get('/fardplan', RoadmapController::class)->name('roadmap');
Route::get('/alternativ-till-{program}', AlternativeController::class)->where('program', '[a-z-]+')->name('alternative');
Route::get('/branscher', [IndustryController::class, 'index'])->name('industries');
Route::get('/{industry}', [IndustryController::class, 'show'])->where('industry', implode('|', array_map(fn (string $slug): string => preg_quote($slug, '#'), Industries::slugs())))->name('industry');
// The developer site (ADR-032): Swedish paths, English with ?lang=en. The spec files keep their conventional names for API tools.
Route::prefix('utvecklare')->name('developers.')->controller(DeveloperPortalController::class)->group(function () {
    Route::get('/', 'home')->name('home');
    Route::get('/referens', 'reference')->name('reference');
    Route::get('/referens/{tag}', 'tag')->where('tag', '[a-z0-9-]+')->name('tag');
    Route::get('/andringar', 'changelog')->name('changelog');
    Route::get('/openapi.{format}', 'spec')->where('format', 'yaml|json')->name('spec');
    Route::get('/{guide}', 'guide')->where('guide', implode('|', array_map(fn (string $slug): string => preg_quote($slug, '#'), Guides::slugs())))->name('guide');
});
// The user manual (ADR-036): docs/manual/sv as pages, while the public pages are on. Search is client-side, from the index.
Route::prefix('manual')->name('manual.')->controller(ManualController::class)->group(function () {
    Route::get('/', 'home')->name('home');
    Route::get('/sokindex.json', 'searchIndex')->name('search');
    Route::get('/{section}', 'section')->where('section', '[a-z0-9-]+')->name('section');
    Route::get('/{section}/{article}', 'article')->where(['section' => '[a-z0-9-]+', 'article' => '[a-z0-9-]+'])->name('article');
});
// Företagsguiden (ADR-051): docs/foretagsguiden as pages. The personal start plan's address is reserved: no section route matches it, with or without an article after it.
Route::prefix('foretagsguiden')->name('guide.')->group(function () {
    Route::view('/'.Guide::START_PLAN_SLUG, 'guide.start-plan')
        ->middleware(['auth:web', config('jetstream.auth_session'), 'verified', EnsureUserHasCompany::class])
        ->name('plan');
    Route::put('/'.Guide::START_PLAN_SLUG.'/tips', [GuideTipsController::class, 'update'])
        ->middleware(['auth:web', config('jetstream.auth_session'), 'verified', EnsureUserHasCompany::class])
        ->name('tips');
    // Mina datum (ADR-051, stage 2). Its calendar link has no session and is in routes/guide-calendar.php.
    Route::view('/'.Guide::DATES_SLUG, 'guide.my-dates')
        ->middleware(['auth:web', config('jetstream.auth_session'), 'verified', EnsureUserHasCompany::class])
        ->name('dates');
    Route::controller(GuideController::class)->group(function () {
        Route::get('/', 'home')->name('home');
        Route::get('/{section}', 'section')->where('section', Guide::sectionPattern())->name('section');
        Route::get('/{section}/{article}', 'article')->where(['section' => Guide::sectionPattern(), 'article' => '[a-z0-9-]+'])->name('article');
    });
});
Route::get('/sitemap.xml', [SitemapController::class, 'sitemap'])->name('sitemap');
Route::get('/robots.txt', [SitemapController::class, 'robots'])->name('robots');

// The firm's logo on the login page reached from an invitation (ADR-030): signed, short-lived.
Route::get('/varumarke/{company}/byrans-logotyp', [FirmLogoController::class, 'signed'])->whereNumber('company')->middleware('signed')->name('brand.firm-logo');

Route::middleware('guest')->group(function () {
    Route::view('/logga-in', 'auth.login')->name('login');
    // No self-registration (ADR-038): new users come through the waiting list or an invitation.
    Route::redirect('/registrera', '/ko')->name('register');
});

Route::post('/logga-ut', LogoutController::class)->middleware('auth')->name('logout');

// The owner's area for the waiting list (ADR-038): 404 for everyone but the HQ admins, guests included.
Route::prefix('hq')->name('hq.')->middleware(EnsureHqAdmin::class)->group(function () {
    Route::view('/', 'hq.waitlist')->name('waitlist');
    Route::get('/ko/{entry}', fn (int $entry) => view('hq.entry', ['entryId' => $entry]))->whereNumber('entry')->name('waitlist.show');
    // Kunder: the companies and users of the instance, read-only metadata (ADR-038).
    Route::view('/kunder', 'hq.customers')->name('customers');
    Route::get('/kunder/foretag/{companyId}', fn (int $companyId) => view('hq.customer-company', ['companyId' => $companyId]))->whereNumber('companyId')->name('customers.company');
    Route::get('/kunder/anvandare/{userId}', fn (int $userId) => view('hq.customer-user', ['userId' => $userId]))->whereNumber('userId')->name('customers.user');
    // The installation's own settings: the operator company, the queue and self-registration (ADR-044).
    Route::view('/installningar', 'hq.settings')->name('settings');
    // The company register for prospecting (ADR-047): imports and exclusions after objections.
    Route::view('/foretagsregistret', 'hq.registry')->name('registry');
    // The bank feed and Peppol across the installation, with the kill switches (ADR-034, ADR-050).
    Route::view('/integrationer', 'hq.integrations')->name('integrations');
    // Incidents and planned maintenance on the public status page (ADR-078).
    Route::view('/status', 'hq.status')->name('status');
});
// The passkey step for a signed-in session that has to pass it (RequirePasskey, ADR-029).
Route::view('/logga-in/passkey', 'auth.passkey')->middleware('auth')->name('passkeys.challenge');

Route::middleware([
    'auth:web',
    config('jetstream.auth_session'),
    'verified',
])->group(function () {
    Route::get('/kom-igang', [OnboardingController::class, 'show'])->name('onboarding');
    Route::get('/profil', [UserProfileController::class, 'show'])->middleware('not-demo-guest')->name('profile.show');

    Route::get('/foretag/nytt', [CompanyController::class, 'create'])->middleware('not-demo-guest')->name('teams.create');
    Route::get('/foretag/{company}', [CompanyController::class, 'show'])->whereNumber('company')->name('teams.show');
    Route::get('/foretag/{company}/logotyp', CompanyLogoController::class)->whereNumber('company')->name('teams.logo');
    // The logo of the accounting firm the company shows its people (ADR-030).
    Route::get('/foretag/{company}/byrans-logotyp', [FirmLogoController::class, 'member'])->whereNumber('company')->name('teams.firm-logo');
    Route::put('/aktuellt-foretag', [CompanyController::class, 'switch'])->name('current-team.update');

    // The encrypted chat (ADR-045): technical JSON endpoints, any signed-in user, the room id only in the POST body.
    Route::prefix('chatt')->name('chat.')->middleware('throttle:chat')->controller(SecureChatController::class)->group(function () {
        Route::post('/starta', 'start')->name('start');
        Route::post('/anslut', 'join')->name('join');
        Route::post('/hamta', 'poll')->name('poll');
        Route::post('/skicka', 'send')->name('send');
        Route::post('/avsluta', 'end')->name('end');
        Route::post('/mina-foretag', 'companies')->name('companies');
        Route::post('/kan-folja', 'followable')->name('followable');
    });
    Route::view('/mina-foretag', 'companies.overview')->name('companies.overview');
    // The user's own groups of companies, for internal reporting (ADR-060); spans companies like Mina företag.
    Route::view('/koncern', 'group.overview')->name('group.overview');
    Route::get('/koncern/{group}/csv', GroupCsvController::class)->whereNumber('group')->name('group.csv');
    // Across every company the user belongs to (ADR-028).
    Route::view('/uppgifter', 'tasks.index')->name('tasks.index');
    Route::redirect('/mina-uppgifter', '/uppgifter');
    Route::view('/notiser', 'notifications.index')->name('notifications.index');

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
        Route::get('/registerutdrag/personal/{person}', [DataSubjectExportController::class, 'staffLedgerPerson'])->whereNumber('person')->name('privacy.export.staffLedgerPerson');
        Route::get('/registerutdrag/delagare/{incomeTaxPartner}', [DataSubjectExportController::class, 'incomeTaxPartner'])->whereNumber('incomeTaxPartner')->name('privacy.export.incomeTaxPartner');
        Route::get('/registerutdrag/k10-agare/{k10Owner}', [DataSubjectExportController::class, 'k10Owner'])->whereNumber('k10Owner')->name('privacy.export.k10Owner');
        Route::get('/registerutdrag/inkorg', [DataSubjectExportController::class, 'inboxSender'])->name('privacy.export.inboxSender');
        Route::put('/oversikt/kom-igang', [GettingStartedController::class, 'update'])->name('dashboard.gettingStarted');
        Route::post('/sok', SearchController::class)->middleware('throttle:search')->name('search');
        Route::post('/sok/senaste', RecentItemController::class)->middleware('throttle:search')->name('search.recent');

        // Tasks in the current company (ADR-028).
        Route::view('/uppgifter/aterkommande', 'tasks.recurring')->name('tasks.recurring');
        Route::get('/uppgifter/{task}', TaskController::class)->whereNumber('task')->name('tasks.show');

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
        // The PDF sent for signing, or its certificate (ADR-046); core since ADR-075 §7, so it works with CRM off.
        Route::get('/kunder/signeringar/{signing}/{file}', SigningFileController::class)->whereNumber('signing')->whereIn('file', ['dokument', 'signeringsbevis'])->name('signing.file');
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
        Route::view('/reskontra', 'accounting.ledgers')->name('accounting.ledgers');
        Route::view('/moms', 'vat.index')->name('vat.index');
        Route::get('/moms/{fiscalYear}/fil', VatFileController::class)->whereNumber('fiscalYear')->name('vat.file');
        Route::view('/eu-rapporter', 'vat.eu-reports')->name('vat.euReports');
        Route::get('/anslutningar/skatteverket', [SkatteverketAuthController::class, 'start'])->middleware('throttle:skatteverket-sign-in')->name('skatteverket.signIn');
        Route::get('/anslutningar/skatteverket/klar', [SkatteverketAuthController::class, 'callback'])->middleware('throttle:skatteverket-sign-in')->name('skatteverket.callback');
        Route::get('/rapporter/csv', ReportCsvController::class)->name('accounting.reports.csv');
        Route::view('/kostnadsstallen', 'accounting.dimensions', ['dimension' => 'kostnadsstallen'])->name('accounting.costCenters');
        Route::view('/projekt', 'accounting.dimensions', ['dimension' => 'projekt'])->name('accounting.projects');
        Route::view('/anlaggningar', 'accounting.fixed-assets')->name('accounting.fixedAssets');
        Route::view('/periodiseringar', 'accounting.accruals')->name('accounting.accruals');
        Route::view('/korjournal', 'mileage.index')->name('mileage.index');
        Route::view('/bankavstamning', 'accounting.bank-reconciliation')->name('accounting.bankReconciliation');
        Route::view('/skattekontot', 'accounting.tax-account')->name('accounting.taxAccount');
        Route::view('/bokslut', 'accounting.closing')->name('accounting.closing');
        Route::view('/bokslut/uppskjuten-skatt', 'accounting.deferred-tax')->name('accounting.closing.deferredTax');
        Route::view('/bokslut/overgang-till-k3', 'accounting.k3-transition')->name('accounting.closing.k3Transition');
        Route::view('/bokslut/regelverk', 'accounting.closing-framework')->name('accounting.closingFramework');
        Route::view('/arsredovisning', 'accounting.annual-report')->name('accounting.annualReport');
        Route::get('/arsredovisning/{fiscalYear}', [AnnualReportController::class, 'show'])->whereNumber('fiscalYear')->name('accounting.annualReport.show');
        Route::get('/arsredovisning/{fiscalYear}/pdf', [AnnualReportController::class, 'pdf'])->whereNumber('fiscalYear')->name('accounting.annualReport.pdf');
        Route::get('/arsredovisning/{fiscalYear}/ixbrl', [AnnualReportController::class, 'ixbrl'])->whereNumber('fiscalYear')->name('accounting.annualReport.ixbrl');
        Route::view('/inkomstdeklaration', 'accounting.income-tax')->name('accounting.incomeTax');
        Route::get('/inkomstdeklaration/{fiscalYear}/sru', IncomeTaxSruController::class)->whereNumber('fiscalYear')->name('accounting.incomeTax.sru');
        Route::view('/inkomstdeklaration/k10', 'accounting.k10')->name('accounting.k10');
        Route::get('/inkomstdeklaration/k10/{k10Owner}', [K10Controller::class, 'show'])->whereNumber('k10Owner')->name('accounting.k10.show');
        Route::get('/inkomstdeklaration/k10/{k10Owner}/pdf', [K10Controller::class, 'pdf'])->whereNumber('k10Owner')->name('accounting.k10.pdf');
        Route::get('/inkomstdeklaration/k10/{k10Owner}/sru', [K10Controller::class, 'sru'])->whereNumber('k10Owner')->name('accounting.k10.sru');
        Route::get('/importera-sie', [SieImportController::class, 'index'])->name('accounting.sieImports.index');
        Route::post('/importera-sie', [SieImportController::class, 'store'])->name('accounting.sieImports.store');
        Route::get('/importera-sie/{sieImport}', [SieImportController::class, 'show'])
            ->whereNumber('sieImport')
            ->name('accounting.sieImports.show');
    });
});
