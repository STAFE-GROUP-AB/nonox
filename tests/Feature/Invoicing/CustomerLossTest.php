<?php

declare(strict_types=1);

/*
 * Confirmed customer losses and the ledger as of a date (AUD-FAKT-12, #57).
 * Mervärdesskattelag (2023:200), read 2026-11-02: 8 kap. 16 § (the base is
 * reduced by a customer loss), 7 kap. 43 § (the VAT already reported may be
 * reduced, and is reported again when the money comes in after all) and
 * 44 § (in the period the loss is booked). Bokföringslagen 4 kap. 1 §,
 * 5 kap. 1 §: the customer ledger agrees with 1510.
 */

use App\Actions\Invoicing\IssueInvoice;
use App\Actions\Invoicing\RegisterPayment;
use App\Actions\Invoicing\SaveCustomer;
use App\Actions\Invoicing\SaveDraftInvoice;
use App\Actions\Invoicing\WriteOffInvoice;
use App\Actions\Payables\RegisterSupplierInvoice;
use App\Actions\Payables\SaveSupplier;
use App\Actions\Payables\SaveSupplierInvoice;
use App\Domain\Closing\ClosingChecklist;
use App\Domain\Closing\LedgerReconciliation;
use App\Domain\Company\AccountingMethod;
use App\Domain\Invoicing\InvoiceStatus;
use App\Livewire\Accounting\LedgerReport;
use App\Livewire\Invoicing\InvoiceActions;
use App\Models\FiscalYear;
use App\Models\Invoice;
use App\Support\Money\Money;
use App\Support\Tenancy\CurrentCompany;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Tests\Support\Bookkeeping;

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-03-02 10:00:00'));
    [$this->owner, $this->company] = Bookkeeping::company();
    CurrentCompany::set($this->company);
    $this->actingAs($this->owner);

    $customer = app(SaveCustomer::class)($this->owner, $this->company, [
        'name' => 'Konkursbolaget AB', 'org_number' => '5563343689',
        'address_line1' => 'Kundvägen 7', 'postal_code' => '211 20', 'city' => 'Malmö', 'payment_terms_days' => 30,
    ]);

    // 1 000 kr + 25 % = 1 250 kr.
    $this->issue = fn (): Invoice => app(IssueInvoice::class)($this->owner, app(SaveDraftInvoice::class)($this->owner, $this->company, [
        'customer_id' => $customer->id, 'invoice_date' => '2026-03-02',
        'rows' => [['description' => 'Konsulttimme', 'quantity' => '1', 'price' => '1 000', 'vat_rate' => 2500]],
    ]));
});

function lossBalance(int $account): int
{
    return (int) DB::table('voucher_rows')
        ->join('vouchers', 'vouchers.id', '=', 'voucher_rows.voucher_id')
        ->join('accounts', 'accounts.id', '=', 'voucher_rows.account_id')
        ->where('vouchers.company_id', CurrentCompany::id())
        ->where('accounts.number', $account)
        ->sum(DB::raw('voucher_rows.debit_ore - voucher_rows.credit_ore'));
}

it('writes off the open part with its share of the VAT and closes the invoice (ML 8 kap. 16 §, 7 kap. 43 §)', function () {
    $invoice = ($this->issue)();
    app(RegisterPayment::class)($this->owner, $invoice, CarbonImmutable::parse('2026-03-05'), Money::ofOre(25_000));

    // 1 000 kr open is 80 % of 1 250: VAT 250 × 0,8 = 200 kr back off 2611, 800 kr loss on 6351.
    $written = app(WriteOffInvoice::class)($this->owner, $invoice->fresh(), CarbonImmutable::parse('2026-03-20'), 'Kunden är försatt i konkurs');

    expect($written->status)->toBe(InvoiceStatus::WrittenOff)
        ->and($written->written_off_ore->ore)->toBe(100_000)
        ->and(lossBalance(1510))->toBe(0)
        ->and(lossBalance(2611))->toBe(-5_000)
        ->and(lossBalance(6351))->toBe(80_000)
        ->and(lossBalance(3001))->toBe(-100_000);

    // The ledger agrees with 1510 before and after the write-off.
    expect(LedgerReconciliation::on(LedgerReconciliation::CUSTOMERS, CarbonImmutable::parse('2026-03-10')))
        ->toMatchArray(['ledger' => 100_000, 'balance' => 100_000, 'difference' => 0])
        ->and(LedgerReconciliation::on(LedgerReconciliation::CUSTOMERS, CarbonImmutable::parse('2026-03-31')))
        ->toMatchArray(['ledger' => 0, 'balance' => 0, 'difference' => 0]);
});

