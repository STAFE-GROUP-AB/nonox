<?php

declare(strict_types=1);

/*
 * Credit invoices (kreditfakturor) for part of an invoice (#47).
 *
 * A credit note changes the original invoice by what it credits and refers
 * to it (Mervärdesskattelag (2023:200) 17 kap. 22-23 §§). The customer ledger
 * must show the company's actual claims and agree with 1510 (BFL 4 kap. 1 §,
 * 5 kap. 1 §). Every expected value is computed by hand in the comments.
 */

use App\Actions\Invoicing\CreditInvoice;
use App\Actions\Invoicing\IssueInvoice;
use App\Actions\Invoicing\RegisterPayment;
use App\Actions\Invoicing\SaveCustomer;
use App\Actions\Invoicing\SaveDraftInvoice;
use App\Domain\Company\AccountingMethod;
use App\Domain\Invoicing\InvoiceStatus;
use App\Models\Customer;
use App\Models\Invoice;
use App\Support\Money\Money;
use App\Support\Tenancy\CurrentCompany;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;
use Tests\Support\Bookkeeping;

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-03-02 10:00:00'));
    [$this->owner, $this->company] = Bookkeeping::company();
    CurrentCompany::set($this->company);
    $this->actingAs($this->owner);
    $this->customer = app(SaveCustomer::class)($this->owner, $this->company, [
        'name' => 'Kreditkunden AB', 'org_number' => '5563343689', 'payment_terms_days' => 30,
        'address_line1' => 'Kundvägen 7', 'postal_code' => '211 20', 'city' => 'Malmö',
    ]);
});

/**
 * @param  list<array<string, mixed>>  $rows
 * @param  array<string, mixed>  $extra
 */
function kredDraft(Customer $customer, array $rows, array $extra = []): Invoice
{
    return app(SaveDraftInvoice::class)(test()->owner, test()->company, [
        'customer_id' => $customer->id,
        'invoice_date' => '2026-03-02',
        'rows' => $rows,
        ...$extra,
    ]);
}

/**
 * A credit of $original with the rows given instead of all of its rows.
 *
 * @param  list<array<string, mixed>>|null  $rows
 */
function kredCredit(Invoice $original, ?array $rows = null): Invoice
{
    $draft = app(CreditInvoice::class)(test()->owner, $original->fresh());

    if ($rows !== null) {
        $draft = app(SaveDraftInvoice::class)(test()->owner, test()->company, [
            'customer_id' => $original->customer_id,
            'invoice_date' => CarbonImmutable::today()->toDateString(),
            'due_date' => CarbonImmutable::today()->toDateString(),
            'tax_reduction' => $original->tax_reduction,
            'property_designation' => $original->property_designation,
            'rows' => $rows,
        ], $draft);
    }

    return app(IssueInvoice::class)(test()->owner, $draft);
}

/** Signed balance (debit positive) of an account over the company's posted vouchers. */
function kredBalance(int $account): int
{
    return (int) DB::table('voucher_rows')
        ->join('vouchers', 'vouchers.id', '=', 'voucher_rows.voucher_id')
        ->join('accounts', 'accounts.id', '=', 'voucher_rows.account_id')
        ->where('vouchers.company_id', CurrentCompany::id())
        ->where('vouchers.status', 'POSTED')
        ->where('accounts.number', $account)
        ->sum(DB::raw('voucher_rows.debit_ore - voucher_rows.credit_ore'));
}

/** The customer ledger: what is open on every issued invoice and credit invoice, öre. */
function kredLedger(): int
{
    return (int) Invoice::query()->where('status', InvoiceStatus::Issued->value)->get()->sum(fn (Invoice $invoice): int => $invoice->remaining()->ore);
}

it('reduces the original by a partial credit only, and the rest can be paid (#47)', function () {
    // 1 400 kr + 25 % = 1 750 kr.
    $invoice = app(IssueInvoice::class)($this->owner, kredDraft($this->customer, [['description' => 'Konsulttimme', 'quantity' => '2', 'price' => '700', 'vat_rate' => 2500]]));

    // Credit 400 kr + 25 % = 500 kr.
    $credit = kredCredit($invoice, [['description' => 'Konsulttimme', 'quantity' => '1', 'price' => '400', 'vat_rate' => 2500]]);
    $invoice->refresh();

    expect($credit->total_ore->ore)->toBe(-50_000)
        ->and($credit->status)->toBe(InvoiceStatus::Paid)
        ->and($credit->remaining()->ore)->toBe(0)
        ->and($invoice->status)->toBe(InvoiceStatus::Issued)
        ->and($invoice->credited_ore->ore)->toBe(50_000)
        ->and($invoice->remaining()->ore)->toBe(125_000)
        // 1 750 − 500 = 1 250 kr on 1510, and the ledger agrees.
        ->and(kredBalance(1510))->toBe(125_000)
        ->and(kredLedger())->toBe(125_000)
        ->and(kredBalance(2611))->toBe(-35_000 + 10_000)
        ->and(kredBalance(3001))->toBe(-140_000 + 40_000);

    app(RegisterPayment::class)($this->owner, $invoice, CarbonImmutable::parse('2026-03-20'), Money::ofOre(125_000));

    expect($invoice->fresh()->status)->toBe(InvoiceStatus::Paid)
        ->and(kredBalance(1510))->toBe(0)
        ->and(kredLedger())->toBe(0);
});

