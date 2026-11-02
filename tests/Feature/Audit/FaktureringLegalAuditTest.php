<?php

declare(strict_types=1);

/*
 * Legal audit of invoicing and payables (granskning "fakturering", 2026-11-02).
 *
 * Every expected value below is computed by hand from the rule cited in the
 * test name or comment, never copied from what the code prints. Tests marked
 * ->todo('AUD-FAKT-n: ...') show a deviation from the cited rule; they are
 * not fixed here (fixes follow only after an independent second check).
 *
 * Sources (read 2026-11-02):
 * - Mervärdesskattelag (2023:200), 15 kap. 16, 26, 29, 30 §§ and 17 kap. 22-29 §§,
 *   https://www.riksdagen.se/sv/dokument-och-lagar/dokument/svensk-forfattningssamling/mervardesskattelag-2023200_sfs-2023-200/
 * - Bokföringslagen (1999:1078) 5 kap. 1 § (affärshändelser bokförs så att de kan
 *   presenteras i registreringsordning och systematisk ordning) and 4 kap. 1 §.
 */

use App\Actions\Invoicing\CreditInvoice;
use App\Actions\Invoicing\IssueInvoice;
use App\Actions\Invoicing\RegisterPayment;
use App\Actions\Invoicing\RegisterTaxReductionPayment;
use App\Actions\Invoicing\SaveCustomer;
use App\Actions\Invoicing\SaveDraftInvoice;
use App\Actions\Payables\ApproveSupplierInvoice;
use App\Actions\Payables\PaySupplierInvoice;
use App\Actions\Payables\RegisterSupplierInvoice;
use App\Actions\Payables\SaveSupplier;
use App\Actions\Payables\SaveSupplierInvoice;
use App\Domain\Company\AccountingMethod;
use App\Domain\Invoicing\InvoicePdf;
use App\Domain\Invoicing\InvoiceStatus;
use App\Domain\Invoicing\Ocr;
use App\Domain\Payables\SupplierInvoiceStatus;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\SupplierInvoice;
use App\Models\Voucher;
use App\Support\Money\Money;
use App\Support\Tenancy\CurrentCompany;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Tests\Support\Bookkeeping;

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-03-02 10:00:00'));
    [$this->owner, $this->company] = Bookkeeping::company();
    $this->company->forceFill([
        'legal_name' => 'Granskningsbolaget AB',
        'address_line1' => 'Revisorsgatan 4',
        'postal_code' => '111 22',
        'city' => 'Stockholm',
        'bankgiro' => '5050-1055',
        'vat_number' => 'SE556334368901',
        'f_skatt' => true,
    ])->save();
    CurrentCompany::set($this->company);
    $this->actingAs($this->owner);

    $this->customer = app(SaveCustomer::class)($this->owner, $this->company, [
        'name' => 'Kundbolaget AB', 'org_number' => '5563343689', 'email' => 'ekonomi@kundbolaget.test',
        'address_line1' => 'Kundvägen 7', 'postal_code' => '211 20', 'city' => 'Malmö', 'payment_terms_days' => 30,
    ]);
});

/** Signed balance (debit positive) of an account over all posted vouchers of the current company. */
function audFaktBalance(int $account): int
{
    return (int) DB::table('voucher_rows')
        ->join('vouchers', 'vouchers.id', '=', 'voucher_rows.voucher_id')
        ->join('accounts', 'accounts.id', '=', 'voucher_rows.account_id')
        ->where('vouchers.company_id', CurrentCompany::id())
        ->where('vouchers.status', 'POSTED')
        ->where('accounts.number', $account)
        ->sum(DB::raw('voucher_rows.debit_ore - voucher_rows.credit_ore'));
}

