<?php

declare(strict_types=1);

use App\Actions\Payables\ApproveSupplierInvoice;
use App\Actions\Payables\CreatePaymentBatch;
use App\Actions\Payables\OffsetSupplierCredit;
use App\Actions\Payables\PaySupplierInvoice;
use App\Actions\Payables\RegisterSupplierInvoice;
use App\Actions\Payables\SavePaymentBankSettings;
use App\Actions\Payables\SaveSupplier;
use App\Actions\Payables\SaveSupplierInvoice;
use App\Domain\Payables\SupplierInvoiceStatus;
use App\Livewire\Payables\SupplierInvoiceActions;
use App\Livewire\Payables\SupplierInvoiceEditor;
use App\Models\Account;
use App\Models\Company;
use App\Models\Supplier;
use App\Models\SupplierInvoice;
use App\Models\User;
use App\Models\Voucher;
use App\Modules\Ai\Agents\SupplierInvoiceAgent;
use App\Modules\Inbox\Actions\ApproveInboxMessage;
use App\Support\Money\Money;
use App\Support\Tenancy\CurrentCompany;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Tests\Support\Bookkeeping;
use Tests\Support\Inbox;

/*
 * Supplier invoices in another currency (ADR-025): booked in kronor at the
 * rate on the invoice, paid in their own currency, with the difference
 * between the kronor booked and the kronor paid on 3960 or 7960.
 */

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-03-10 10:00:00'));
    [$this->owner, $this->company] = Bookkeeping::company();
    CurrentCompany::set($this->company);
    $this->actingAs($this->owner);
    $this->company->forceFill(['separate_supplier_approval' => false])->save();
    $this->supplier = app(SaveSupplier::class)($this->owner, $this->company, ['name' => 'Molnet Exempel Inc.', 'payment_terms_days' => 30, 'default_account' => 6540]);
});

/** An approved invoice of $foreign USD at $rate, booked as $kronor on 6540. */
function dollarSupplierInvoice(User $user, Company $company, Supplier $supplier, string $foreign, string $rate, string $kronor, bool $credit = false, string $treatment = 'DOMESTIC'): SupplierInvoice
{
    $draft = app(SaveSupplierInvoice::class)($user, $company, [
        'supplier_id' => $supplier->id,
        'invoice_date' => '2026-03-05',
        'total' => $kronor,
        'vat' => '0',
        'credit' => $credit,
        'vat_treatment' => $treatment,
        'currency' => 'USD',
        'foreign_total' => $foreign,
        'exchange_rate' => $rate,
        'rows' => [['account' => '6540', 'amount' => $kronor, 'vat_rate' => '2500', 'description' => 'Servrar']],
    ]);

    return app(ApproveSupplierInvoice::class)($user, app(RegisterSupplierInvoice::class)($user, $draft))->fresh();
}

/** @return array<int, int> signed öre per account, debit positive */
function fxLines(Voucher $voucher): array
{
    $lines = [];

    foreach ($voucher->load('rows.account')->rows as $row) {
        $lines[$row->account->number] = ($lines[$row->account->number] ?? 0) + $row->debit_ore->ore - $row->credit_ore->ore;
    }

    ksort($lines);

    return $lines;
}

it('keeps the currency, the foreign total and the booking rate on the invoice', function () {
    $invoice = dollarSupplierInvoice($this->owner, $this->company, $this->supplier, '100', '10', '1000');

    expect($invoice->invoice_currency)->toBe('USD')
        ->and($invoice->foreign_total_minor)->toBe(10_000)
        ->and($invoice->exchange_rate_micros)->toBe(10_000_000)
        ->and($invoice->isForeign())->toBeTrue()
        ->and($invoice->foreignRemaining())->toBe(10_000)
        ->and($invoice->total_ore->ore)->toBe(100_000)
        ->and(fxLines($invoice->voucher))->toBe([2440 => -100_000, 6540 => 100_000]);

    $this->get(route('payables.invoices.index'))->assertOk()->assertSee('100,00 USD');
    $this->get(route('payables.invoices.show', $invoice))->assertOk()->assertSee('100,00 USD')->assertSee('kurs 10,000000');
});

