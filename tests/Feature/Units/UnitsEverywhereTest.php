<?php

declare(strict_types=1);

use App\Actions\Invoicing\CreditInvoice;
use App\Actions\Invoicing\IssueInvoice;
use App\Actions\Invoicing\SaveArticle;
use App\Actions\Invoicing\SaveDraftInvoice;
use App\Actions\Invoicing\SaveSalesDocument;
use App\Actions\Units\SaveUnit;
use App\Actions\Units\SetRowDefaultUnit;
use App\Actions\Units\SetUnitArchived;
use App\Domain\Units\UnitBackfill;
use App\Domain\Units\UnitCatalogue;
use App\Livewire\Invoicing\InvoiceEditor;
use App\Livewire\Invoicing\Units as UnitsPage;
use App\Migration\Import\RegisterKind;
use App\Models\Company;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\InvoiceRow;
use App\Models\SalesDocument;
use App\Models\Unit;
use App\Models\User;
use App\Modules\Crm\Actions\CreateQuoteFromTemplate;
use App\Modules\Crm\Actions\SaveQuoteTemplate;
use App\Modules\Crm\Models\QuoteTemplateRow;
use App\Modules\WorkOrders\Actions\RecordMaterial;
use App\Modules\WorkOrders\Models\MaterialEntry;
use App\Support\Tenancy\CurrentCompany;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Tests\Support\Bookkeeping;
use Tests\Support\Connect;
use Tests\Support\Crm;
use Tests\Support\RegisterImports;
use Tests\Support\WorkOrders;

/*
 * The company's unit register as the only source of units (ADR-071
 * §12.9): invoice, quote, order, template and work order rows point at a
 * unit of the register, free text is refused, archived units stay on the
 * rows that have them, importers add what is missing, and the old rows are
 * linked by the migration without changing what they print.
 */

beforeEach(function () {
    [$this->owner, $this->company] = Bookkeeping::company();
    $this->actingAs($this->owner);
    CurrentCompany::set($this->company);
    UnitCatalogue::seed((int) $this->company->id);
    $this->customer = Crm::customer($this->company, $this->owner);
});

function everywhereUnit(string $code): Unit
{
    return Unit::query()->where('code', $code)->firstOrFail();
}

/** @param  list<array<string, mixed>>  $rows */
function everywhereDraft(User $owner, Company $company, Customer $customer, array $rows, ?Invoice $draft = null): Invoice
{
    return app(SaveDraftInvoice::class)($owner, $company, ['customer_id' => $customer->id, 'invoice_date' => '2026-03-02', 'rows' => $rows], $draft);
}

it('stores the unit of a row by id, with its code as the printed text', function () {
    $invoice = everywhereDraft($this->owner, $this->company, $this->customer, [
        ['description' => 'Golvlist', 'quantity' => '12', 'unit_id' => everywhereUnit('lpm')->id, 'price' => '40', 'vat_rate' => 2500],
    ]);

    expect($invoice->rows()->sole())->unit_id->toBe(everywhereUnit('lpm')->id)->unit->toBe('lpm');
});

it('takes a code or alias of the register, keeps the spelling, and refuses any other text', function () {
    $invoice = everywhereDraft($this->owner, $this->company, $this->customer, [
        ['description' => 'Arbete', 'quantity' => '2', 'unit' => 'tim', 'price' => '600', 'vat_rate' => 2500],
        ['description' => 'Skivor', 'quantity' => '3', 'unit' => 'm2', 'price' => '100', 'vat_rate' => 2500],
    ]);

    expect($invoice->rows()->orderBy('position')->get()->map(fn (InvoiceRow $row): array => [$row->unit_id, $row->unit])->all())
        ->toBe([[everywhereUnit('h')->id, 'tim'], [everywhereUnit('m²')->id, 'm2']]);

    expect(fn () => everywhereDraft($this->owner, $this->company, $this->customer, [
        ['description' => 'Lunch', 'quantity' => '1', 'unit' => 'pers', 'price' => '100', 'vat_rate' => 1200],
    ]))->toThrow(ValidationException::class, 'Rad 1: Enheten pers finns inte i enhetslistan.');
});

it('gives a row without a unit the article\'s sales unit, or the company\'s default unit', function () {
    app(SaveArticle::class)($this->owner, $this->company, ['number' => 'KONS', 'name' => 'Konsulttimme', 'unit' => 'tim', 'price' => '950', 'vat_rate' => 2500]);
    app(SetRowDefaultUnit::class)($this->owner, $this->company, everywhereUnit('h')->id);

    $invoice = everywhereDraft($this->owner, $this->company, $this->customer, [
        ['article' => 'KONS', 'description' => 'Konsulttimme', 'quantity' => '1', 'price' => '950', 'vat_rate' => 2500],
        ['description' => 'Fri rad', 'quantity' => '1', 'price' => '10', 'vat_rate' => 2500],
    ]);
    $rows = $invoice->rows()->orderBy('position')->get();

    // The article was written "tim", so its rows print "tim" as before; the free row gets the default.
    expect($rows[0])->unit_id->toBe(everywhereUnit('h')->id)->unit->toBe('tim')
        ->and($rows[1])->unit_id->toBe(everywhereUnit('h')->id)->unit->toBe('h');
});