/** Signed balance of every account in a range (e.g. 3000-3999 for revenue). */
function audFaktRange(int $from, int $to): int
{
    return (int) DB::table('voucher_rows')
        ->join('vouchers', 'vouchers.id', '=', 'voucher_rows.voucher_id')
        ->join('accounts', 'accounts.id', '=', 'voucher_rows.account_id')
        ->where('vouchers.company_id', CurrentCompany::id())
        ->where('vouchers.status', 'POSTED')
        ->whereBetween('accounts.number', [$from, $to])
        ->sum(DB::raw('voucher_rows.debit_ore - voucher_rows.credit_ore'));
}

/** Open customer invoices in the reskontra, öre. */
function audFaktReceivablesLedger(): int
{
    return (int) Invoice::query()->where('status', InvoiceStatus::Issued->value)->get()->sum(fn (Invoice $invoice): int => $invoice->remaining()->ore);
}

/**
 * @param  list<array<string, mixed>>  $rows
 * @param  array<string, mixed>  $extra
 */
function audFaktDraft(Customer $customer, array $rows, array $extra = []): Invoice
{
    return app(SaveDraftInvoice::class)(test()->owner, test()->company, [
        'customer_id' => $customer->id,
        'invoice_date' => '2026-03-02',
        'rows' => $rows,
        ...$extra,
    ]);
}

/** @return array<int, int> */
function audFaktLines(?string $voucherId): array
{
    $lines = [];

    foreach (Voucher::query()->with('rows.account')->findOrFail($voucherId)->rows as $row) {
        $lines[$row->account->number] = ($lines[$row->account->number] ?? 0) + $row->debit_ore->ore - $row->credit_ore->ore;
    }

    ksort($lines);

    return $lines;
}

/* ---------------------------------------------------------------------------
 | OCR (AGENTS.md §9.1, Bankgirot OCR: Luhn/modulus 10 check digit)
 * ------------------------------------------------------------------------- */

it('OCR: invoice number followed by a Luhn (modulus 10) check digit, hand-computed', function () {
    // 4711: from the right 1×2=2, 1×1=1, 7×2=14→1+4=5, 4×1=4; sum 12; check digit 10-2 = 8.
    // 100: 0×2=0, 0×1=0, 1×2=2; sum 2; check digit 8.
    expect(Ocr::forInvoiceNumber(4711))->toBe('47118')
        ->and(Ocr::forInvoiceNumber(100))->toBe('1008')
        ->and(Ocr::isValid('47118'))->toBeTrue()
        ->and(Ocr::isValid('47119'))->toBeFalse();
});

/* ---------------------------------------------------------------------------
 | Fakturametoden: booking, öresavrundning, credit
 * ------------------------------------------------------------------------- */

it('ML 8 kap./17 kap. 24 § p. 9-11: VAT per rate on the base, öresavrundning on 3740, receivable on 1510 (fakturametoden)', function () {
    // 3 × 333,33 = 999,99 at 25 % → VAT 249,9975 → 250,00 (half up); 1 249,99 → 1 250 kr, rounding +0,01.
    $invoice = app(IssueInvoice::class)($this->owner, audFaktDraft($this->customer, [
        ['description' => 'Konsulttimme', 'quantity' => '3', 'price' => '333,33', 'vat_rate' => 2500],
    ]));

    $lines = audFaktLines($invoice->voucher_id);

    expect($invoice->net_ore->ore)->toBe(99_999)
        ->and($invoice->vat_ore->ore)->toBe(25_000)
        ->and($invoice->rounding_ore->ore)->toBe(1)
        ->and($invoice->total_ore->ore)->toBe(125_000)
        ->and($lines[1510])->toBe(125_000)
        ->and($lines[2611])->toBe(-25_000)
        ->and($lines[3740])->toBe(-1)
        ->and(array_sum(array_filter($lines, fn (int $value, int $account): bool => $account >= 3000 && $account <= 3699, ARRAY_FILTER_USE_BOTH)))->toBe(-99_999)
        ->and(array_sum($lines))->toBe(0);
});