it('books a loss on 7960 when the bank took more kronor than were booked', function () {
    $invoice = dollarSupplierInvoice($this->owner, $this->company, $this->supplier, '100', '10', '1000');

    $payment = app(PaySupplierInvoice::class)($this->owner, $invoice, CarbonImmutable::parse('2026-04-04'), Money::parse('1050'));

    expect(fxLines($payment->voucher))->toBe([1930 => -105_000, 2440 => 100_000, 7960 => 5_000])
        ->and($payment->voucher->series->code)->toBe('E')
        ->and($payment->amount_ore->ore)->toBe(105_000)
        ->and($payment->foreign_amount_minor)->toBe(10_000)
        ->and($payment->exchange_difference_ore)->toBe(5_000)
        ->and($invoice->fresh()->status)->toBe(SupplierInvoiceStatus::Paid)
        ->and($invoice->fresh()->paid_ore->ore)->toBe(100_000)
        ->and($invoice->fresh()->foreign_paid_minor)->toBe(10_000);
});

it('books a gain on 3960 when the bank took fewer kronor than were booked', function () {
    $invoice = dollarSupplierInvoice($this->owner, $this->company, $this->supplier, '100', '10', '1000');

    $payment = app(PaySupplierInvoice::class)($this->owner, $invoice, CarbonImmutable::parse('2026-04-04'), Money::parse('950'));

    expect(fxLines($payment->voucher))->toBe([1930 => -95_000, 2440 => 100_000, 3960 => -5_000])
        ->and($payment->voucher->rows->firstWhere('credit_ore.ore', 5_000)?->description)->toBe('Kursdifferens USD');
});

it('books no difference row when the rate is the same', function () {
    $invoice = dollarSupplierInvoice($this->owner, $this->company, $this->supplier, '100', '10', '1000');

    $payment = app(PaySupplierInvoice::class)($this->owner, $invoice, CarbonImmutable::parse('2026-04-04'), Money::parse('1000'));

    expect(fxLines($payment->voucher))->toBe([1930 => -100_000, 2440 => 100_000])
        ->and($payment->exchange_difference_ore)->toBe(0);
});

it('pays down the foreign amount in parts and clears the payables account to the öre', function () {
    // 100 USD at 9,82635 is 982,635 kr, booked as 982,64.
    $invoice = dollarSupplierInvoice($this->owner, $this->company, $this->supplier, '100', '9,82635', '982,64');
    $pay = app(PaySupplierInvoice::class);

    $first = $pay($this->owner, $invoice, CarbonImmutable::parse('2026-03-20'), Money::parse('340'), foreignAmount: 3_333);
    $invoice = $invoice->fresh();

    // 33,33 of 100 USD settles 98 264 × 3 333 / 10 000 = 32 751,39, so 32 751 öre.
    expect(fxLines($first->voucher))->toBe([1930 => -34_000, 2440 => 32_751, 7960 => 1_249])
        ->and($invoice->status)->toBe(SupplierInvoiceStatus::Approved)
        ->and($invoice->foreignRemaining())->toBe(6_667)
        ->and($invoice->remaining()->ore)->toBe(65_513);

    $second = $pay($this->owner, $invoice, CarbonImmutable::parse('2026-04-20'), Money::parse('640'));
    $invoice = $invoice->fresh();

    expect(fxLines($second->voucher))->toBe([1930 => -64_000, 2440 => 65_513, 3960 => -1_513])
        ->and($invoice->status)->toBe(SupplierInvoiceStatus::Paid)
        ->and($invoice->remaining()->ore)->toBe(0)
        ->and($invoice->foreignRemaining())->toBe(0);

    $payables = Account::query()->where('number', 2440)->value('id');
    $balance = Voucher::query()->whereIn('id', [$invoice->voucher_id, $first->voucher_id, $second->voucher_id])->with('rows')->get()
        ->flatMap->rows->where('account_id', $payables)->sum(fn ($row): int => $row->debit_ore->ore - $row->credit_ore->ore);

    expect($balance)->toBe(0);
});