it('refuses another company\'s unit without naming it', function () {
    [, $other] = Bookkeeping::company();
    UnitCatalogue::seed((int) $other->id);
    $foreign = CurrentCompany::run($other, fn () => Unit::query()->where('code', 'kg')->firstOrFail());

    try {
        everywhereDraft($this->owner, $this->company, $this->customer, [
            ['description' => 'Mjöl', 'quantity' => '1', 'unit_id' => $foreign->id, 'price' => '10', 'vat_rate' => 1200],
        ]);
        $this->fail('Another company\'s unit was accepted.');
    } catch (ValidationException $exception) {
        expect($exception->errors())->toHaveKey('rows.0.unit_id')
            ->and($exception->errors()['rows.0.unit_id'][0])->toBe('Rad 1: Enheten finns inte i företagets enhetslista.');
    }
});

it('keeps an archived unit on the rows that have it, and refuses it on a new row in the editor', function () {
    $draft = everywhereDraft($this->owner, $this->company, $this->customer, [
        ['description' => 'Pall', 'quantity' => '1', 'unit_id' => everywhereUnit('set')->id, 'price' => '10', 'vat_rate' => 2500],
    ]);
    app(SetUnitArchived::class)($this->owner, $this->company, everywhereUnit('set')->id, true);

    // The row that had it is still shown and saved.
    Livewire::test(InvoiceEditor::class, ['invoice' => $draft])
        ->assertSee('set (arkiverad)')
        ->call('save')
        ->assertHasNoErrors();

    expect($draft->rows()->sole()->unit_id)->toBe(everywhereUnit('set')->id);

    // A new document cannot choose it.
    Livewire::test(InvoiceEditor::class)
        ->set('customerId', (string) $this->customer->id)
        ->set('rows', [['article' => '', 'description' => 'Ny', 'quantity' => '1', 'unit_id' => (string) everywhereUnit('set')->id, 'price' => '5', 'discount' => '', 'vat_rate' => '2500', 'account' => '']])
        ->call('save')
        ->assertHasErrors(['rows.0.unit_id'])
        ->assertDontSee('set (arkiverad)');
});

it('credits an invoice whose unit has since been archived', function () {
    $invoice = app(IssueInvoice::class)($this->owner, everywhereDraft($this->owner, $this->company, $this->customer, [
        ['description' => 'Bunt', 'quantity' => '2', 'unit_id' => everywhereUnit('par')->id, 'price' => '50', 'vat_rate' => 2500],
    ]));
    app(SetUnitArchived::class)($this->owner, $this->company, everywhereUnit('par')->id, true);

    $credit = app(CreditInvoice::class)($this->owner, $invoice);

    expect($credit->rows()->sole())->unit_id->toBe(everywhereUnit('par')->id)->unit->toBe('par');
});

it('lets the company choose the default unit for new rows, never an archived one', function () {
    $page = Livewire::test(UnitsPage::class)->assertSee('Gör till standard');
    $page->call('makeRowDefault', everywhereUnit('h')->id)->assertHasNoErrors();

    expect(Unit::query()->where('row_default', true)->pluck('code')->all())->toBe(['h']);

    expect(fn () => app(SetUnitArchived::class)($this->owner, $this->company, everywhereUnit('h')->id, true))
        ->toThrow(ValidationException::class, 'Enheten h är standard för nya rader.');

    app(SetUnitArchived::class)($this->owner, $this->company, everywhereUnit('kg')->id, true);

    expect(fn () => app(SetRowDefaultUnit::class)($this->owner, $this->company, everywhereUnit('kg')->id))
        ->toThrow(ValidationException::class, 'Enheten kg är arkiverad');

    Livewire::test(InvoiceEditor::class)->assertSet('rows.0.unit_id', (string) everywhereUnit('h')->id);
});

