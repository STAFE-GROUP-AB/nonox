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
use App\Actions\Invoicing\RegisterRefund;
use App\Actions\Invoicing\RegisterTaxReductionPayment;
use App\Actions\Invoicing\RegisterTaxReductionRepayment;
use App\Actions\Invoicing\SaveCustomer;
use App\Actions\Invoicing\SaveDraftInvoice;
use App\Domain\Closing\OpenInvoices;
use App\Domain\Closing\UnpaidInvoices;
use App\Domain\Company\AccountingMethod;
use App\Domain\Invoicing\InvoiceStatus;
use App\Livewire\Invoicing\InvoiceActions;
use App\Models\Customer;
use App\Models\FiscalYear;
use App\Models\Invoice;
use App\Support\Money\Money;
use App\Support\Tenancy\CurrentCompany;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
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
        ->toThrow(ValidationException::class, 'större än det som går att kreditera');

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

/*
 * A credit after Skatteverket has paid the deduction (#49): the paid part
 * is a debt to Skatteverket, which the company must notify and which
 * Skatteverket reclaims (lag (2009:194) 13-16 §§). It goes on 2890, not as
 * a negative 1513.
 */

/** Labour 10 × 500 kr + 25 % = 6 250 kr; 30 % ROT = 1 875 kr; material 2 500 kr. Customer pays 8 750 − 1 875 = 6 875 kr. */
function kredRotInvoice(): Invoice
{
    return app(IssueInvoice::class)(test()->owner, kredDraft(kredHousehold(), [
        ['description' => 'Badrum, arbete', 'quantity' => '10', 'price' => '500', 'vat_rate' => 2500, 'labour' => true],
        ['description' => 'Kakel', 'quantity' => '1', 'price' => '2 000', 'vat_rate' => 2500, 'labour' => false],
    ], ['tax_reduction' => 'ROT', 'property_designation' => 'Uppsala Kåbo 1:25']));
}

it('books what Skatteverket has paid as a debt on 2890 when the invoice is credited, and registers the repayment (#49)', function () {
    $invoice = kredRotInvoice();
    expect($invoice->tax_reduction_ore->ore)->toBe(187_500)->and($invoice->total_ore->ore)->toBe(687_500);

    app(RegisterTaxReductionPayment::class)($this->owner, $invoice, CarbonImmutable::parse('2026-03-20'), Money::ofOre(187_500));
    $credit = kredCredit($invoice);

    // Credit: 1510 −6 875, 2890 −1 875 (debt), 1513 untouched by the credit; revenue and VAT reversed.
    expect($credit->tax_reduction_repayable_ore->ore)->toBe(187_500)
        ->and(kredBalance(1513))->toBe(0)
        ->and(kredBalance(2890))->toBe(-187_500)
        ->and(kredBalance(1930))->toBe(187_500)
        ->and(kredBalance(1510))->toBe(0)
        ->and(kredLedger())->toBe(0)
        ->and($invoice->fresh()->taxReductionClaimable()->ore)->toBe(0);

    Livewire::test(InvoiceActions::class, ['invoice' => $credit])
        ->assertSee('Det är nu en skuld till Skatteverket på konto 2890.')
        ->assertSee('Anmäl ändringen till Skatteverket utan dröjsmål')
        ->set('repayPaidOn', '2026-05-29')
        ->set('repayAmount', '1 875,00')
        ->call('registerTaxReductionRepayment')
        ->assertHasNoErrors();

    expect(kredBalance(2890))->toBe(0)
        ->and(kredBalance(1930))->toBe(0)
        ->and($credit->fresh()->tax_reduction_repaid_ore->ore)->toBe(187_500)
        ->and(fn () => app(RegisterTaxReductionRepayment::class)($this->owner, $credit->fresh(), CarbonImmutable::parse('2026-05-30'), Money::ofOre(100)))
        ->toThrow(AuthorizationException::class);
});