it('refuses to pay more than is left in the foreign currency, and a foreign amount on an invoice in kronor', function () {
    $invoice = dollarSupplierInvoice($this->owner, $this->company, $this->supplier, '100', '10', '1000');

    expect(fn () => app(PaySupplierInvoice::class)($this->owner, $invoice, CarbonImmutable::parse('2026-04-04'), Money::parse('1100'), foreignAmount: 10_001))
        ->toThrow(ValidationException::class, 'Beloppet är större än det som återstår, 100,00 USD.');

    $kronor = app(SaveSupplierInvoice::class)($this->owner, $this->company, [
        'supplier_id' => $this->supplier->id, 'invoice_date' => '2026-03-05', 'total' => '500', 'vat' => '0',
        'rows' => [['account' => '6540', 'amount' => '500']],
    ]);
    $kronor = app(ApproveSupplierInvoice::class)($this->owner, app(RegisterSupplierInvoice::class)($this->owner, $kronor))->fresh();

    expect(fn () => app(PaySupplierInvoice::class)($this->owner, $kronor, CarbonImmutable::parse('2026-04-04'), Money::parse('500'), foreignAmount: 5_000))
        ->toThrow(ValidationException::class, 'Fakturan är i kronor.');

    $payment = app(PaySupplierInvoice::class)($this->owner, $kronor, CarbonImmutable::parse('2026-04-04'), Money::parse('500'));
    expect(fxLines($payment->voucher))->toBe([1930 => -50_000, 2440 => 50_000])
        ->and($payment->foreign_amount_minor)->toBeNull();
});

it('refuses a total in kronor that is not the foreign total at the rate', function () {
    expect(fn () => dollarSupplierInvoice($this->owner, $this->company, $this->supplier, '100', '10', '1200'))
        ->toThrow(ValidationException::class, '100,00 USD till kursen 10,000000 blir');

    expect(fn () => dollarSupplierInvoice($this->owner, $this->company, $this->supplier, '100', 'tio', '1000'))
        ->toThrow(ValidationException::class, 'Skriv kursen i kronor per USD');
});

it('settles a credit note refunded in dollars with the difference the right way round', function () {
    $credit = dollarSupplierInvoice($this->owner, $this->company, $this->supplier, '100', '10', '1000', credit: true);

    expect($credit->foreign_total_minor)->toBe(-10_000)
        ->and(fxLines($credit->voucher))->toBe([2440 => 100_000, 6540 => -100_000]);

    // 1 100 kr came back for a credit booked at 1 000 kr: a gain.
    $refund = app(PaySupplierInvoice::class)($this->owner, $credit, CarbonImmutable::parse('2026-04-04'), Money::parse('-1100'));

    expect(fxLines($refund->voucher))->toBe([1930 => 110_000, 2440 => -100_000, 3960 => -10_000])
        ->and($credit->fresh()->status)->toBe(SupplierInvoiceStatus::Paid);
});

it('books the costs at the booking rate and the difference at payment under kontantmetoden', function () {
    $this->company->forceFill(['accounting_method' => 'KONTANTMETODEN'])->save();
    $invoice = dollarSupplierInvoice($this->owner, $this->company, $this->supplier, '100', '10', '1000', treatment: 'NON_EU_SERVICES');

    expect($invoice->voucher_id)->toBeNull();

    $payment = app(PaySupplierInvoice::class)($this->owner, $invoice, CarbonImmutable::parse('2026-04-04'), Money::parse('1030'));

    expect(fxLines($payment->voucher))->toBe([1930 => -103_000, 2614 => -25_000, 2645 => 25_000, 4531 => 100_000, 7960 => 3_000]);
});

