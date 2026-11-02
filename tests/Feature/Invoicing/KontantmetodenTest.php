<?php

declare(strict_types=1);

/*
 * Kontantmetoden (Mervärdesskattelag (2023:200) 7 kap. 16 och 33 §§) and its
 * exceptions. Every expected value is computed by hand.
 *
 * - EU sales of goods (7 kap. 29 §) and services in the periodisk
 *   sammanställning (7 kap. 30 §), and intra-Community acquisitions of goods
 *   (7 kap. 26, 40 §§), are reported for the invoice's period whatever the
 *   method, so they are booked at issue/registration (AUD-FAKT-4, #50).
 * - Part payments together book exactly the invoice's revenue and VAT: the
 *   payment that settles the invoice takes what is left (AUD-FAKT-3, #52).
 */

use App\Actions\Invoicing\CreditInvoice;
use App\Actions\Invoicing\IssueInvoice;
use App\Actions\Invoicing\RegisterPayment;
use App\Actions\Invoicing\SaveCustomer;
use App\Actions\Invoicing\SaveDraftInvoice;
use App\Actions\Payables\ApproveSupplierInvoice;
use App\Actions\Payables\PaySupplierInvoice;
use App\Actions\Payables\RegisterSupplierInvoice;
use App\Actions\Payables\SaveSupplier;
use App\Actions\Payables\SaveSupplierInvoice;
use App\Domain\Closing\UnpaidInvoices;
use App\Domain\Company\AccountingMethod;
use App\Domain\Invoicing\InvoiceStatus;
use App\Models\Customer;
use App\Models\FiscalYear;
use App\Models\Invoice;
use App\Models\SupplierInvoice;
use App\Support\Money\Money;
use App\Support\Tenancy\CurrentCompany;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Tests\Support\Bookkeeping;

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-03-02 10:00:00'));
    [$this->owner, $this->company] = Bookkeeping::company();
    $this->company->forceFill([
        'accounting_method' => AccountingMethod::Kontantmetoden,
        'separate_supplier_approval' => false,
        'vat_number' => 'SE556334368901',
    ])->save();
    CurrentCompany::set($this->company);
    $this->actingAs($this->owner);
});

/** Signed balance (debit positive) of an account over the posted vouchers of the current company. */
function kontantBalance(int $account): int
{
    return (int) DB::table('voucher_rows')
        ->join('vouchers', 'vouchers.id', '=', 'voucher_rows.voucher_id')
        ->join('accounts', 'accounts.id', '=', 'voucher_rows.account_id')
        ->where('vouchers.company_id', CurrentCompany::id())
        ->where('vouchers.status', 'POSTED')
        ->where('accounts.number', $account)
        ->sum(DB::raw('voucher_rows.debit_ore - voucher_rows.credit_ore'));
}

function kontantEuCustomer(string $kind = 'EU_GOODS'): Customer
{
    return app(SaveCustomer::class)(test()->owner, test()->company, [
        'name' => 'Abnehmer GmbH', 'vat_number' => 'DE123456789', 'country' => 'DE',
        'address_line1' => 'Hauptstraße 1', 'postal_code' => '10115', 'city' => 'Berlin', 'payment_terms_days' => 60,
        'foreign_sale' => $kind,
    ]);
}

function kontantDomesticCustomer(): Customer
{
    return app(SaveCustomer::class)(test()->owner, test()->company, [
        'name' => 'Kundbolaget AB', 'org_number' => '5563343689',
        'address_line1' => 'Kundvägen 7', 'postal_code' => '211 20', 'city' => 'Malmö', 'payment_terms_days' => 30,
    ]);
}

/** @param  list<array<string, mixed>>  $rows */
function kontantIssue(Customer $customer, array $rows, string $date = '2026-03-02'): Invoice
{
    return app(IssueInvoice::class)(test()->owner, app(SaveDraftInvoice::class)(test()->owner, test()->company, [
        'customer_id' => $customer->id, 'invoice_date' => $date, 'rows' => $rows,
    ]));
}

/** @param  array<string, mixed>  $data */
function kontantSupplierInvoice(string $country, array $data): SupplierInvoice
{
    $supplier = app(SaveSupplier::class)(test()->owner, test()->company, ['name' => "Lieferant {$country}", 'country' => $country, 'payment_terms_days' => 60, 'default_account' => 4010]);

    return app(ApproveSupplierInvoice::class)(test()->owner, app(RegisterSupplierInvoice::class)(test()->owner, app(SaveSupplierInvoice::class)(test()->owner, test()->company, [
        'supplier_id' => $supplier->id, 'invoice_date' => '2026-03-01', ...$data,
    ])));
}

/* ---------------------------------------------------------------------------
 | AUD-FAKT-4 (#50): EU trade is reported in the invoice's period
 * ------------------------------------------------------------------------- */