it('closes the original as credited when partial credits together credit all of it (#47)', function () {
    $invoice = app(IssueInvoice::class)($this->owner, kredDraft($this->customer, [['description' => 'Licens', 'quantity' => '2', 'price' => '1 000', 'vat_rate' => 2500]]));

    kredCredit($invoice, [['description' => 'Licens', 'quantity' => '1', 'price' => '1 000', 'vat_rate' => 2500]]);
    expect($invoice->fresh()->status)->toBe(InvoiceStatus::Issued)
        ->and($invoice->fresh()->remaining()->ore)->toBe(125_000);

    kredCredit($invoice, [['description' => 'Licens', 'quantity' => '1', 'price' => '1 000', 'vat_rate' => 2500]]);

    expect($invoice->fresh()->status)->toBe(InvoiceStatus::Credited)
        ->and($invoice->fresh()->credited_ore->ore)->toBe(250_000)
        ->and(kredBalance(1510))->toBe(0)
        ->and(kredLedger())->toBe(0);
});

it('refuses a credit larger than what remains of an unpaid invoice, or one that is not below zero (#47)', function () {
    $invoice = app(IssueInvoice::class)($this->owner, kredDraft($this->customer, [['description' => 'Licens', 'quantity' => '1', 'price' => '1 000', 'vat_rate' => 2500]]));
    kredCredit($invoice, [['description' => 'Licens', 'quantity' => '1', 'price' => '600', 'vat_rate' => 2500]]);

    // 1 250 − 750 = 500 kr left; a credit of 625 kr is too large.
    expect(fn () => kredCredit($invoice, [['description' => 'Licens', 'quantity' => '1', 'price' => '500', 'vat_rate' => 2500]]))
        ->toThrow(ValidationException::class, 'större än det som återstår');

    $draft = app(CreditInvoice::class)($this->owner, $invoice->fresh());
    $draft = app(SaveDraftInvoice::class)($this->owner, $this->company, [
        'customer_id' => $invoice->customer_id, 'invoice_date' => '2026-03-02',
        'rows' => [['description' => 'Licens', 'quantity' => '1', 'price' => '-100', 'vat_rate' => 2500]],
    ], $draft);

    expect(fn () => app(IssueInvoice::class)($this->owner, $draft))->toThrow(ValidationException::class, 'under noll')
        ->and(kredBalance(1510))->toBe(50_000)
        ->and(kredLedger())->toBe(50_000);
});

it('books the rest of a partly credited invoice under kontantmetoden when it is paid (#47)', function () {
    $this->company->forceFill(['accounting_method' => AccountingMethod::Kontantmetoden])->save();
    $invoice = app(IssueInvoice::class)($this->owner, kredDraft($this->customer, [['description' => 'Licens', 'quantity' => '1', 'price' => '1 000', 'vat_rate' => 2500]]));
    kredCredit($invoice, [['description' => 'Licens', 'quantity' => '1', 'price' => '400', 'vat_rate' => 2500]]);

    // 1 250 − 500 = 750 kr: 600 kr revenue and 150 kr VAT.
    app(RegisterPayment::class)($this->owner, $invoice->fresh(), CarbonImmutable::parse('2026-03-20'), Money::ofOre(75_000));

    expect(kredBalance(1930))->toBe(75_000)
        ->and(kredBalance(3001))->toBe(-60_000)
        ->and(kredBalance(2611))->toBe(-15_000)
        ->and(kredBalance(1510))->toBe(0)
        ->and($invoice->fresh()->status)->toBe(InvoiceStatus::Paid);
});