it('adds 3960 or 7960 from BAS when the chart lacks it', function () {
    Account::query()->where('number', 7960)->delete();
    $invoice = dollarSupplierInvoice($this->owner, $this->company, $this->supplier, '100', '10', '1000');

    $payment = app(PaySupplierInvoice::class)($this->owner, $invoice, CarbonImmutable::parse('2026-04-04'), Money::parse('1010'));

    expect(fxLines($payment->voucher))->toBe([1930 => -101_000, 2440 => 100_000, 7960 => 1_000])
        ->and(Account::query()->where('number', 7960)->value('name'))->toBe('Valutakursförluster på fordringar och skulder av rörelsekaraktär');
});

it('keeps invoices in another currency out of payment files and offsets', function () {
    $invoice = dollarSupplierInvoice($this->owner, $this->company, $this->supplier, '100', '10', '1000');
    app(SavePaymentBankSettings::class)($this->owner, $this->company, ['payment_bank' => 'SEB', 'iban' => 'SE4550000000058398257466', 'bank_agreement_id' => 'ABCDEFGHIJ']);

    expect(fn () => app(CreatePaymentBatch::class)($this->owner, $this->company, [$invoice->id], CarbonImmutable::parse('2026-03-12')))
        ->toThrow(ValidationException::class);

    try {
        app(CreatePaymentBatch::class)($this->owner, $this->company, [$invoice->id], CarbonImmutable::parse('2026-03-12'));
    } catch (ValidationException $exception) {
        expect(collect($exception->errors())->flatten()->first())->toContain('är i USD. Betala den i internetbanken');
    }

    $credit = dollarSupplierInvoice($this->owner, $this->company, $this->supplier, '10', '10', '100', credit: true);

    expect(fn () => app(OffsetSupplierCredit::class)($this->owner, $credit, $invoice, CarbonImmutable::parse('2026-03-12')))
        ->toThrow(ValidationException::class, 'i utländsk valuta kan inte kvittas ännu');
});

it('never lets another company see or pay an invoice in another currency', function () {
    $invoice = dollarSupplierInvoice($this->owner, $this->company, $this->supplier, '100', '10', '1000');
    [$other, $otherCompany] = Bookkeeping::company();

    CurrentCompany::set($otherCompany);
    $this->actingAs($other);

    expect(SupplierInvoice::query()->where('invoice_currency', 'USD')->count())->toBe(0)
        ->and(fn () => SupplierInvoice::query()->findOrFail($invoice->id))->toThrow(ModelNotFoundException::class);

    $this->get(route('payables.invoices.show', $invoice))->assertNotFound();

    expect(fn () => app(PaySupplierInvoice::class)($other, $invoice, CarbonImmutable::parse('2026-04-04'), Money::parse('1000')))
        ->toThrow(Exception::class);

    CurrentCompany::set($this->company);
    expect($invoice->fresh()->paid_ore->ore)->toBe(0);
});

it('prefills Riksbanken\'s rate in the editor and saves the currency fields', function () {
    Http::fake(['api.riksbank.se/*' => Http::response([['date' => '2026-03-05', 'value' => 9.5]])]);

    Livewire::test(SupplierInvoiceEditor::class)
        ->set('supplierId', (string) $this->supplier->id)
        ->set('invoiceDate', '2026-03-05')
        ->set('currency', 'usd')
        ->assertSet('currency', 'USD')
        ->assertSet('exchangeRate', '9,500000')
        ->assertSet('rateDate', '2026-03-05')
        ->set('foreignTotal', '49')
        ->assertSet('total', '465,50')
        ->set('rows', [['account' => '6540', 'amount' => '465,50', 'description' => 'Projektverktyg', 'cost_center' => '', 'project' => '', 'vat_rate' => '2500']])
        ->call('save')
        ->assertHasNoErrors();

    $invoice = SupplierInvoice::query()->latest()->firstOrFail();

    expect($invoice->invoice_currency)->toBe('USD')
        ->and($invoice->foreign_total_minor)->toBe(4_900)
        ->and($invoice->exchange_rate_micros)->toBe(9_500_000)
        ->and($invoice->total_ore->ore)->toBe(46_550);
});