it('takes a credit off the unpaid claim first and books only the paid rest as a debt (#49)', function () {
    $invoice = kredRotInvoice();
    $this->actingAs($this->owner);

    expect(Livewire::test(InvoiceActions::class, ['invoice' => $invoice])->html())->not->toContain('Skatteverket har betalat ut');

    // Skatteverket has paid 1 000 of the 1 875 kr; 875 kr is still claimed.
    app(RegisterTaxReductionPayment::class)($this->owner, $invoice, CarbonImmutable::parse('2026-03-20'), Money::ofOre(100_000));
    Livewire::test(InvoiceActions::class, ['invoice' => $invoice->fresh()])->assertSee('i ROT-avdrag för fakturan. Krediterar du avdraget blir den delen en skuld till Skatteverket');

    $credit = kredCredit($invoice);

    // 875 kr comes off the claim on 1513 and 1 000 kr becomes a debt.
    expect($credit->tax_reduction_repayable_ore->ore)->toBe(100_000)
        ->and(kredBalance(1513))->toBe(0)
        ->and(kredBalance(2890))->toBe(-100_000)
        ->and(kredBalance(1510))->toBe(0)
        // Nothing is left to claim, so no further payment from Skatteverket can be registered.
        ->and(fn () => app(RegisterTaxReductionPayment::class)($this->owner, $invoice->fresh(), CarbonImmutable::parse('2026-04-01'), Money::ofOre(87_500)))
        ->toThrow(AuthorizationException::class)
        ->and(fn () => app(RegisterTaxReductionRepayment::class)($this->owner, $credit, CarbonImmutable::parse('2026-05-30'), Money::ofOre(100_001)))
        ->toThrow(ValidationException::class);
});

/*
 * A credit note for a paid or part-paid invoice (#53). The law does not
 * limit credit notes to unpaid invoices (ML 17 kap. 22-23 §§; a VAT
 * reduction needs such a document, 7 kap. 46 §). What the customer has
 * already paid of the credit is a credit balance on the credit invoice
 * (credit on 1510), paid back with a refund.
 */

/** 1 000 kr + 25 % = 1 250 kr. */
function kredLicence(): Invoice
{
    return app(IssueInvoice::class)(test()->owner, kredDraft(test()->customer, [['description' => 'Licens', 'quantity' => '1', 'price' => '1 000', 'vat_rate' => 2500]]));
}

/** 400 kr + 25 % = 500 kr. */
function kredPriceReduction(Invoice $invoice): Invoice
{
    return kredCredit($invoice, [['description' => 'Prisavdrag licens', 'quantity' => '1', 'price' => '400', 'vat_rate' => 2500]]);
}

it('credits a paid invoice as a credit balance, and the refund settles it (#53)', function () {
    $invoice = kredLicence();
    app(RegisterPayment::class)($this->owner, $invoice, CarbonImmutable::parse('2026-03-10'), Money::ofOre(125_000));

    $credit = kredPriceReduction($invoice);

    // Nothing remained to settle: the whole 500 kr is owed to the customer.
    expect($credit->status)->toBe(InvoiceStatus::Issued)
        ->and($credit->statusLabel())->toBe('Att återbetala')
        ->and($credit->remaining()->ore)->toBe(-50_000)
        ->and($credit->isOverdue(CarbonImmutable::parse('2026-06-01')))->toBeFalse()
        ->and($invoice->fresh()->status)->toBe(InvoiceStatus::Paid)
        ->and(kredBalance(1510))->toBe(-50_000)
        ->and(kredLedger())->toBe(-50_000)
        // Revenue 1 000 − 400 = 600 kr and VAT 250 − 100 = 150 kr.
        ->and(kredBalance(3001))->toBe(-60_000)
        ->and(kredBalance(2611))->toBe(-15_000)
        ->and(collect(OpenInvoices::customer(CarbonImmutable::parse('2026-03-31')))->sum('open'))->toBe(-50_000);

    Livewire::test(InvoiceActions::class, ['invoice' => $credit])
        ->assertSee('Betala tillbaka till kunden')
        ->assertSet('refundAmount', '500,00')
        ->set('refundPaidOn', '2026-03-12')
        ->call('registerRefund')
        ->assertHasNoErrors();

    expect($credit->fresh()->status)->toBe(InvoiceStatus::Paid)
        ->and($credit->fresh()->payments->first()->amount_ore->ore)->toBe(-50_000)
        ->and(kredBalance(1510))->toBe(0)
        ->and(kredLedger())->toBe(0)
        ->and(kredBalance(1930))->toBe(125_000 - 50_000)
        ->and(OpenInvoices::customer(CarbonImmutable::parse('2026-03-31')))->toBe([])
        // On the day before the refund the credit balance was still open.
        ->and(collect(OpenInvoices::customer(CarbonImmutable::parse('2026-03-11')))->sum('open'))->toBe(-50_000);
});

