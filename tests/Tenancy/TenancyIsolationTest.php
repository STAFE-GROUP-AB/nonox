<?php

declare(strict_types=1);

use App\Models\Account;
use App\Models\Article;
use App\Models\Company;
use App\Models\CostCenter;
use App\Models\Customer;
use App\Models\FiscalYear;
use App\Models\ImportedVoucher;
use App\Models\Invoice;
use App\Models\InvoicePayment;
use App\Models\InvoiceReminder;
use App\Models\InvoiceRow;
use App\Models\NumberSeries;
use App\Models\OpeningBalance;
use App\Models\Project;
use App\Models\SalesDocument;
use App\Models\SalesDocumentRow;
use App\Models\SieImport;
use App\Models\Supplier;
use App\Models\SupplierInvoice;
use App\Models\SupplierInvoiceRow;
use App\Models\SupplierPayment;
use App\Models\SupplierPaymentBatch;
use App\Models\SupplierPaymentBatchItem;
use App\Models\User;
use App\Models\VatReport;
use App\Models\Voucher;
use App\Models\VoucherAttachment;
use App\Models\VoucherNumberCounter;
use App\Models\VoucherRow;
use App\Models\VoucherRowHistory;
use App\Models\VoucherSeries;
use App\Support\Tenancy\CompanyScope;
use App\Support\Tenancy\CurrentCompany;
use App\Support\Tenancy\MissingCompanyContext;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Tests\Tenancy\Fixtures\ListNotesForCompany;
use Tests\Tenancy\Fixtures\TenantNote;

/*
 * AGENTS.md §13.2: every company-scoped model is registered here with the
 * attributes needed to create one. The test at the bottom fails when a model
 * uses BelongsToCompany without being listed.
 */
const COMPANY_SCOPED_MODELS = [
    TenantNote::class => ['title' => 'Hemlig anteckning'],
    Account::class => ['number' => 1930, 'name' => 'Företagskonto', 'type' => 'ASSET'],
    FiscalYear::class => ['start_date' => '2026-01-01', 'end_date' => '2026-12-31'],
    VoucherSeries::class => ['code' => 'A', 'name' => 'Manuell'],
    CostCenter::class => ['code' => '10', 'name' => 'Restaurang Malmö'],
    Project::class => ['code' => 'P1', 'name' => 'Ombyggnad kök'],
    // Records that need other records of the same company get a closure.
    Voucher::class => 'voucherAttributes',
    VoucherRow::class => 'voucherRowAttributes',
    VoucherNumberCounter::class => 'counterAttributes',
    VoucherAttachment::class => 'attachmentAttributes',
    OpeningBalance::class => 'openingBalanceAttributes',
    SieImport::class => 'sieImportAttributes',
    ImportedVoucher::class => 'importedVoucherAttributes',
    VoucherRowHistory::class => 'voucherRowHistoryAttributes',
    VatReport::class => 'vatReportAttributes',
    NumberSeries::class => ['key' => 'invoices', 'next_number' => 1],
    Customer::class => ['number' => 1, 'name' => 'Hemlig kund AB', 'payment_terms_days' => 30],
    Article::class => 'articleAttributes',
    Invoice::class => 'invoiceAttributes',
    InvoiceRow::class => 'invoiceRowAttributes',
    InvoicePayment::class => 'invoicePaymentAttributes',
    SalesDocument::class => 'salesDocumentAttributes',
    SalesDocumentRow::class => 'salesDocumentRowAttributes',
    InvoiceReminder::class => 'reminderAttributes',
    Supplier::class => ['number' => 1, 'name' => 'Hemlig leverantör AB', 'payment_terms_days' => 30],
    SupplierInvoice::class => 'supplierInvoiceAttributes',
    SupplierInvoiceRow::class => 'supplierInvoiceRowAttributes',
    SupplierPayment::class => 'supplierPaymentAttributes',
    SupplierPaymentBatch::class => 'paymentBatchAttributes',
    SupplierPaymentBatchItem::class => 'paymentBatchItemAttributes',
];

/** Runs inside the company's context. */
function voucherAttributes(): array
{
    return [
        'fiscal_year_id' => FiscalYear::query()->firstOrCreate(['start_date' => '2026-01-01'], ['end_date' => '2026-12-31'])->id,
        'series_id' => VoucherSeries::query()->firstOrCreate(['code' => 'A'], ['name' => 'Manuell'])->id,
        'voucher_date' => '2026-03-01',
        'description' => 'Hemlig verifikation',
    ];
}