it('ML 17 kap. 22 §: a credit invoice refers to the original and reverses 1510, revenue and VAT exactly', function () {
    $original = app(IssueInvoice::class)($this->owner, audFaktDraft($this->customer, [
        ['description' => 'Lunch', 'quantity' => '7', 'price' => '113,57', 'vat_rate' => 1200],
        ['description' => 'Dryck', 'quantity' => '7', 'price' => '31,90', 'vat_rate' => 2500],
    ]));
    $credit = app(IssueInvoice::class)($this->owner, app(CreditInvoice::class)($this->owner, $original));

    expect($credit->total_ore->ore)->toBe(-$original->total_ore->ore)
        ->and($credit->vat_ore->ore)->toBe(-$original->vat_ore->ore)
        ->and(audFaktBalance(1510))->toBe(0)
        ->and(audFaktBalance(2611))->toBe(0)
        ->and(audFaktBalance(2621))->toBe(0)
        ->and(audFaktRange(3000, 3999))->toBe(0)
        ->and(InvoicePdf::html($credit))->toContain('Krediterar faktura')
        ->and(InvoicePdf::html($credit))->toContain((string) $original->number);
});

it('ML 17 kap. 24 §: the invoice PDF carries the mandatory particulars', function () {
    $invoice = app(IssueInvoice::class)($this->owner, audFaktDraft($this->customer, [
        ['description' => 'Konsulttimme', 'quantity' => '2', 'price' => '1 000', 'vat_rate' => 2500],
    ], ['delivery_date' => '2026-02-27']));
    $html = InvoicePdf::html($invoice);

    expect($html)
        ->toContain('2026-03-02')                    // p. 1 date of issue
        ->toContain((string) $invoice->number)       // p. 2 sequential number
        ->toContain('SE556334368901')                // p. 3 seller's VAT number
        ->toContain('Granskningsbolaget AB')         // p. 5 seller's name
        ->toContain('Revisorsgatan 4')               // p. 5 seller's address
        ->toContain('Kundbolaget AB')                // p. 6 buyer's name
        ->toContain('Kundvägen 7')                   // p. 6 buyer's address
        ->toContain('Konsulttimme')                  // p. 7 kind and quantity
        ->toContain('2026-02-27')                    // p. 8 date of supply
        ->toContain("1\u{00A0}000,00")                      // p. 9 unit price excl. VAT
        ->toContain("2\u{00A0}000,00")                      // p. 9 base per rate
        ->toContain('25 %')                          // p. 10 rate
        ->toContain('500,00')                        // p. 11 VAT amount
        ->toContain($invoice->ocr);
});

it('ML 17 kap. 24 § p. 4 and 14: reverse charge in construction states it and both VAT numbers, and carries no VAT', function () {
    $builder = app(SaveCustomer::class)($this->owner, $this->company, [
        'name' => 'Byggherren AB', 'org_number' => '5560160680', 'vat_number' => 'SE556016068001',
        'address_line1' => 'Bygget 1', 'postal_code' => '722 10', 'city' => 'Västerås', 'payment_terms_days' => 30,
        'reverse_charge_construction' => true,
    ]);
    $invoice = app(IssueInvoice::class)($this->owner, audFaktDraft($builder, [
        ['description' => 'Snickeriarbete', 'quantity' => '10', 'price' => '600', 'vat_rate' => 2500],
    ]));
    $html = InvoicePdf::html($invoice);

    expect($invoice->vat_ore->ore)->toBe(0)
        ->and(audFaktLines($invoice->voucher_id))->toBe([1510 => 600_000, 3231 => -600_000])
        ->and($html)->toContain('Omvänd betalningsskyldighet')
        ->and($html)->toContain('SE556334368901')
        ->and($html)->toContain('SE556016068001');
});