it('prefills the kronor paid at Riksbanken\'s rate on the payment day and books the difference', function () {
    Http::fake(['api.riksbank.se/*' => Http::response([['date' => '2026-03-09', 'value' => 10.25]])]);
    $invoice = dollarSupplierInvoice($this->owner, $this->company, $this->supplier, '100', '10', '1000');

    Livewire::test(SupplierInvoiceActions::class, ['invoice' => $invoice])
        ->assertSet('foreignAmount', '100,00')
        ->assertSet('amount', Money::ofOre(102_500)->format(withCurrency: false))
        ->assertSee('100,00 USD')
        ->assertSee('Kursförlust '.Money::ofOre(2_500)->format().' bokförs på 7960.')
        ->set('amount', '1 020,00')
        ->call('pay')
        ->assertHasNoErrors();

    $payment = $invoice->fresh()->payments()->firstOrFail();

    expect(fxLines($payment->voucher))->toBe([1930 => -102_000, 2440 => 100_000, 7960 => 2_000]);
});

it('takes a dollar invoice from the inbox all the way to a paid invoice with its exchange difference', function () {
    Storage::fake('local');
    Http::preventStrayRequests();
    Http::fake(['api.riksbank.se/*' => Http::response([['date' => '2026-03-05', 'value' => 9.82635]])]);
    $this->travelTo(CarbonImmutable::parse('2026-03-06 07:00:00'));
    [$owner, $company] = Inbox::company();
    CurrentCompany::set($company);
    $this->actingAs($owner);
    $company->forceFill(['separate_supplier_approval' => false])->save();
    Inbox::enableAi($company, $owner);
    Inbox::allow($company, '@exempelbolaget.example');
    $supplier = app(SaveSupplier::class)($owner, $company, ['name' => 'Molnet Exempel Inc.', 'payment_terms_days' => 14, 'default_account' => 6540]);
    SupplierInvoiceAgent::fake([[
        'supplier_id' => $supplier->id, 'supplier_name' => 'Molnet Exempel Inc.', 'supplier_org_number' => '', 'supplier_vat_number' => '', 'supplier_bankgiro' => '', 'supplier_plusgiro' => '', 'supplier_iban' => '',
        'supplier_address' => '', 'supplier_postal_code' => '', 'supplier_city' => '', 'supplier_email' => '', 'supplier_phone' => '',
        'invoice_number' => 'INV-42', 'ocr' => '', 'invoice_date' => '2026-03-05', 'due_date' => '2026-03-19', 'currency' => 'USD',
        'total' => 1517.5, 'vat_total' => 0.0, 'is_credit_note' => false, 'vat_treatment' => 'DOMESTIC', 'description' => 'Servrar mars',
        'rows' => [['account' => 6540, 'amount_excl_vat' => 1517.5, 'vat_rate_percent' => 0, 'description' => 'Servrar']], 'notes' => '',
    ]]);

    $message = Inbox::fresh(Inbox::receive($company, Inbox::fixture('kvitto.eml'), 'faktura'));
    $approved = app(ApproveInboxMessage::class)($owner, $message, $message->proposal, ApproveInboxMessage::POST, true);
    $invoice = SupplierInvoice::query()->findOrFail($approved->supplier_invoice_id);

    // 1 517,50 USD at 9,82635 is 14 911,486 kr.
    expect($invoice->invoice_currency)->toBe('USD')
        ->and($invoice->foreign_total_minor)->toBe(151_750)
        ->and($invoice->exchange_rate_micros)->toBe(9_826_350)
        ->and($invoice->total_ore->ore)->toBe(1_491_149)
        ->and(fxLines($invoice->voucher))->toBe([2440 => -1_491_149, 6540 => 1_491_149]);

    $invoice = app(ApproveSupplierInvoice::class)($owner, $invoice)->fresh();
    $payment = app(PaySupplierInvoice::class)($owner, $invoice, CarbonImmutable::parse('2026-03-19'), Money::parse('15 210,00'));

    expect(fxLines($payment->voucher))->toBe([1930 => -1_521_000, 2440 => 1_491_149, 7960 => 29_851])
        ->and($invoice->fresh()->status)->toBe(SupplierInvoiceStatus::Paid);
});
