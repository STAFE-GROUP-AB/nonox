<?php

declare(strict_types=1);

use App\Actions\Accounting\CreateNextFiscalYear;
use App\Actions\Closing\YearEndClosing;
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
use App\Domain\Closing\ClosingChecklist;
use App\Domain\Closing\CurrencyRevaluation;
use App\Domain\Company\AccountingMethod;
use App\Domain\Invoicing\InvoiceStatus;
use App\Livewire\Accounting\ClosingPage;
use App\Models\AuditLog;
use App\Models\ClosingCheck;
use App\Models\FiscalYear;
use App\Models\Voucher;
use App\Models\VoucherRow;
use App\Models\YearEndEntry;
use App\Support\Money\Money;
use App\Support\Tenancy\CurrentCompany;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Tests\Support\Bookkeeping;

/*
 * Unrealised exchange differences at year-end (ADR-025): customer and
 * supplier invoices in another currency that are open on the last day are
 * valued at Riksbanken's rate that day, and the voucher is reversed on the
 * first day of the next year.
 */

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-11-02 10:00:00'));
    [$this->owner, $this->company] = Bookkeeping::company();
    CurrentCompany::set($this->company);
    $this->actingAs($this->owner);
    $this->company->forceFill(['separate_supplier_approval' => false])->save();
    $this->year = FiscalYear::query()->whereDate('start_date', '2026-01-01')->firstOrFail();

    $customer = app(SaveCustomer::class)($this->owner, $this->company, ['name' => 'Exempel Handel AB', 'payment_terms_days' => 30, 'currency' => 'EUR']);
    $this->invoice = app(IssueInvoice::class)($this->owner, app(SaveDraftInvoice::class)($this->owner, $this->company, [
        'customer_id' => $customer->id, 'invoice_date' => '2026-11-02', 'currency' => 'EUR', 'exchange_rate' => '11',
        'rows' => [['description' => 'Konsultarbete', 'quantity' => '1', 'price' => '1000', 'vat_rate' => 0]],
    ]));
    // 300 € of the 1 000 € are paid at the booking rate before the year ends.
    app(RegisterPayment::class)($this->owner, $this->invoice, CarbonImmutable::parse('2026-11-20'), Money::parse('3 300'), foreignAmount: 30_000);

    $supplier = app(SaveSupplier::class)($this->owner, $this->company, ['name' => 'Molnet Exempel Inc.', 'payment_terms_days' => 30, 'default_account' => 6540]);
    $this->supplierInvoice = app(ApproveSupplierInvoice::class)($this->owner, app(RegisterSupplierInvoice::class)($this->owner, app(SaveSupplierInvoice::class)($this->owner, $this->company, [
        'supplier_id' => $supplier->id, 'invoice_date' => '2026-11-05', 'total' => '1000', 'vat' => '0', 'vat_treatment' => 'DOMESTIC',
        'currency' => 'USD', 'foreign_total' => '100', 'exchange_rate' => '10',
        'rows' => [['account' => '6540', 'amount' => '1000', 'vat_rate' => '2500', 'description' => 'Servrar']],
    ])));

    // Riksbanken on the last day: a stronger euro (a gain on the receivable) and a weaker dollar (a gain on the liability).
    Http::fake(fn (Request $request) => Http::response([['date' => '2026-12-30', 'value' => str_contains($request->url(), 'SEKEURPMI') ? 11.5 : 9.0]]));
    $this->travelTo(CarbonImmutable::parse('2027-01-15 10:00:00'));
});

/** @return array<int, int> signed öre per account, debit positive */
function revaluationLines(Voucher $voucher): array
{
    return $voucher->load('rows.account')->rows
        ->mapWithKeys(fn ($row) => [$row->account->number => $row->debit_ore->ore - $row->credit_ore->ore])->sortKeys()->all();
}

it('values the open part of each invoice at the closing rate', function () {
    $revaluation = CurrencyRevaluation::for($this->company, $this->year);

    expect($revaluation['missing'])->toBe([])
        ->and(array_map(fn (array $line) => [$line['kind'], $line['currency'], $line['foreign'], $line['booked'], $line['closing'], $line['difference']], $revaluation['lines']))->toBe([
            // 700 € left, booked at 11 (7 700 kr), worth 8 050 kr at 11,5.
            ['receivable', 'EUR', 70_000, 770_000, 805_000, 35_000],
            // 100 $ owed, booked at 10 (1 000 kr), worth 900 kr at 9.
            ['payable', 'USD', 10_000, 100_000, 90_000, 10_000],
        ])
        ->and(CurrencyRevaluation::rows($revaluation['lines']))->toBe([
            ['account' => 1510, 'amount' => 35_000],
            ['account' => 2440, 'amount' => 10_000],
            ['account' => 3960, 'amount' => -45_000],
        ]);
});