it('saves quote template rows with units of the register, and makes quotes with them', function () {
    Crm::enable($this->company, $this->owner);
    app(SaveArticle::class)($this->owner, $this->company, ['number' => 'MÅL', 'name' => 'Målning', 'unit' => 'h', 'price' => '500', 'vat_rate' => 2500]);
    $roll = app(SaveUnit::class)($this->owner, $this->company, ['code' => 'rulle', 'name' => 'rulle', 'dimension' => 'OTHER']);

    $template = app(SaveQuoteTemplate::class)($this->owner, $this->company, ['name' => 'Målning', 'validity_days' => 30, 'rows' => [
        ['article' => 'MÅL', 'quantity' => '8'],
        ['description' => 'Tapet', 'quantity' => '3', 'unit_id' => (string) $roll->id, 'price' => '400', 'vat_rate' => 2500],
        ['description' => 'Framkörning', 'quantity' => '1', 'price' => '300', 'vat_rate' => 2500],
    ]]);
    $rows = $template->rows()->orderBy('position')->get();

    expect($rows->map(fn (QuoteTemplateRow $row): array => [$row->unit_id, $row->unit])->all())
        ->toBe([[null, null], [$roll->id, 'rulle'], [everywhereUnit('st')->id, 'st']]);

    expect(fn () => app(SaveQuoteTemplate::class)($this->owner, $this->company, ['name' => 'Fel', 'validity_days' => 30, 'rows' => [
        ['description' => 'X', 'quantity' => '1', 'unit' => 'kartong', 'price' => '1', 'vat_rate' => 2500],
    ]]))->toThrow(ValidationException::class, 'Rad 1: Enheten kartong finns inte i enhetslistan.');

    $quote = app(CreateQuoteFromTemplate::class)($this->owner, $this->company, $template, $this->customer->id);

    expect($quote->rows()->orderBy('position')->get()->map(fn ($row): array => [$row->unit_id, $row->unit])->all())
        ->toBe([[everywhereUnit('h')->id, 'h'], [$roll->id, 'rulle'], [everywhereUnit('st')->id, 'st']]);
});

it('records work order material in a unit of the register', function () {
    WorkOrders::enable($this->company, $this->owner);
    $workOrder = WorkOrders::workOrder($this->company, $this->owner, WorkOrders::privateCustomer($this->company, $this->owner));
    $record = app(RecordMaterial::class);

    $entry = $record($this->owner, $this->company, $workOrder, ['source' => 'FREE', 'description' => 'Fogmassa', 'quantity' => '2', 'unit_id' => everywhereUnit('l')->id, 'work_date' => '2026-03-02']);

    expect($entry)->unit_id->toBe(everywhereUnit('l')->id)->unit->toBe('l');

    expect(fn () => $record($this->owner, $this->company, $workOrder, ['source' => 'FREE', 'description' => 'Kakel', 'quantity' => '2', 'unit' => 'kartong', 'work_date' => '2026-03-02']))
        ->toThrow(ValidationException::class, 'Enheten kartong finns inte i enhetslistan.');

    expect(MaterialEntry::query()->count())->toBe(1);
});

it('accepts a unit id or a code over the API, lists the register, and refuses unknown text with 422', function () {
    Connect::setUp();
    [$owner, $company] = Connect::company();
    $key = Connect::apiKey($owner, $company, ['invoices:view', 'invoices:create']);
    $customer = $this->withToken($key)->withHeader('Idempotency-Key', 'u-c')->postJson('/api/v1/customers', ['name' => 'Kunden AB', 'payment_terms_days' => 30])->assertCreated()->json('data.id');

    $units = $this->withToken($key)->getJson('/api/v1/units')->assertOk()->json('data');
    $hour = collect($units)->firstWhere('code', 'h');

    expect($hour)->not->toBeNull()
        ->and(collect($units)->firstWhere('is_default', true)['code'])->toBe('st');

    $this->withToken($key)->withHeader('Idempotency-Key', 'u-1')->postJson('/api/v1/invoices', ['customer_id' => $customer, 'invoice_date' => '2026-03-02', 'rows' => [
        ['description' => 'Arbete', 'quantity' => 2, 'unit_id' => $hour['id'], 'unit_price_ore' => 60000, 'vat_rate' => 2500],
        ['description' => 'Material', 'quantity' => 1, 'unit' => 'kvm', 'unit_price_ore' => 10000, 'vat_rate' => 2500],
    ]])->assertCreated()
        ->assertJsonPath('data.rows.0.unit_id', $hour['id'])
        ->assertJsonPath('data.rows.0.unit', 'h')
        ->assertJsonPath('data.rows.1.unit', 'kvm');

    $this->withToken($key)->withHeader('Idempotency-Key', 'u-2')->postJson('/api/v1/invoices', ['customer_id' => $customer, 'invoice_date' => '2026-03-02', 'rows' => [
        ['description' => 'Lunch', 'quantity' => 1, 'unit' => 'pers', 'unit_price_ore' => 10000, 'vat_rate' => 1200],
    ]])->assertUnprocessable()->assertSee('rows.0.unit_id')->assertSee('finns inte i enhetslistan');

    $foreign = CurrentCompany::run($this->company, fn () => everywhereUnit('kg')->id);

    $this->withToken($key)->withHeader('Idempotency-Key', 'u-3')->postJson('/api/v1/articles', ['number' => 'A1', 'name' => 'Mjöl', 'unit_id' => $foreign, 'price_ore' => 100, 'vat_rate' => 1200])
        ->assertUnprocessable();
    $this->withToken($key)->withHeader('Idempotency-Key', 'u-4')->postJson('/api/v1/articles', ['number' => 'A2', 'name' => 'Mjöl', 'unit' => 'säck', 'price_ore' => 100, 'vat_rate' => 1200])
        ->assertUnprocessable();
});