it('credits part of an invoice in euro in its currency and at its rate (#47)', function () {
    Http::fake(['api.riksbank.se/*' => Http::response([['date' => '2026-03-02', 'value' => 11.0]])]);
    // 1 000 € at 11 kr = 11 000 kr, on a 0 % row to keep the arithmetic plain.
    $invoice = app(IssueInvoice::class)($this->owner, kredDraft($this->customer, [['description' => 'Licens', 'quantity' => '1', 'price' => '1 000', 'vat_rate' => 0]], ['currency' => 'EUR', 'exchange_rate' => '11']));

    $this->travelTo(CarbonImmutable::parse('2026-03-10 10:00:00'));
    $credit = kredCredit($invoice, [['description' => 'Licens', 'quantity' => '1', 'price' => '300', 'vat_rate' => 0]]);
    $invoice->refresh();

    // 300 € at the invoice's 11 kr = 3 300 kr; 700 € = 7 700 kr remain.
    expect($credit->invoice_currency)->toBe('EUR')
        ->and($credit->exchange_rate_micros)->toBe(11_000_000)
        ->and($credit->total_ore->ore)->toBe(-330_000)
        ->and($invoice->status)->toBe(InvoiceStatus::Issued)
        ->and($invoice->foreignRemaining())->toBe(70_000)
        ->and($invoice->remaining()->ore)->toBe(770_000)
        ->and(kredBalance(1510))->toBe(770_000)
        ->and(kredLedger())->toBe(770_000);
});

/*
 * ROT and RUT on a credit invoice (#48): the deduction is the original's,
 * reversed in proportion to the labour credited, never recomputed at the
 * credit's date, rate or ceiling (ML 17 kap. 22 §; lag (2009:194) 7 §).
 */

function kredHousehold(): Customer
{
    return app(SaveCustomer::class)(test()->owner, test()->company, [
        'name' => 'Bertil Berg', 'personal_number' => '19121212-1212', 'payment_terms_days' => 30,
        'address_line1' => 'Villavägen 2', 'postal_code' => '752 36', 'city' => 'Uppsala',
    ]);
}

it('reverses exactly the capped deduction on a full credit of a ROT invoice (#48)', function () {
    // Labour 160 000 + 25 % = 200 000 kr; 30 % = 60 000 kr, capped at 50 000 kr. Customer pays 150 000 kr.
    $invoice = app(IssueInvoice::class)($this->owner, kredDraft(kredHousehold(), [
        ['description' => 'Tillbyggnad, arbete', 'quantity' => '1', 'price' => '160 000', 'vat_rate' => 2500, 'labour' => true],
    ], ['tax_reduction' => 'ROT', 'property_designation' => 'Uppsala Kåbo 1:24']));

    // Credited months later: the deduction is the original's, not recomputed.
    $this->travelTo(CarbonImmutable::parse('2026-12-15 10:00:00'));
    $credit = kredCredit($invoice);

    expect($credit->tax_reduction_ore->ore)->toBe(-5_000_000)
        ->and($credit->total_ore->ore)->toBe(-15_000_000)
        ->and($invoice->fresh()->status)->toBe(InvoiceStatus::Credited)
        ->and(kredBalance(1513))->toBe(0)
        ->and(kredBalance(1510))->toBe(0)
        ->and(kredLedger())->toBe(0);
});

it('reverses a share of the deduction on a partial credit, and the last credit takes the rest (#48)', function () {
    // Labour 3 × 1 000 kr + 25 % = 3 750 kr; 30 % = 1 125 kr. Material 2 000 + 25 % = 2 500 kr.
    // Total 6 250 − 1 125 = 5 125 kr for the customer.
    $invoice = app(IssueInvoice::class)($this->owner, kredDraft(kredHousehold(), [
        ['description' => 'Målning, arbete', 'quantity' => '3', 'price' => '1 000', 'vat_rate' => 2500, 'labour' => true],
        ['description' => 'Färg', 'quantity' => '1', 'price' => '2 000', 'vat_rate' => 2500, 'labour' => false],
    ], ['tax_reduction' => 'ROT', 'property_designation' => 'Uppsala Kåbo 1:24']));
    expect($invoice->tax_reduction_ore->ore)->toBe(112_500)->and($invoice->total_ore->ore)->toBe(512_500);

    // One hour: 1 250 kr is a third of 3 750 kr, so a third of 1 125 = 375 kr. Credit 1 250 − 375 = 875 kr.
    $first = kredCredit($invoice, [['description' => 'Målning, arbete', 'quantity' => '1', 'price' => '1 000', 'vat_rate' => 2500, 'labour' => true]]);

    expect($first->tax_reduction_ore->ore)->toBe(-37_500)
        ->and($first->total_ore->ore)->toBe(-87_500)
        ->and($invoice->fresh()->remaining()->ore)->toBe(512_500 - 87_500)
        ->and(kredBalance(1513))->toBe(112_500 - 37_500);

    // The other two hours are the rest of the labour, so they take the rest of the deduction, 750 kr.
    $second = kredCredit($invoice, [['description' => 'Målning, arbete', 'quantity' => '2', 'price' => '1 000', 'vat_rate' => 2500, 'labour' => true]]);

    expect($second->tax_reduction_ore->ore)->toBe(-75_000)
        ->and(kredBalance(1513))->toBe(0)
        // The paint is left: 2 500 kr, on 1510 and in the ledger.
        ->and(kredBalance(1510))->toBe(250_000)
        ->and(kredLedger())->toBe(250_000);
});