function voucherRowAttributes(): array
{
    return [
        'voucher_id' => Voucher::create(voucherAttributes())->id,
        'account_id' => Account::query()->firstOrCreate(['number' => 1930], ['name' => 'Bank', 'type' => 'ASSET'])->id,
        'debit_ore' => 100,
        'credit_ore' => 0,
    ];
}

function openingBalanceAttributes(): array
{
    return [
        'fiscal_year_id' => FiscalYear::query()->firstOrCreate(['start_date' => '2026-01-01'], ['end_date' => '2026-12-31'])->id,
        'account_id' => Account::query()->firstOrCreate(['number' => 1930], ['name' => 'Bank', 'type' => 'ASSET'])->id,
        'amount_ore' => 100,
    ];
}

function sieImportAttributes(): array
{
    return [
        'user_id' => User::factory()->create()->id,
        'original_name' => 'SIE4 2026.se',
        'file_path' => 'sie-import/test.se',
        'size' => 100,
        'sha256' => str_repeat('0', 64),
        'report' => [],
    ];
}

function importedVoucherAttributes(): array
{
    $voucher = Voucher::create(voucherAttributes());

    return [
        'sie_import_id' => SieImport::create(sieImportAttributes())->id,
        'voucher_id' => $voucher->id,
        'fiscal_year_id' => $voucher->fiscal_year_id,
        'original_series' => 'A',
        'original_number' => (string) random_int(1, 1_000_000),
    ];
}

function voucherRowHistoryAttributes(): array
{
    return [
        'voucher_id' => Voucher::create(voucherAttributes())->id,
        'change' => 'REMOVED',
        'position' => 0,
        'account_number' => 1930,
        'amount_ore' => 100,
    ];
}

function vatReportAttributes(): array
{
    return [
        'period_start' => '2026-0'.random_int(1, 9).'-01',
        'period_end' => '2026-01-31',
        'boxes' => [49 => 0],
        'voucher_id' => Voucher::create(voucherAttributes())->id,
        'created_by' => User::factory()->create()->id,
    ];
}

function articleAttributes(): array
{
    return [
        'number' => 'A'.random_int(1, 1_000_000),
        'name' => 'Hemlig artikel',
        'price_ore' => 100,
        'vat_rate' => 2500,
        'account_id' => Account::query()->firstOrCreate(['number' => 3001], ['name' => 'Försäljning', 'type' => 'REVENUE'])->id,
    ];
}

function invoiceAttributes(): array
{
    return [
        'customer_id' => Customer::query()->firstOrCreate(['number' => 1], ['name' => 'Hemlig kund AB'])->id,
        'invoice_date' => '2026-03-01',
        'due_date' => '2026-03-31',
    ];
}

function invoiceRowAttributes(): array
{
    return [
        'invoice_id' => Invoice::create(invoiceAttributes())->id,
        'description' => 'Hemlig rad',
        'quantity' => '1',
        'unit_price_ore' => 100,
        'vat_rate' => 2500,
        'account_id' => articleAttributes()['account_id'],
        'net_ore' => 100,
        'position' => 0,
    ];
}

function invoicePaymentAttributes(): array
{
    return [
        'invoice_id' => Invoice::create(invoiceAttributes())->id,
        'paid_on' => '2026-03-10',
        'amount_ore' => 100,
        'account_id' => Account::query()->firstOrCreate(['number' => 1930], ['name' => 'Bank', 'type' => 'ASSET'])->id,
        'voucher_id' => Voucher::create(voucherAttributes())->id,
        'created_by' => User::factory()->create()->id,
    ];
}

function supplierInvoiceAttributes(): array
{
    return [
        'supplier_id' => Supplier::query()->firstOrCreate(['number' => 1], ['name' => 'Hemlig leverantör AB'])->id,
        'invoice_date' => '2026-03-01',
        'due_date' => '2026-03-31',
    ];
}

function supplierInvoiceRowAttributes(): array
{
    return [
        'supplier_invoice_id' => SupplierInvoice::create(supplierInvoiceAttributes())->id,
        'account_id' => Account::query()->firstOrCreate(['number' => 4010], ['name' => 'Inköp', 'type' => 'EXPENSE'])->id,
        'amount_ore' => 100,
        'position' => 1,
    ];
}

function supplierPaymentAttributes(): array
{
    return [
        'supplier_invoice_id' => SupplierInvoice::create(supplierInvoiceAttributes())->id,
        'paid_on' => '2026-03-10',
        'amount_ore' => 100,
        'account_id' => Account::query()->firstOrCreate(['number' => 1930], ['name' => 'Bank', 'type' => 'ASSET'])->id,
        'voucher_id' => Voucher::create(voucherAttributes())->id,
        'created_by' => User::factory()->create()->id,
    ];
}