it('books an EU sale of goods at issue under kontantmetoden, and the payment only clears 1510 (ML 7 kap. 29 §)', function () {
    // 4 × 2 500 = 10 000 kr, no VAT.
    $invoice = kontantIssue(kontantEuCustomer(), [['description' => 'Maskindel', 'quantity' => '4', 'price' => '2 500', 'vat_rate' => 2500]]);

    expect($invoice->voucher_id)->not->toBeNull()
        ->and(kontantBalance(1510))->toBe(1_000_000)
        ->and(kontantBalance(3108))->toBe(-1_000_000);

    app(RegisterPayment::class)($this->owner, $invoice, CarbonImmutable::parse('2026-04-20'), Money::ofOre(1_000_000));

    expect(kontantBalance(1510))->toBe(0)
        ->and(kontantBalance(1930))->toBe(1_000_000)
        ->and(kontantBalance(3108))->toBe(-1_000_000)
        ->and($invoice->fresh()->status)->toBe(InvoiceStatus::Paid);
});

it('books an EU sale of services at issue under kontantmetoden (ML 7 kap. 30 §)', function () {
    kontantIssue(kontantEuCustomer('EU_SERVICES'), [['description' => 'Konsultation', 'quantity' => '3', 'price' => '1 200', 'vat_rate' => 2500]]);

    // 3 × 1 200 = 3 600 kr on 3308 in March, unpaid.
    expect(kontantBalance(3308))->toBe(-360_000)
        ->and(kontantBalance(1510))->toBe(360_000);
});

it('books a credit of an EU sale at issue too, so 1510 nets to zero', function () {
    $invoice = kontantIssue(kontantEuCustomer(), [['description' => 'Maskindel', 'quantity' => '1', 'price' => '2 000', 'vat_rate' => 2500]]);
    app(IssueInvoice::class)($this->owner, app(CreditInvoice::class)($this->owner, $invoice));

    expect(kontantBalance(1510))->toBe(0)
        ->and(kontantBalance(3108))->toBe(0)
        ->and($invoice->fresh()->status)->toBe(InvoiceStatus::Credited);
});

it('still books a domestic sale only when paid under kontantmetoden (ML 7 kap. 16 §)', function () {
    $invoice = kontantIssue(kontantDomesticCustomer(), [['description' => 'Fönsterputs', 'quantity' => '1', 'price' => '800', 'vat_rate' => 2500]]);

    expect($invoice->voucher_id)->toBeNull()
        ->and(kontantBalance(1510))->toBe(0)
        ->and(kontantBalance(2611))->toBe(0);
});

it('books an intra-Community acquisition of goods at registration and the payment only clears 2440 (ML 7 kap. 26, 40 §§)', function () {
    $invoice = kontantSupplierInvoice('DE', ['invoice_number' => 'DE-1', 'total' => '10 000,00', 'vat_treatment' => 'EU_GOODS', 'rows' => [['amount' => '10 000,00', 'vat_rate' => '2500']]]);

    // 10 000 × 25 % = 2 500 kr output and input VAT in March.
    expect($invoice->voucher_id)->not->toBeNull()
        ->and(kontantBalance(2440))->toBe(-1_000_000)
        ->and(kontantBalance(4515))->toBe(1_000_000)
        ->and(kontantBalance(2614))->toBe(-250_000)
        ->and(kontantBalance(2645))->toBe(250_000);

    app(PaySupplierInvoice::class)($this->owner, $invoice, CarbonImmutable::parse('2026-04-20'), Money::ofOre(1_000_000));

    expect(kontantBalance(2440))->toBe(0)
        ->and(kontantBalance(1930))->toBe(-1_000_000)
        ->and(kontantBalance(2614))->toBe(-250_000)
        ->and(kontantBalance(4515))->toBe(1_000_000);
});

it('still books an EU purchase of services at payment under kontantmetoden (7 kap. 16 § andra st., 16 kap. 6 §)', function () {
    $invoice = kontantSupplierInvoice('IE', ['invoice_number' => 'IE-1', 'total' => '1 000,00', 'vat_treatment' => 'EU_SERVICES', 'rows' => [['amount' => '1 000,00', 'vat_rate' => '2500']]]);

    expect($invoice->voucher_id)->toBeNull()
        ->and(kontantBalance(2614))->toBe(0);
});

it('leaves EU invoices out of the unpaid invoices booked at year end, since they are on 1510 and 2440 already', function () {
    kontantIssue(kontantEuCustomer(), [['description' => 'Maskindel', 'quantity' => '1', 'price' => '2 000', 'vat_rate' => 2500]], '2026-11-10');
    kontantIssue(kontantDomesticCustomer(), [['description' => 'Fönsterputs', 'quantity' => '1', 'price' => '800', 'vat_rate' => 2500]], '2026-11-10');
    kontantSupplierInvoice('DE', ['invoice_number' => 'DE-2', 'invoice_date' => '2026-11-10', 'total' => '500,00', 'vat_treatment' => 'EU_GOODS', 'rows' => [['amount' => '500,00', 'vat_rate' => '2500']]]);

    $unpaid = UnpaidInvoices::for($this->company, FiscalYear::query()->whereDate('start_date', '2026-01-01')->firstOrFail());

    // Only the domestic 800 + 200 = 1 000 kr invoice is booked at year end.
    expect($unpaid['customer'])->toHaveCount(1)
        ->and(UnpaidInvoices::total($unpaid['customer']))->toBe(100_000)
        ->and($unpaid['supplier'])->toBe([]);
});