it('ML 17 kap. 24 § p. 4 and 13: an intra-Community supply of goods states the exemption and the buyer\'s VAT number', function () {
    $german = app(SaveCustomer::class)($this->owner, $this->company, [
        'name' => 'Abnehmer GmbH', 'vat_number' => 'DE123456789', 'country' => 'DE',
        'address_line1' => 'Hauptstraße 1', 'postal_code' => '10115', 'city' => 'Berlin', 'payment_terms_days' => 30,
        'foreign_sale' => 'EU_GOODS',
    ]);
    $invoice = app(IssueInvoice::class)($this->owner, audFaktDraft($german, [
        ['description' => 'Maskindel', 'quantity' => '4', 'price' => '2 500', 'vat_rate' => 2500],
    ]));
    $html = InvoicePdf::html($invoice);

    expect($invoice->vat_ore->ore)->toBe(0)
        ->and(audFaktLines($invoice->voucher_id))->toBe([1510 => 1_000_000, 3108 => -1_000_000])
        ->and($html)->toContain('undantagen från moms')
        ->and($html)->toContain('DE123456789')
        ->and($html)->toContain('SE556334368901');
});

/* ---------------------------------------------------------------------------
 | Kontantmetoden (ML 15 kap. 16 §)
 * ------------------------------------------------------------------------- */

it('ML 15 kap. 16 §: under kontantmetoden nothing is booked at issue and a full payment books revenue and the invoice\'s VAT', function () {
    $this->company->forceFill(['accounting_method' => AccountingMethod::Kontantmetoden])->save();

    // 2 × 450 = 900 + 25 % 225 = 1 125 kr.
    $invoice = app(IssueInvoice::class)($this->owner, audFaktDraft($this->customer, [
        ['description' => 'Fönsterputs', 'quantity' => '2', 'price' => '450', 'vat_rate' => 2500],
    ]));

    expect($invoice->voucher_id)->toBeNull();

    app(RegisterPayment::class)($this->owner, $invoice, CarbonImmutable::parse('2026-03-20'), Money::ofOre(112_500));

    expect(audFaktBalance(1930))->toBe(112_500)
        ->and(audFaktBalance(2611))->toBe(-22_500)
        ->and(audFaktRange(3000, 3999))->toBe(-90_000)
        ->and(audFaktBalance(1510))->toBe(0);
});

it('ML 15 kap. 16 §: under kontantmetoden part payments together book exactly the VAT on the invoice', function () {
    $this->company->forceFill(['accounting_method' => AccountingMethod::Kontantmetoden])->save();

    // 100 kr + 25 kr VAT = 125 kr, paid 41,67 + 41,67 + 41,66.
    // Each share rounded on its own: VAT 8,334→8,33, 8,334→8,33, 8,332→8,33 = 24,99, not 25,00.
    $invoice = app(IssueInvoice::class)($this->owner, audFaktDraft($this->customer, [
        ['description' => 'Tjänst', 'quantity' => '1', 'price' => '100', 'vat_rate' => 2500],
    ]));

    foreach ([4_167, 4_167, 4_166] as $day => $ore) {
        app(RegisterPayment::class)($this->owner, $invoice->fresh(), CarbonImmutable::parse('2026-03-1'.($day + 1)), Money::ofOre($ore));
    }

    expect($invoice->fresh()->status)->toBe(InvoiceStatus::Paid)
        ->and(audFaktBalance(2611))->toBe(-2_500)
        ->and(audFaktRange(3000, 3999))->toBe(-10_000);
}); // AUD-FAKT-3 fixed (#52): the payment that settles the invoice takes what is left of each line.

it('ML 15 kap. 29 §: an intra-Community supply of goods is reported for the period the invoice is issued, also under kontantmetoden', function () {
    $this->company->forceFill(['accounting_method' => AccountingMethod::Kontantmetoden])->save();
    $german = app(SaveCustomer::class)($this->owner, $this->company, [
        'name' => 'Abnehmer GmbH', 'vat_number' => 'DE123456789', 'country' => 'DE',
        'address_line1' => 'Hauptstraße 1', 'postal_code' => '10115', 'city' => 'Berlin', 'payment_terms_days' => 60,
        'foreign_sale' => 'EU_GOODS',
    ]);

    app(IssueInvoice::class)($this->owner, audFaktDraft($german, [
        ['description' => 'Maskindel', 'quantity' => '4', 'price' => '2 500', 'vat_rate' => 2500],
    ]));

    // The sale (box 35 / periodisk sammanställning) belongs to March even though nothing is paid yet.
    expect(audFaktBalance(3108))->toBe(-1_000_000);
}); // AUD-FAKT-4 fixed (#50): EU sales are booked at issue under kontantmetoden too (ML 2023:200 7 kap. 29-30 §§).