it('reports the VAT again when the money comes in after all (ML 7 kap. 43 §)', function () {
    $invoice = app(WriteOffInvoice::class)($this->owner, ($this->issue)(), CarbonImmutable::parse('2026-03-20'), 'Konkurs');

    // Written off 1 250: 2611 +250, 6351 +1 000. Recovered 500 + 750: 2611 −100 −150, 6351 −400 −600.
    app(RegisterPayment::class)($this->owner, $invoice, CarbonImmutable::parse('2026-04-10'), Money::ofOre(50_000));

    expect(lossBalance(2611))->toBe(-10_000)
        ->and(lossBalance(6351))->toBe(60_000)
        ->and(lossBalance(1930))->toBe(50_000);

    app(RegisterPayment::class)($this->owner, $invoice->fresh(), CarbonImmutable::parse('2026-04-20'), Money::ofOre(75_000));

    expect(lossBalance(2611))->toBe(-25_000)
        ->and(lossBalance(6351))->toBe(0)
        ->and(lossBalance(1510))->toBe(0)
        ->and($invoice->fresh()->recovered_ore->ore)->toBe(125_000)
        ->and($invoice->fresh()->status)->toBe(InvoiceStatus::WrittenOff)
        ->and(LedgerReconciliation::on(LedgerReconciliation::CUSTOMERS, CarbonImmutable::parse('2026-04-30'))['ledger'])->toBe(0)
        ->and(fn () => app(RegisterPayment::class)($this->owner, $invoice->fresh(), CarbonImmutable::parse('2026-04-21'), Money::ofOre(100)))
        ->toThrow(AuthorizationException::class);
});

it('books nothing under kontantmetoden, where nothing was reported, but closes the invoice', function () {
    $this->company->forceFill(['accounting_method' => AccountingMethod::Kontantmetoden])->save();

    $invoice = app(WriteOffInvoice::class)($this->owner, ($this->issue)(), CarbonImmutable::parse('2026-03-20'), 'Konkurs');

    expect($invoice->write_off_voucher_id)->toBeNull()
        ->and($invoice->status)->toBe(InvoiceStatus::WrittenOff)
        ->and(lossBalance(2611))->toBe(0)
        ->and(lossBalance(6351))->toBe(0);
});

it('needs a reason and the right to credit', function () {
    $invoice = ($this->issue)();

    expect(fn () => app(WriteOffInvoice::class)($this->owner, $invoice, CarbonImmutable::parse('2026-03-20'), ' '))->toThrow(ValidationException::class);

    $auditor = Bookkeeping::member($this->company, 'auditor');

    expect(fn () => app(WriteOffInvoice::class)($auditor, $invoice, CarbonImmutable::parse('2026-03-20'), 'Konkurs'))->toThrow(AuthorizationException::class);
});

it('writes off from the invoice page', function () {
    $invoice = ($this->issue)();

    Livewire::test(InvoiceActions::class, ['invoice' => $invoice])
        ->set('writeOffOn', '2026-03-20')
        ->set('writeOffReason', 'Konkurs')
        ->call('writeOff')
        ->assertHasNoErrors()
        ->assertSee('Skrevs bort 2026-03-20');

    expect($invoice->fresh()->status)->toBe(InvoiceStatus::WrittenOff);
});

it('shows the customer and supplier ledgers against 1510 and 2440 on any day', function () {
    ($this->issue)();
    $supplier = app(SaveSupplier::class)($this->owner, $this->company, ['name' => 'Grossisten AB', 'bankgiro' => '5050-1055', 'payment_terms_days' => 30, 'default_account' => 4010]);
    app(RegisterSupplierInvoice::class)($this->owner, app(SaveSupplierInvoice::class)($this->owner, $this->company, [
        'supplier_id' => $supplier->id, 'invoice_number' => 'G-1', 'invoice_date' => '2026-03-01',
        'total' => '500,00', 'vat' => '100,00', 'rows' => [['account' => '4010', 'amount' => '400,00']],
    ]));

    Livewire::test(LedgerReport::class)
        ->set('date', '2026-03-31')
        ->assertSee('Konkursbolaget AB')
        ->assertSee('Reskontran stämmer med kontot.')
        ->set('side', LedgerReconciliation::SUPPLIERS)
        ->assertSee('Grossisten AB')
        ->assertSee('Reskontran stämmer med kontot.');

    expect(LedgerReconciliation::on(LedgerReconciliation::SUPPLIERS, CarbonImmutable::parse('2026-03-31')))
        ->toMatchArray(['ledger' => 50_000, 'balance' => 50_000, 'difference' => 0])
        ->and(LedgerReconciliation::on(LedgerReconciliation::CUSTOMERS, CarbonImmutable::parse('2026-03-01'))['ledger'])->toBe(0);

    $this->get(route('accounting.ledgers', ['typ' => 'kund', 'datum' => '2026-03-31']))->assertOk();
});

it('links the closing checklist to the ledger on the last day', function () {
    $year = FiscalYear::query()->whereDate('start_date', '2026-01-01')->firstOrFail();
    $items = collect(ClosingChecklist::for($this->company, $year))->keyBy('key');

    expect($items['receivables']['url'])->toBe(route('accounting.ledgers', ['typ' => 'kund', 'datum' => '2026-12-31']))
        ->and($items['payables']['url'])->toBe(route('accounting.ledgers', ['typ' => 'leverantor', 'datum' => '2026-12-31']));
});