function paymentBatchAttributes(): array
{
    return [
        'number' => random_int(1, 1_000_000),
        'bank' => 'NORDEA',
        'execution_date' => '2026-03-12',
        'debtor_iban' => 'SE4550000000058398257466',
        'debtor_bic' => 'NDEASESS',
        'message_id' => 'NONOX-'.random_int(1, 1_000_000),
        'payment_count' => 1,
        'total_ore' => 100,
        'file_path' => 'betalfiler/hemlig.xml',
        'file_sha256' => str_repeat('0', 64),
        'created_by' => User::factory()->create()->id,
    ];
}

function paymentBatchItemAttributes(): array
{
    return [
        'batch_id' => SupplierPaymentBatch::create(paymentBatchAttributes())->id,
        'supplier_invoice_id' => SupplierInvoice::create(supplierInvoiceAttributes())->id,
        'amount_ore' => 100,
        'end_to_end_id' => 'LF1',
    ];
}

function salesDocumentAttributes(): array
{
    return [
        'kind' => 'QUOTE',
        'number' => random_int(1, 1_000_000),
        'customer_id' => Customer::query()->firstOrCreate(['number' => 1], ['name' => 'Hemlig kund AB'])->id,
        'document_date' => '2026-04-01',
    ];
}

function salesDocumentRowAttributes(): array
{
    return [...array_diff_key(invoiceRowAttributes(), ['invoice_id' => true]), 'sales_document_id' => SalesDocument::create(salesDocumentAttributes())->id];
}

function reminderAttributes(): array
{
    return [
        'invoice_id' => Invoice::create(invoiceAttributes())->id,
        'sequence' => 1,
        'sent_on' => '2026-04-01',
        'pay_by' => '2026-04-11',
        'remaining_ore' => 100,
        'sent_to' => 'kund@exempel.test',
        'created_by' => User::factory()->create()->id,
    ];
}

function attachmentAttributes(): array
{
    return [
        'voucher_id' => Voucher::create(voucherAttributes())->id,
        'file_path' => 'underlag/test.pdf',
        'original_name' => 'kvitto.pdf',
        'mime' => 'application/pdf',
        'size' => 100,
        'sha256' => str_repeat('0', 64),
    ];
}

function counterAttributes(): array
{
    $voucher = voucherAttributes();

    return ['fiscal_year_id' => $voucher['fiscal_year_id'], 'series_id' => $voucher['series_id'], 'next_number' => 1];
}

/** @return array<string, mixed> */
function attributesFor(string $model): array
{
    $attributes = COMPANY_SCOPED_MODELS[$model];

    return is_string($attributes) ? $attributes() : $attributes;
}

/** Models whose rows may never be updated; the bulk test only deletes them. */
const NEVER_UPDATED = [VoucherAttachment::class];

/** Models that can only be added to: the database refuses bulk changes too. */
const APPEND_ONLY = [ImportedVoucher::class, VoucherRowHistory::class, VatReport::class, InvoicePayment::class, InvoiceReminder::class, SupplierPayment::class, SupplierPaymentBatch::class, SupplierPaymentBatchItem::class];

/** Test-only models that are not part of the application. */
const TENANCY_FIXTURES = [TenantNote::class];

beforeEach(function () {
    TenantNote::createTable();

    $this->companyA = Company::factory()->create(['name' => 'Företag A']);
    $this->companyB = Company::factory()->create(['name' => 'Företag B']);
});

/**
 * @param  class-string<Model>  $model
 */
function createFor(Company $company, string $model): Model
{
    return CurrentCompany::run($company, fn () => $model::create(attributesFor($model)));
}

dataset('company-scoped models', fn () => array_keys(COMPANY_SCOPED_MODELS));

it('fills in the current company when a record is created', function (string $model) {
    $record = createFor($this->companyA, $model);

    expect($record->company_id)->toBe($this->companyA->id);
})->with('company-scoped models');

it('never shows another company\'s records', function (string $model) {
    $own = createFor($this->companyA, $model);
    $foreign = createFor($this->companyB, $model);

    CurrentCompany::set($this->companyA);

    expect($model::all()->modelKeys())->toBe([$own->getKey()])
        ->and($model::count())->toBe(1)
        ->and($model::find($foreign->getKey()))->toBeNull()
        ->and($model::whereKey($foreign->getKey())->exists())->toBeFalse()
        ->and(fn () => $model::findOrFail($foreign->getKey()))->toThrow(ModelNotFoundException::class);
})->with('company-scoped models');