it('adds the unit spellings an import brings and reports them, instead of failing', function () {
    $csv = "Nr;Namn;Enhet;Pris;Moms\n1;Tapet;rulle;400;25\n2;Lim;tim;100;25\n";
    $import = RegisterImports::run($this->owner, $this->company, RegisterKind::Articles, RegisterImports::contents($csv), 'nonox', [
        'mapping' => ['number' => 'Nr', 'name' => 'Namn', 'unit' => 'Enhet', 'price' => 'Pris', 'vat_rate' => 'Moms'],
    ]);

    expect($import->result)->toMatchArray(['created' => 2, 'error_count' => 0, 'units_created' => ['rulle']])
        ->and(everywhereUnit('rulle')->dimension->value)->toBe('OTHER');
});

it('links existing rows to the register without changing their text, and leaves issued invoices alone', function () {
    $draft = everywhereDraft($this->owner, $this->company, $this->customer, [
        ['description' => 'Ett', 'quantity' => '1', 'unit' => 'st', 'price' => '10', 'vat_rate' => 2500],
    ]);
    $issued = app(IssueInvoice::class)($this->owner, everywhereDraft($this->owner, $this->company, $this->customer, [
        ['description' => 'Två', 'quantity' => '1', 'unit' => 'st', 'price' => '10', 'vat_rate' => 2500],
    ]));

    // As before the register: a free spelling and no unit id. The issued row is left as it is.
    DB::table('invoice_rows')->where('invoice_id', $draft->id)->update(['unit' => 'Bunt', 'unit_id' => null]);

    UnitBackfill::rows('invoice_rows', updatable: fn ($query) => $query->whereIn('invoice_id', DB::table('invoices')->where('status', 'DRAFT')->select('id')));

    $bunt = everywhereUnit('Bunt');

    expect($draft->rows()->sole())->unit_id->toBe($bunt->id)->unit->toBe('Bunt')
        ->and($bunt->dimension->value)->toBe('OTHER')
        ->and($issued->rows()->sole()->unit)->toBe('st');
});

it('matches a long unit text cut to a code again instead of adding it twice', function () {
    $first = UnitCatalogue::resolve((int) $this->company->id, 'kubikdecimeter');
    $again = UnitCatalogue::resolve((int) $this->company->id, 'kubikdecimeter');

    expect($first)->toMatchArray(['code' => 'kubikdecim', 'created' => true])
        ->and($again)->toMatchArray(['id' => $first['id'], 'created' => false]);
});

it('asks for the units once, whatever the number of rows in the editor', function () {
    $count = function (Invoice $invoice): int {
        DB::flushQueryLog();
        DB::enableQueryLog();
        Livewire::test(InvoiceEditor::class, ['invoice' => $invoice])->assertOk();
        $queries = count(DB::getQueryLog());
        DB::disableQueryLog();

        return $queries;
    };
    $row = fn (int $i): array => ['description' => "Rad {$i}", 'quantity' => '1', 'unit_id' => everywhereUnit($i % 2 === 0 ? 'h' : 'st')->id, 'price' => '10', 'vat_rate' => 2500];

    $short = everywhereDraft($this->owner, $this->company, $this->customer, array_map($row, range(1, 2)));
    $long = everywhereDraft($this->owner, $this->company, $this->customer, array_map($row, range(1, 30)));
    $count($short);

    expect($count($long))->toBe($count($short));
});

it('offers the units of the register as a select on the rows, with a link to the register', function () {
    Livewire::test(InvoiceEditor::class)
        ->assertSee('Hantera enheter')
        ->assertSeeHtml('x-model="row.unit_id"')
        ->assertDontSeeHtml('x-model="row.unit"');
});

it('keeps quotes made from the editor on register units', function () {
    $quote = app(SaveSalesDocument::class)($this->owner, $this->company, SalesDocument::QUOTE, [
        'customer_id' => $this->customer->id, 'document_date' => '2026-03-02', 'until_date' => '2026-04-01',
        'rows' => [['description' => 'Skiva', 'quantity' => '2', 'unit' => 'kvm', 'price' => '100', 'vat_rate' => 2500]],
    ]);

    expect($quote->rows()->sole())->unit_id->toBe(everywhereUnit('m²')->id)->unit->toBe('kvm');
});