/* ---------------------------------------------------------------------------
 | Invoice content deviations
 * ------------------------------------------------------------------------- */

it('ML 17 kap. 24 § p. 6: an invoice to a business needs the buyer\'s full address', function () {
    $noAddress = app(SaveCustomer::class)($this->owner, $this->company, ['name' => 'Adresslösa AB', 'org_number' => '5560160680', 'payment_terms_days' => 30]);

    expect(fn () => app(IssueInvoice::class)($this->owner, audFaktDraft($noAddress, [
        ['description' => 'Konsulttimme', 'quantity' => '50', 'price' => '1 000', 'vat_rate' => 2500],
    ])))->toThrow(ValidationException::class);
})->todo('AUD-FAKT-7: a full invoice (over 4 000 kr, ML 17 kap. 26 §) is issued to a business without the buyer\'s address');

it('ML 17 kap. 24 § p. 13: a domestic row at 0 % must refer to the exemption', function () {
    $invoice = app(IssueInvoice::class)($this->owner, audFaktDraft($this->customer, [
        ['description' => 'Uthyrning av lokal', 'quantity' => '1', 'price' => '10 000', 'vat_rate' => 0],
    ]));

    expect(InvoicePdf::html($invoice))->toMatch('/undantag|10 kap\.|artikel 13[0-9]/i');
})->todo('AUD-FAKT-6: a domestic invoice with 0 % VAT carries no reference to the exemption (ML 17 kap. 24 § p. 13)');

it('ML 17 kap. 28 § p. 6 and 16 kap.: a company that is not VAT registered cannot charge VAT on its invoices', function () {
    $this->company->forceFill(['vat_registered' => false, 'vat_number' => null])->save();

    expect(fn () => app(IssueInvoice::class)($this->owner, audFaktDraft($this->customer, [
        ['description' => 'Konsulttimme', 'quantity' => '1', 'price' => '1 000', 'vat_rate' => 2500],
    ])))->toThrow(ValidationException::class);
})->todo('AUD-FAKT-5: a company without VAT registration issues invoices with 25 % VAT (felaktigt debiterad moms, ML 2 kap. 12 §) and without the exemption reference');

it('ML 17 kap. 22-23 §§: a price reduction after payment is made with a credit note referring to the paid invoice', function () {
    $invoice = app(IssueInvoice::class)($this->owner, audFaktDraft($this->customer, [
        ['description' => 'Konsulttimme', 'quantity' => '10', 'price' => '1 000', 'vat_rate' => 2500],
    ]));
    app(RegisterPayment::class)($this->owner, $invoice, CarbonImmutable::parse('2026-03-10'), Money::ofOre(1_250_000));

    $credit = app(CreditInvoice::class)($this->owner, $invoice->fresh());

    expect($credit->isCredit())->toBeTrue()
        ->and($credit->credits_invoice_id)->toBe($invoice->id);

    // AUD-FAKT-8 fixed (#53): issued, the 12 500 kr credit is a credit balance for the customer, and 1510 agrees with the ledger.
    app(IssueInvoice::class)($this->owner, $credit);

    expect(audFaktBalance(1510))->toBe(-1_250_000)
        ->and(audFaktReceivablesLedger())->toBe(-1_250_000)
        ->and(audFaktBalance(2611))->toBe(0);
});

/* ---------------------------------------------------------------------------
 | ROT/RUT (IL 67 kap. 11-19 §§, lag 2009:194) and 1513
 * ------------------------------------------------------------------------- */