it('books the revaluation on the last day and reverses it on the first day of the next year', function () {
    expect(collect(ClosingChecklist::for($this->company, $this->year))->firstWhere('key', 'currency')['status'])->toBe(ClosingChecklist::TODO)
        ->and(fn () => app(YearEndClosing::class)->bookCurrencyRevaluation($this->owner, $this->year))->toThrow(ValidationException::class, 'nästa räkenskapsår');

    app(CreateNextFiscalYear::class)($this->owner, $this->company);
    $entry = app(YearEndClosing::class)->bookCurrencyRevaluation($this->owner, $this->year);
    $vouchers = Voucher::query()->where('source_type', $this->year->getMorphClass())->where('source_id', (string) $this->year->id)->orderBy('voucher_date')->get();

    expect($entry->type)->toBe(YearEndEntry::CURRENCY_REVALUATION)
        ->and($entry->amount_ore->ore)->toBe(45_000)
        ->and($vouchers->map(fn (Voucher $voucher) => $voucher->voucher_date->toDateString())->all())->toBe(['2026-12-31', '2027-01-01'])
        ->and(revaluationLines($vouchers[0]))->toBe([1510 => 35_000, 2440 => 10_000, 3960 => -45_000])
        ->and(revaluationLines($vouchers[1]))->toBe([1510 => -35_000, 2440 => -10_000, 3960 => 45_000])
        ->and($vouchers[0]->description)->toContain('EUR 11,500000')->toContain('USD 9,000000')
        ->and(collect(ClosingChecklist::for($this->company, $this->year))->firstWhere('key', 'currency')['status'])->toBe(ClosingChecklist::OK)
        ->and(fn () => app(YearEndClosing::class)->bookCurrencyRevaluation($this->owner, $this->year))->toThrow(ValidationException::class, 'redan bokförd');

    // Paid in the new year: the payment settles the kronor booked on the invoice, so 1510 ends at zero.
    app(RegisterPayment::class)($this->owner, $this->invoice->fresh(), CarbonImmutable::parse('2027-01-20'), Money::parse('8 050'));

    expect((int) VoucherRow::query()->whereHas('account', fn ($query) => $query->where('number', 1510))->sum(DB::raw('debit_ore - credit_ore')))->toBe(0);
});

it('waits for the last day, and under kontantmetoden for the unpaid invoices to be booked first', function () {
    $this->travelTo(CarbonImmutable::parse('2026-12-20 10:00:00'));
    expect(fn () => app(YearEndClosing::class)->bookCurrencyRevaluation($this->owner, $this->year))->toThrow(ValidationException::class, 'efter årets sista dag');

    $this->travelTo(CarbonImmutable::parse('2027-01-15 10:00:00'));
    $this->company->forceFill(['accounting_method' => AccountingMethod::Kontantmetoden])->save();
    $currency = collect(ClosingChecklist::for($this->company->fresh(), $this->year))->firstWhere('key', 'currency');

    expect($currency['status'])->toBe(ClosingChecklist::TODO)
        ->and($currency['detail'])->toContain('obetalda fakturorna')
        ->and(fn () => app(YearEndClosing::class)->bookCurrencyRevaluation($this->owner, $this->year))->toThrow(ValidationException::class, 'obetalda fakturorna');
});

it('leaves the checklist point out without open invoices in another currency', function () {
    app(RegisterPayment::class)($this->owner, $this->invoice->fresh(), CarbonImmutable::parse('2026-12-15'), Money::parse('7 800'));
    app(PaySupplierInvoice::class)($this->owner, $this->supplierInvoice->fresh(), CarbonImmutable::parse('2026-12-15'), Money::parse('950'));

    expect(collect(ClosingChecklist::for($this->company, $this->year))->firstWhere('key', 'currency')['status'])->toBe(ClosingChecklist::NOT_APPLICABLE);
});

it('does not count an invoice paid before the year ended', function () {
    app(RegisterPayment::class)($this->owner, $this->invoice->fresh(), CarbonImmutable::parse('2026-12-15'), Money::parse('7 800'));

    expect(array_column(CurrencyRevaluation::for($this->company, $this->year)['lines'], 'kind'))->toBe(['payable'])
        ->and($this->invoice->fresh()->status)->toBe(InvoiceStatus::Paid);
});

