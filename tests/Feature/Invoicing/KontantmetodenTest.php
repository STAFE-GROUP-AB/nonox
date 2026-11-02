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