it('ROT: 30 % of the labour cost including VAT, rounded down to whole kronor, is moved from 1510 to 1513', function () {
    $household = app(SaveCustomer::class)($this->owner, $this->company, [
        'name' => 'Anna Andersson', 'personal_number' => '19121212-1212', 'payment_terms_days' => 30,
        'address_line1' => 'Villavägen 2', 'postal_code' => '752 36', 'city' => 'Uppsala',
    ]);
    // Labour 3 × 333 = 999 + 249,75 VAT = 1 248,75 → 30 % = 374,625 → 374 kr.
    // Invoice total 1 248,75 → 1 249 kr (öresavrundning) − 374 = 875 kr to pay.
    $invoice = app(IssueInvoice::class)($this->owner, audFaktDraft($household, [
        ['description' => 'Målning, arbete', 'quantity' => '3', 'price' => '333', 'vat_rate' => 2500, 'labour' => true],
    ], ['tax_reduction' => 'ROT', 'property_designation' => 'Uppsala Kåbo 1:23']));

    expect($invoice->tax_reduction_ore->ore)->toBe(37_400)
        ->and($invoice->total_ore->ore)->toBe(87_500)
        ->and(audFaktLines($invoice->voucher_id))->toMatchArray([1510 => 87_500, 1513 => 37_400, 2611 => -24_975, 3740 => -25]);
});

it('ROT credit: crediting an invoice whose deduction was capped by the ceiling leaves 1510 and 1513 at zero', function () {
    $household = app(SaveCustomer::class)($this->owner, $this->company, [
        'name' => 'Bertil Berg', 'personal_number' => '19121212-1212', 'payment_terms_days' => 30,
        'address_line1' => 'Villavägen 2', 'postal_code' => '752 36', 'city' => 'Uppsala',
    ]);
    // Labour 160 000 + 25 % = 200 000 kr; 30 % = 60 000 kr, capped at the 50 000 kr ROT ceiling.
    // Total 200 000 − 50 000 = 150 000 kr. A full credit must reverse exactly that: −150 000 on 1510, −50 000 on 1513.
    $invoice = app(IssueInvoice::class)($this->owner, audFaktDraft($household, [
        ['description' => 'Tillbyggnad, arbete', 'quantity' => '1', 'price' => '160 000', 'vat_rate' => 2500, 'labour' => true],
    ], ['tax_reduction' => 'ROT', 'property_designation' => 'Uppsala Kåbo 1:24']));

    expect($invoice->tax_reduction_ore->ore)->toBe(5_000_000)
        ->and($invoice->total_ore->ore)->toBe(15_000_000);

    app(IssueInvoice::class)($this->owner, app(CreditInvoice::class)($this->owner, $invoice));

    expect(audFaktBalance(1513))->toBe(0)
        ->and(audFaktBalance(1510))->toBe(0)
        ->and(audFaktBalance(1510))->toBe(audFaktReceivablesLedger());
}); // AUD-FAKT-1 fixed (#48): the credit takes the original's deduction, not a recomputed one.

it('ROT credit: an invoice whose deduction Skatteverket has paid cannot be credited as if it were unpaid', function () {
    $household = app(SaveCustomer::class)($this->owner, $this->company, [
        'name' => 'Cecilia Ceder', 'personal_number' => '19121212-1212', 'payment_terms_days' => 30,
        'address_line1' => 'Villavägen 2', 'postal_code' => '752 36', 'city' => 'Uppsala',
    ]);
    $invoice = app(IssueInvoice::class)($this->owner, audFaktDraft($household, [
        ['description' => 'Badrum, arbete', 'quantity' => '10', 'price' => '500', 'vat_rate' => 2500, 'labour' => true],
    ], ['tax_reduction' => 'ROT', 'property_designation' => 'Uppsala Kåbo 1:25']));
    app(RegisterTaxReductionPayment::class)($this->owner, $invoice, CarbonImmutable::parse('2026-03-20'), $invoice->tax_reduction_ore);

    // AUD-FAKT-9 fixed (#49), as the verifier recommended: the credit is allowed, and the 1 875 kr
    // Skatteverket paid becomes a debt to Skatteverket on 2890 (lag 2009:194 13-14 §§) instead of a negative 1513.
    $credit = app(IssueInvoice::class)($this->owner, app(CreditInvoice::class)($this->owner, $invoice->fresh()));

    expect($credit->tax_reduction_repayable_ore->ore)->toBe(187_500)
        ->and(audFaktBalance(1513))->toBe(0)
        ->and(audFaktBalance(2890))->toBe(-187_500)
        ->and(audFaktBalance(1510))->toBe(audFaktReceivablesLedger());
});