it('settles what remains of a part-paid invoice first and leaves the rest as a credit balance (#53)', function () {
    $invoice = kredLicence();
    app(RegisterPayment::class)($this->owner, $invoice, CarbonImmutable::parse('2026-03-10'), Money::ofOre(100_000));

    // 250 kr remained: the 500 kr credit settles it and 250 kr is owed to the customer.
    $credit = kredPriceReduction($invoice);

    expect($invoice->fresh()->status)->toBe(InvoiceStatus::Paid)
        ->and($invoice->fresh()->credited_ore->ore)->toBe(25_000)
        ->and($invoice->fresh()->remaining()->ore)->toBe(0)
        ->and($credit->remaining()->ore)->toBe(-25_000)
        ->and(kredBalance(1510))->toBe(-25_000)
        ->and(kredLedger())->toBe(-25_000);

    expect(fn () => app(RegisterRefund::class)($this->owner, $credit, CarbonImmutable::parse('2026-03-12'), Money::ofOre(25_001)))
        ->toThrow(ValidationException::class);

    app(RegisterRefund::class)($this->owner, $credit, CarbonImmutable::parse('2026-03-12'), Money::ofOre(25_000));

    expect(kredBalance(1510))->toBe(0)
        ->and(kredLedger())->toBe(0)
        ->and(kredBalance(1930))->toBe(100_000 - 25_000);
});

it('refuses a credit of more than is not credited yet, and only members with payments:create refund (#53)', function () {
    $invoice = kredLicence();
    app(RegisterPayment::class)($this->owner, $invoice, CarbonImmutable::parse('2026-03-10'), Money::ofOre(125_000));

    expect(fn () => kredCredit($invoice, [['description' => 'Licens', 'quantity' => '1', 'price' => '1 001', 'vat_rate' => 2500]]))
        ->toThrow(ValidationException::class, 'större än det som går att kreditera');

    $credit = kredCredit($invoice);

    expect($credit->remaining()->ore)->toBe(-125_000)
        ->and(fn () => app(CreditInvoice::class)($this->owner, $invoice->fresh()))->toThrow(AuthorizationException::class)
        ->and(fn () => app(RegisterRefund::class)(Bookkeeping::member($this->company, 'auditor'), $credit, CarbonImmutable::parse('2026-03-12'), Money::ofOre(125_000)))
        ->toThrow(AuthorizationException::class)
        ->and(fn () => app(RegisterPayment::class)($this->owner, $credit, CarbonImmutable::parse('2026-03-12'), Money::ofOre(1)))
        ->toThrow(AuthorizationException::class);
});

it('books the credited revenue and VAT when the refund is paid under kontantmetoden (#53)', function () {
    $this->company->forceFill(['accounting_method' => AccountingMethod::Kontantmetoden])->save();
    $invoice = kredLicence();
    app(RegisterPayment::class)($this->owner, $invoice, CarbonImmutable::parse('2026-03-10'), Money::ofOre(125_000));
    $credit = kredPriceReduction($invoice);

    // Nothing is booked when the credit is issued under kontantmetoden.
    expect($credit->voucher_id)->toBeNull()
        ->and(kredBalance(3001))->toBe(-100_000);

    app(RegisterRefund::class)($this->owner, $credit, CarbonImmutable::parse('2026-03-12'), Money::ofOre(50_000));

    // The refund reverses 400 kr revenue and 100 kr VAT: 600 kr and 150 kr remain.
    expect(kredBalance(3001))->toBe(-60_000)
        ->and(kredBalance(2611))->toBe(-15_000)
        ->and(kredBalance(1930))->toBe(75_000)
        ->and(kredBalance(1510))->toBe(0);
});