it('shows nothing and refuses to create when no company is known', function (string $model) {
    createFor($this->companyA, $model);

    CurrentCompany::set(null);

    expect($model::count())->toBe(0)
        ->and(fn () => $model::create(CurrentCompany::run($this->companyA, fn () => attributesFor($model))))->toThrow(MissingCompanyContext::class)
        ->and(fn () => $model::create([...CurrentCompany::run($this->companyB, fn () => attributesFor($model)), 'company_id' => $this->companyB->id]))->toThrow(MissingCompanyContext::class);
})->with('company-scoped models');

it('refuses to create a record for another company', function (string $model) {
    CurrentCompany::set($this->companyA);

    $attributes = CurrentCompany::run($this->companyA, fn () => attributesFor($model));

    expect(fn () => $model::create([...$attributes, 'company_id' => $this->companyB->id]))
        ->toThrow(MissingCompanyContext::class);
})->with('company-scoped models');

it('refuses to move a record to another company', function (string $model) {
    $record = createFor($this->companyA, $model);

    CurrentCompany::set($this->companyA);
    $record->company_id = $this->companyB->id;

    expect(fn () => $record->save())->toThrow(MissingCompanyContext::class);
    expect(CompanyScope::bypass(fn () => $model::find($record->getKey())->company_id))->toBe($this->companyA->id);
})->with('company-scoped models');

it('limits bulk updates and deletes to the current company', function (string $model) {
    $own = createFor($this->companyA, $model);
    $foreign = createFor($this->companyB, $model);

    CurrentCompany::set($this->companyA);

    if (in_array($model, APPEND_ONLY, true)) {
        expect(fn () => DB::transaction(fn () => $model::query()->delete()))->toThrow(QueryException::class)
            ->and(CompanyScope::bypass(fn () => $model::all()->modelKeys()))->toEqualCanonicalizing([$own->getKey(), $foreign->getKey()]);

        return;
    }

    if (! in_array($model, NEVER_UPDATED, true)) {
        $model::query()->update(['updated_at' => now()->addDay()]);
    }

    $model::query()->delete();

    $remaining = CompanyScope::bypass(fn () => $model::all()->modelKeys());

    expect($remaining)->toBe([$foreign->getKey()])
        ->and($remaining)->not->toContain($own->getKey());
})->with('company-scoped models');

it('only lets an explicit bypass see every company', function (string $model) {
    createFor($this->companyA, $model);
    createFor($this->companyB, $model);

    CurrentCompany::set($this->companyA);

    expect($model::count())->toBe(1)
        ->and(CompanyScope::bypass(fn () => $model::count()))->toBe(2)
        ->and($model::count())->toBe(1);
})->with('company-scoped models');

it('runs queued jobs only inside the company they were dispatched for', function (string $model) {
    createFor($this->companyA, $model);
    createFor($this->companyB, $model);

    CurrentCompany::set($this->companyB);
    ListNotesForCompany::$seenCompanyIds = [];

    ListNotesForCompany::dispatch($this->companyA->id, $model);

    expect(ListNotesForCompany::$seenCompanyIds)->toBe([$this->companyA->id])
        ->and(CurrentCompany::id())->toBe($this->companyB->id);
})->with('company-scoped models');

it('answers 404, not 403, when a user opens another company\'s record by id', function (string $model) {
    $foreign = createFor($this->companyB, $model);
    $own = createFor($this->companyA, $model);

    Route::middleware(['web', 'auth'])->get('/tenancy-test/{record}', fn (string $record) => $model::findOrFail($record)->getKey());

    $user = User::factory()->create();
    $this->companyA->users()->attach($user, ['role' => 'admin']);
    $user->switchCompany($this->companyA->fresh());

    $this->actingAs($user->fresh())->get("/tenancy-test/{$foreign->getKey()}")->assertNotFound();
    $this->actingAs($user->fresh())->get("/tenancy-test/{$own->getKey()}")->assertOk();
})->with('company-scoped models');

it('lists every model that uses BelongsToCompany', function () {
    $usingTrait = [];

    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator(app_path())) as $file) {
        if ($file->getExtension() !== 'php') {
            continue;
        }

        $source = (string) file_get_contents($file->getPathname());

        if (! preg_match('/^\s*use BelongsToCompany;/m', $source)) {
            continue;
        }

        preg_match('/^namespace (.+);/m', $source, $namespace);
        preg_match('/^(?:final |abstract )?class (\w+)/m', $source, $class);

        $usingTrait[] = $namespace[1].'\\'.$class[1];
    }

    $registered = array_values(array_diff(array_keys(COMPANY_SCOPED_MODELS), TENANCY_FIXTURES));

    sort($usingTrait);
    sort($registered);

    expect($usingTrait)->toBe($registered, 'Every model using BelongsToCompany must be listed in COMPANY_SCOPED_MODELS.');
});