/* ---------------------------------------------------------------------------
 | Kundreskontra reconciles with 1510 (BFL 4 kap. 1 §, 5 kap. 1 §)
 * ------------------------------------------------------------------------- */

it('Kundreskontra: open invoices equal the balance on 1510 after issue, part payment, payment and credit', function () {
    $a = app(IssueInvoice::class)($this->owner, audFaktDraft($this->customer, [['description' => 'A', 'quantity' => '1', 'price' => '1 000', 'vat_rate' => 2500]]));
    $b = app(IssueInvoice::class)($this->owner, audFaktDraft($this->customer, [['description' => 'B', 'quantity' => '3', 'price' => '199,90', 'vat_rate' => 1200]]));
    $c = app(IssueInvoice::class)($this->owner, audFaktDraft($this->customer, [['description' => 'C', 'quantity' => '1', 'price' => '80', 'vat_rate' => 600]]));

    app(RegisterPayment::class)($this->owner, $a, CarbonImmutable::parse('2026-03-05'), Money::ofOre(50_000));
    app(RegisterPayment::class)($this->owner, $c, CarbonImmutable::parse('2026-03-06'), $c->total_ore);
    app(IssueInvoice::class)($this->owner, app(CreditInvoice::class)($this->owner, $b));

    // A: 1 250 − 500 = 750 kr open; B credited; C paid.
    expect(audFaktReceivablesLedger())->toBe(75_000)
        ->and(audFaktBalance(1510))->toBe(75_000);
});

/* ---------------------------------------------------------------------------
 | Leverantörsreskontra reconciles with 2440; attest
 * ------------------------------------------------------------------------- */

it('Leverantörsreskontra: unpaid supplier invoices equal 2440, costs and input VAT on 2641 (fakturametoden)', function () {
    $this->company->forceFill(['separate_supplier_approval' => true])->save();
    $approver = Bookkeeping::member($this->company, 'approver');
    $this->actingAs($this->owner);
    $supplier = app(SaveSupplier::class)($this->owner, $this->company, ['name' => 'Grossisten AB', 'bankgiro' => '5050-1055', 'payment_terms_days' => 30, 'default_account' => 4010]);

    $draft = app(SaveSupplierInvoice::class)($this->owner, $this->company, [
        'supplier_id' => $supplier->id, 'invoice_number' => 'G-1', 'invoice_date' => '2026-03-01',
        'total' => '1 250,00', 'vat' => '250,00', 'rows' => [['account' => '4010', 'amount' => '1 000,00']],
    ]);
    $registered = app(RegisterSupplierInvoice::class)($this->owner, $draft);

    // AGENTS.md §4 segregation of duties: the one who registered may not approve.
    expect(fn () => app(ApproveSupplierInvoice::class)($this->owner, $registered))->toThrow(ValidationException::class)
        ->and(audFaktLines($registered->voucher_id))->toBe([2440 => -125_000, 2641 => 25_000, 4010 => 100_000]);

    $approved = app(ApproveSupplierInvoice::class)($approver, $registered);
    app(PaySupplierInvoice::class)($this->owner, $approved, CarbonImmutable::parse('2026-03-20'), Money::ofOre(40_000));

    $open = SupplierInvoice::query()->whereIn('status', [SupplierInvoiceStatus::Registered->value, SupplierInvoiceStatus::Approved->value])->get()
        ->sum(fn (SupplierInvoice $invoice): int => $invoice->remaining()->ore);

    expect($open)->toBe(85_000)
        ->and(-audFaktBalance(2440))->toBe(85_000)
        ->and(audFaktBalance(1930))->toBe(-40_000);
});