it('books the revaluation from the closing page', function () {
    app(CreateNextFiscalYear::class)($this->owner, $this->company);

    Livewire::test(ClosingPage::class, ['year' => $this->year->id])
        ->set('year', $this->year->id)
        ->assertSee('Omvärdering av valutafordringar och valutaskulder')
        ->assertSee('700,00 EUR')
        ->call('bookCurrencyRevaluation')
        ->assertDispatched('toast', message: "Omvärderingen är bokförd: kursvinst 450,00\u{00A0}kr. Den återförs första dagen i nästa år.");
});

it('blocks the closing until the revaluation is booked or confirmed as booked by hand', function () {
    $currency = fn (): array => collect(ClosingChecklist::for($this->company, $this->year))->firstWhere('key', 'currency');

    expect($currency()['status'])->toBe(ClosingChecklist::TODO)
        ->and($currency()['confirm'])->toBe('Omvärderingen är bokförd för hand')
        ->and(ClosingChecklist::isComplete([$currency()]))->toBeFalse();

    app(YearEndClosing::class)->check($this->owner, $this->year, 'currency', true);
    $check = ClosingCheck::query()->where('fiscal_year_id', $this->year->id)->where('key', 'currency')->firstOrFail();

    expect($currency()['status'])->toBe(ClosingChecklist::OK)
        ->and($currency()['detail'])->toContain('för hand')
        ->and($check->checked_by)->toBe($this->owner->id)
        ->and($check->created_at)->not->toBeNull()
        ->and(AuditLog::query()->where('action', 'closing.checked')->where('payload->key', 'currency')->exists())->toBeTrue()
        ->and(ClosingChecklist::isComplete([$currency()]))->toBeTrue();

    app(YearEndClosing::class)->check($this->owner, $this->year, 'currency', false);

    expect($currency()['status'])->toBe(ClosingChecklist::TODO);
});

it('stays blocking when Riksbanken has no rate, until a person confirms the revaluation by hand', function () {
    Http::swap(new Factory);
    Http::fake(fn () => Http::response([]));
    $currency = collect(ClosingChecklist::for($this->company, $this->year))->firstWhere('key', 'currency');

    expect($currency['status'])->toBe(ClosingChecklist::TODO)
        ->and($currency['detail'])->toContain('ingen kurs');

    Livewire::test(ClosingPage::class, ['year' => $this->year->id])
        ->set('year', $this->year->id)
        ->assertSee('Omvärderingen är bokförd för hand')
        ->call('check', 'currency', true);

    expect(collect(ClosingChecklist::for($this->company, $this->year))->firstWhere('key', 'currency')['status'])->toBe(ClosingChecklist::OK);
});

it('values the rest of an invoice credited in part', function () {
    $customer = app(SaveCustomer::class)($this->owner, $this->company, ['name' => 'Delkredit Exempel AB', 'payment_terms_days' => 30, 'currency' => 'EUR']);
    $this->travelTo(CarbonImmutable::parse('2026-12-01 10:00:00'));
    $invoice = app(IssueInvoice::class)($this->owner, app(SaveDraftInvoice::class)($this->owner, $this->company, [
        'customer_id' => $customer->id, 'invoice_date' => '2026-12-01', 'currency' => 'EUR', 'exchange_rate' => '11',
        'rows' => [['description' => 'Licens', 'quantity' => '1', 'price' => '1000', 'vat_rate' => 0]],
    ]));
    $draft = app(CreditInvoice::class)($this->owner, $invoice);
    // 400 € of the 1 000 € are credited before the year ends.
    $credit = app(IssueInvoice::class)($this->owner, app(SaveDraftInvoice::class)($this->owner, $this->company, [
        'customer_id' => $customer->id, 'invoice_date' => '2026-12-01', 'due_date' => '2026-12-01',
        'rows' => [['description' => 'Licens', 'quantity' => '0,4', 'price' => '1000', 'vat_rate' => 0, 'account' => $draft->rows->load('account')->first()->account->number]],
    ], $draft));
    $this->travelTo(CarbonImmutable::parse('2027-01-15 10:00:00'));

    $line = collect(CurrencyRevaluation::for($this->company, $this->year)['lines'])->firstWhere('title', "{$invoice->title()}, Delkredit Exempel AB");

    expect($credit->total_ore->ore)->toBe(-440_000)
        // 600 € left, booked at 11 (6 600 kr), worth 6 900 kr at 11,5.
        ->and([$line['foreign'], $line['booked'], $line['closing'], $line['difference']])->toBe([60_000, 660_000, 690_000, 30_000]);
});