it('pays back a credit balance in euro in its currency, with the exchange difference (#53)', function () {
    Http::fake(['api.riksbank.se/*' => Http::response([['date' => '2026-03-02', 'value' => 11.0]])]);
    $invoice = app(IssueInvoice::class)($this->owner, kredDraft($this->customer, [['description' => 'Licens', 'quantity' => '1', 'price' => '1 000', 'vat_rate' => 0]], ['currency' => 'EUR', 'exchange_rate' => '11']));
    // 1 000 € paid; 11 000 kr reached the bank.
    app(RegisterPayment::class)($this->owner, $invoice, CarbonImmutable::parse('2026-03-10'), Money::ofOre(1_100_000), foreignAmount: 100_000);

    $credit = kredCredit($invoice, [['description' => 'Licens', 'quantity' => '1', 'price' => '300', 'vat_rate' => 0]]);

    // 300 € at the invoice's 11 kr = 3 300 kr owed to the customer.
    expect($credit->foreignRemaining())->toBe(-30_000)
        ->and($credit->remaining()->ore)->toBe(-330_000)
        ->and(kredBalance(1510))->toBe(-330_000);

    // 300 € paid back cost 3 150 kr at 10,50: 150 kr less than booked, an exchange gain on 3960.
    app(RegisterRefund::class)($this->owner, $credit, CarbonImmutable::parse('2026-03-20'), Money::ofOre(315_000), foreignAmount: 30_000);

    expect($credit->fresh()->status)->toBe(InvoiceStatus::Paid)
        ->and(kredBalance(1510))->toBe(0)
        ->and(kredLedger())->toBe(0)
        ->and(kredBalance(3960))->toBe(-15_000)
        ->and(kredBalance(1930))->toBe(1_100_000 - 315_000);
});

it('credits a ROT invoice the customer and Skatteverket have paid: credit balance on 1510, debt on 2890 (#53, #49)', function () {
    $invoice = kredRotInvoice();
    app(RegisterPayment::class)($this->owner, $invoice, CarbonImmutable::parse('2026-03-10'), Money::ofOre(687_500));
    app(RegisterTaxReductionPayment::class)($this->owner, $invoice, CarbonImmutable::parse('2026-03-20'), Money::ofOre(187_500));

    $credit = kredCredit($invoice);

    expect($credit->remaining()->ore)->toBe(-687_500)
        ->and(kredBalance(1510))->toBe(-687_500)
        ->and(kredLedger())->toBe(-687_500)
        ->and(kredBalance(1513))->toBe(0)
        ->and(kredBalance(2890))->toBe(-187_500);
});

it('books a credit balance not yet paid back at year-end under kontantmetoden as the reversed share of the credit (#53)', function () {
    $this->company->forceFill(['accounting_method' => AccountingMethod::Kontantmetoden])->save();
    $invoice = kredLicence();
    app(RegisterPayment::class)($this->owner, $invoice, CarbonImmutable::parse('2026-03-10'), Money::ofOre(125_000));
    kredPriceReduction($invoice);

    $unpaid = UnpaidInvoices::for($this->company, FiscalYear::query()->orderBy('start_date')->firstOrFail());
    $rows = collect($unpaid['customerRows'])->pluck('amount', 'account')->sortKeys()->all();

    // The 500 kr owed to the customer: 1510 credit, and 400 kr revenue and 100 kr VAT reversed.
    expect($rows)->toBe([1510 => -50_000, 2611 => 10_000, 3001 => 40_000]);
});