/* ---------------------------------------------------------------------------
 | AUD-FAKT-3 (#52): part payments together book exactly the invoice
 * ------------------------------------------------------------------------- */

it('lets the payment that settles a customer invoice take the rest of each line (ML 7 kap. 16 §, 17 kap. 24 § p. 11)', function () {
    // 100 kr at 12 % (VAT 12) and 50 kr at 6 % (VAT 3): 165 kr, paid 55 + 55 + 55.
    // A third each: 3001 33,333 → 33,33; 3002 16,666 → 16,67; 2621 4,00; 2631 1,00 (sum 55,00).
    // Three such shares would book 3001 99,99 and 3002 50,01; the last payment takes the rest instead.
    $invoice = kontantIssue(kontantDomesticCustomer(), [
        ['description' => 'Tjänst', 'quantity' => '1', 'price' => '100', 'vat_rate' => 1200, 'account' => 3001],
        ['description' => 'Bok', 'quantity' => '1', 'price' => '50', 'vat_rate' => 600, 'account' => 3002],
    ]);

    foreach ([5_500, 5_500, 5_500] as $day => $ore) {
        app(RegisterPayment::class)($this->owner, $invoice->fresh(), CarbonImmutable::parse('2026-03-1'.($day + 1)), Money::ofOre($ore));
    }

    expect($invoice->fresh()->status)->toBe(InvoiceStatus::Paid)
        ->and(kontantBalance(1930))->toBe(16_500)
        ->and(kontantBalance(3001))->toBe(-10_000)
        ->and(kontantBalance(3002))->toBe(-5_000)
        ->and(kontantBalance(2621))->toBe(-1_200)
        ->and(kontantBalance(2631))->toBe(-300);
});

it('takes the credited part off what the settling payment books', function () {
    // 100 kr + 25 % = 125 kr. 41,67 paid, then 25 kr (20 + 5 VAT) credited, then the rest, 58,33, paid.
    // Booked in total: 100 − 20 = 80 kr revenue and 25 − 5 = 20 kr VAT, the 100 kr received.
    $invoice = kontantIssue(kontantDomesticCustomer(), [['description' => 'Tjänst', 'quantity' => '1', 'price' => '100', 'vat_rate' => 2500]]);
    app(RegisterPayment::class)($this->owner, $invoice, CarbonImmutable::parse('2026-03-10'), Money::ofOre(4_167));

    $credit = app(SaveDraftInvoice::class)($this->owner, $this->company, [
        'customer_id' => $invoice->customer_id, 'invoice_date' => '2026-03-12', 'due_date' => '2026-03-12',
        'rows' => [['description' => 'Avdrag', 'quantity' => '1', 'price' => '20', 'vat_rate' => 2500]],
    ], app(CreditInvoice::class)($this->owner, $invoice->fresh()));
    app(IssueInvoice::class)($this->owner, $credit);

    expect($invoice->fresh()->remaining()->ore)->toBe(5_833);

    app(RegisterPayment::class)($this->owner, $invoice->fresh(), CarbonImmutable::parse('2026-03-20'), Money::ofOre(5_833));

    expect($invoice->fresh()->status)->toBe(InvoiceStatus::Paid)
        ->and(kontantBalance(1930))->toBe(10_000)
        ->and(kontantBalance(2611))->toBe(-2_000)
        ->and(kontantBalance(3001))->toBe(-8_000);
});

it('lets the payment that settles a supplier invoice take the rest of each row (ML 7 kap. 33 §)', function () {
    // 60 + 40 kr costs and 25 kr VAT = 125 kr, paid 41,67 + 41,67 + 41,66.
    // Pro rata: 4010 20,0016 → 20,00, 6540 13,3344 → 13,33, 2641 8,334 → 8,33, öre left on 4010 (20,01).
    // The last payment takes the rest: 4010 60 − 40,02 = 19,98, 6540 40 − 26,66 = 13,34, 2641 25 − 16,66 = 8,34.
    $invoice = kontantSupplierInvoice('SE', ['invoice_number' => 'G-3', 'total' => '125,00', 'vat' => '25,00', 'rows' => [
        ['account' => '4010', 'amount' => '60,00'],
        ['account' => '6540', 'amount' => '40,00'],
    ]]);

    foreach ([4_167, 4_167, 4_166] as $day => $ore) {
        app(PaySupplierInvoice::class)($this->owner, $invoice->fresh(), CarbonImmutable::parse('2026-03-1'.($day + 1)), Money::ofOre($ore));
    }

    expect(kontantBalance(1930))->toBe(-12_500)
        ->and(kontantBalance(2641))->toBe(2_500)
        ->and(kontantBalance(4010))->toBe(6_000)
        ->and(kontantBalance(6540))->toBe(4_000);
});