it('ML 2 kap./BAS: a purchase of services from another EU country books output VAT 2614 and input VAT 2645 on the base', function () {
    $this->company->forceFill(['separate_supplier_approval' => false])->save();
    $supplier = app(SaveSupplier::class)($this->owner, $this->company, ['name' => 'Software Ltd', 'country' => 'IE', 'payment_terms_days' => 30, 'default_account' => 4010]);

    $draft = app(SaveSupplierInvoice::class)($this->owner, $this->company, [
        'supplier_id' => $supplier->id, 'invoice_number' => 'IE-9', 'invoice_date' => '2026-03-01',
        'total' => '1 234,56', 'vat_treatment' => 'EU_SERVICES', 'rows' => [['amount' => '1 234,56', 'vat_rate' => '2500']],
    ]);
    $registered = app(RegisterSupplierInvoice::class)($this->owner, $draft);

    // 1 234,56 × 25 % = 308,64.
    expect(audFaktLines($registered->voucher_id))->toBe([2440 => -123_456, 2614 => -30_864, 2645 => 30_864, 4535 => 123_456]);
});

it('ML 15 kap. 26 §: VAT on an intra-Community acquisition of goods is reported for the period the invoice is issued, also under kontantmetoden', function () {
    $this->company->forceFill(['separate_supplier_approval' => false, 'accounting_method' => AccountingMethod::Kontantmetoden])->save();
    $supplier = app(SaveSupplier::class)($this->owner, $this->company, ['name' => 'Lieferant GmbH', 'country' => 'DE', 'payment_terms_days' => 60, 'default_account' => 4010]);

    $draft = app(SaveSupplierInvoice::class)($this->owner, $this->company, [
        'supplier_id' => $supplier->id, 'invoice_number' => 'DE-1', 'invoice_date' => '2026-03-01',
        'total' => '10 000,00', 'vat_treatment' => 'EU_GOODS', 'rows' => [['amount' => '10 000,00', 'vat_rate' => '2500']],
    ]);
    app(RegisterSupplierInvoice::class)($this->owner, $draft);

    // Unpaid, but box 20/30/48 belong to March: 2614 −2 500, 2645 +2 500.
    expect(audFaktBalance(2614))->toBe(-250_000)
        ->and(audFaktBalance(2645))->toBe(250_000);
}); // AUD-FAKT-4 fixed (#50): EU acquisitions of goods are booked at registration under kontantmetoden too (ML 2023:200 7 kap. 26, 40 §§).

it('ML 15 kap. 16 §/31 §: under kontantmetoden part payments of a supplier invoice together book exactly its input VAT', function () {
    $this->company->forceFill(['separate_supplier_approval' => false, 'accounting_method' => AccountingMethod::Kontantmetoden])->save();
    $supplier = app(SaveSupplier::class)($this->owner, $this->company, ['name' => 'Grossisten AB', 'bankgiro' => '5050-1055', 'payment_terms_days' => 30, 'default_account' => 4010]);
    $draft = app(SaveSupplierInvoice::class)($this->owner, $this->company, [
        'supplier_id' => $supplier->id, 'invoice_number' => 'G-2', 'invoice_date' => '2026-03-01',
        'total' => '125,00', 'vat' => '25,00', 'rows' => [['account' => '4010', 'amount' => '100,00']],
    ]);
    $invoice = app(ApproveSupplierInvoice::class)($this->owner, app(RegisterSupplierInvoice::class)($this->owner, $draft));

    foreach ([4_167, 4_167, 4_166] as $day => $ore) {
        app(PaySupplierInvoice::class)($this->owner, $invoice->fresh(), CarbonImmutable::parse('2026-03-1'.($day + 1)), Money::ofOre($ore));
    }

    expect(audFaktBalance(2641))->toBe(2_500)
        ->and(audFaktBalance(4010))->toBe(10_000);
}); // AUD-FAKT-3 fixed (#52): the payment that settles the supplier invoice takes what is left of each row.
