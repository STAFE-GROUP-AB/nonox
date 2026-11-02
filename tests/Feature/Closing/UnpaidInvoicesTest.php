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
use App\Domain\Accounting\VoucherStatus;
use App\Domain\Closing\ClosingChecklist;
use App\Domain\Closing\UnpaidInvoices;
use App\Domain\Company\AccountingMethod;
use App\Livewire\Accounting\ClosingPage;
use App\Models\AuditLog;
use App\Models\Company;
use App\Models\FiscalYear;
use App\Models\Invoice;
use App\Models\SupplierInvoice;
use App\Models\User;
use App\Models\Voucher;
use App\Models\VoucherRow;
use App\Models\YearEndEntry;
use App\Support\Money\Money;
use App\Support\Tenancy\CurrentCompany;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Tests\Support\Bookkeeping;

/*
 * Kontantmetoden at year-end (BFL 5 kap. 2 §, ADR-013): the customer and
 * supplier invoices unpaid on the last day are booked then, as fakturametoden
 * would have booked them, and reversed on the first day of the next year.
 */

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-11-02 10:00:00'));
    // Riksbanken: 11,5 kr per euro on the last day.
    Http::fake(fn (Request $request) => Http::response([['date' => '2026-12-30', 'value' => 11.5]]));
    [$this->owner, $this->company] = Bookkeeping::company();
    $this->company->forceFill(['accounting_method' => AccountingMethod::Kontantmetoden, 'separate_supplier_approval' => false])->save();
    CurrentCompany::set($this->company);
    $this->actingAs($this->owner);
    $this->closing = app(YearEndClosing::class);
    $this->year = FiscalYear::query()->whereDate('start_date', '2026-01-01')->firstOrFail();

    // 1 000 kr at 25 % and 500 kr at 12 %: 1 810 kr, half of it paid before the year ends.
    $this->invoice = unpaidCustomerInvoice($this->owner, $this->company, 'Exempel Konsult AB', [
        ['description' => 'Rådgivning', 'quantity' => '1', 'price' => '1000', 'vat_rate' => 2500],
        ['description' => 'Kurslitteratur', 'quantity' => '1', 'price' => '500', 'vat_rate' => 1200],
    ]);
    app(RegisterPayment::class)($this->owner, $this->invoice, CarbonImmutable::parse('2026-12-10'), Money::parse('905'));

    // 1 000 kr plus 250 kr VAT, not paid at all.
    $this->supplierInvoice = unpaidSupplierInvoice($this->owner, $this->company);

    $this->travelTo(CarbonImmutable::parse('2027-01-15 10:00:00'));
});

/** @param  list<array<string, mixed>>  $rows */
function unpaidCustomerInvoice(User $user, Company $company, string $name, array $rows, array $extra = []): Invoice
{
    $customer = app(SaveCustomer::class)($user, $company, ['name' => $name, 'payment_terms_days' => 30, ...(isset($extra['currency']) ? ['currency' => $extra['currency']] : [])]);

    return app(IssueInvoice::class)($user, app(SaveDraftInvoice::class)($user, $company, ['customer_id' => $customer->id, 'invoice_date' => '2026-11-10', 'rows' => $rows, ...$extra]));
}

function unpaidSupplierInvoice(User $user, Company $company): SupplierInvoice
{
    $supplier = app(SaveSupplier::class)($user, $company, ['name' => 'Kontorsmaterial Exempel AB', 'payment_terms_days' => 30, 'default_account' => 6540]);

    return app(ApproveSupplierInvoice::class)($user, app(RegisterSupplierInvoice::class)($user, app(SaveSupplierInvoice::class)($user, $company, [
        'supplier_id' => $supplier->id, 'invoice_date' => '2026-11-20', 'total' => '1250', 'vat' => '250', 'vat_treatment' => 'DOMESTIC',
        'rows' => [['account' => '6540', 'amount' => '1000', 'vat_rate' => '2500', 'description' => 'Programvara']],
    ])));
}

/** @return array<int, int> signed öre per account, debit positive */
function unpaidLines(Voucher $voucher): array
{
    return $voucher->load('rows.account')->rows
        ->groupBy(fn ($row) => $row->account->number)
        ->map(fn ($rows) => (int) $rows->sum(fn ($row) => $row->debit_ore->ore - $row->credit_ore->ore))->sortKeys()->all();
}

/** Debit minus credit on an account in the posted vouchers of a fiscal year. */
function unpaidBalance(int $number, FiscalYear $year): int
{
    return (int) VoucherRow::query()
        ->whereHas('account', fn ($query) => $query->where('number', $number))
        ->whereHas('voucher', fn ($query) => $query->where('fiscal_year_id', $year->id)->where('status', VoucherStatus::Posted->value))
        ->sum(DB::raw('debit_ore - credit_ore'));
}

function unpaidItem(Company $company, FiscalYear $year, string $key = 'unpaid'): array
{
    return collect(ClosingChecklist::for($company, $year))->firstWhere('key', $key);
}

it('lists the unpaid invoices and blocks the closing until they are booked', function () {
    $unpaid = UnpaidInvoices::for($this->company, $this->year);
    $item = unpaidItem($this->company, $this->year);

    expect(array_column($unpaid['customer'], 'open'))->toBe([90_500])
        ->and(array_column($unpaid['supplier'], 'open'))->toBe([125_000])
        ->and($unpaid['customerRows'])->toBe([
            ['account' => 1510, 'amount' => 90_500],
            ['account' => 2611, 'amount' => -12_500],
            ['account' => 2621, 'amount' => -3_000],
            ['account' => 3001, 'amount' => -50_000],
            ['account' => 3002, 'amount' => -25_000],
        ])
        ->and($unpaid['supplierRows'])->toBe([
            ['account' => 2440, 'amount' => -125_000],
            ['account' => 2641, 'amount' => 25_000],
            ['account' => 6540, 'amount' => 100_000],
        ])
        ->and($item['status'])->toBe(ClosingChecklist::TODO)
        ->and($item['confirm'])->toBe('Bokfört för hand')
        ->and(ClosingChecklist::isComplete([$item]))->toBeFalse();
});

it('books them on the last day and reverses them on the first day of the next year', function () {
    expect(fn () => $this->closing->bookUnpaidInvoices($this->owner, $this->year))->toThrow(ValidationException::class, 'nästa räkenskapsår');

    app(CreateNextFiscalYear::class)($this->owner, $this->company);
    $entries = collect($this->closing->bookUnpaidInvoices($this->owner, $this->year))->keyBy('type');
    $vouchers = Voucher::query()->where('source_type', $this->year->getMorphClass())->where('source_id', (string) $this->year->id)->with('series')->orderBy('voucher_date')->orderBy('number')->get();

    expect($entries[YearEndEntry::UNPAID_RECEIVABLES]->amount_ore->ore)->toBe(90_500)
        ->and($entries[YearEndEntry::UNPAID_PAYABLES]->amount_ore->ore)->toBe(125_000)
        ->and($vouchers->map(fn (Voucher $voucher) => $voucher->voucher_date->toDateString())->all())->toBe(['2026-12-31', '2026-12-31', '2027-01-01', '2027-01-01'])
        ->and($vouchers->every(fn (Voucher $voucher) => $voucher->status === VoucherStatus::Posted && $voucher->series->code === 'A'))->toBeTrue()
        ->and(unpaidLines($vouchers[0]))->toBe([1510 => 90_500, 2611 => -12_500, 2621 => -3_000, 3001 => -50_000, 3002 => -25_000])
        ->and(unpaidLines($vouchers[1]))->toBe([2440 => -125_000, 2641 => 25_000, 6540 => 100_000])
        ->and(unpaidLines($vouchers[2]))->toBe([1510 => -90_500, 2611 => 12_500, 2621 => 3_000, 3001 => 50_000, 3002 => 25_000])
        ->and(unpaidLines($vouchers[3]))->toBe([2440 => 125_000, 2641 => -25_000, 6540 => -100_000])
        ->and($vouchers->every(fn (Voucher $voucher) => array_sum(unpaidLines($voucher)) === 0))->toBeTrue()
        ->and(unpaidItem($this->company, $this->year)['status'])->toBe(ClosingChecklist::OK)
        ->and(AuditLog::query()->where('action', 'closing.unpaid_invoices_booked')->count())->toBe(1)
        ->and(fn () => $this->closing->bookUnpaidInvoices($this->owner, $this->year))->toThrow(ValidationException::class, 'redan bokförda')
        // A posted voucher is never changed.
        ->and(fn () => $vouchers[0]->forceFill(['description' => 'Ändrad'])->save())->toThrow(LogicException::class);
});

it('books the payment in the next year as usual, so revenue, costs and VAT are booked once', function () {
    $next = app(CreateNextFiscalYear::class)($this->owner, $this->company);
    $this->closing->bookUnpaidInvoices($this->owner, $this->year);

    app(RegisterPayment::class)($this->owner, $this->invoice->fresh(), CarbonImmutable::parse('2027-01-20'), Money::parse('905'));
    app(PaySupplierInvoice::class)($this->owner, $this->supplierInvoice->fresh(), CarbonImmutable::parse('2027-01-20'), Money::parse('1250'));

    expect(unpaidBalance(3001, $this->year))->toBe(-100_000)
        ->and(unpaidBalance(2611, $this->year))->toBe(-25_000)
        ->and(unpaidBalance(6540, $this->year))->toBe(100_000)
        ->and(unpaidBalance(1510, $this->year))->toBe(90_500)
        ->and(unpaidBalance(2440, $this->year))->toBe(-125_000)
        // The next year: the reversal and the payment cancel out on revenue, costs and VAT.
        ->and(unpaidBalance(3001, $next))->toBe(0)
        ->and(unpaidBalance(2611, $next))->toBe(0)
        ->and(unpaidBalance(6540, $next))->toBe(0)
        ->and(unpaidBalance(2641, $next))->toBe(0)
        ->and(unpaidBalance(1510, $next))->toBe(-90_500)
        ->and(unpaidBalance(2440, $next))->toBe(125_000);
});

it('does not apply under fakturametoden or when nothing is unpaid', function () {
    app(RegisterPayment::class)($this->owner, $this->invoice->fresh(), CarbonImmutable::parse('2026-12-20'), Money::parse('905'));
    app(PaySupplierInvoice::class)($this->owner, $this->supplierInvoice->fresh(), CarbonImmutable::parse('2026-12-20'), Money::parse('1250'));
    app(CreateNextFiscalYear::class)($this->owner, $this->company);

    expect(unpaidItem($this->company, $this->year)['status'])->toBe(ClosingChecklist::NOT_APPLICABLE)
        ->and(fn () => $this->closing->bookUnpaidInvoices($this->owner, $this->year))->toThrow(ValidationException::class, 'Inga kund- eller leverantörsfakturor');

    $this->company->forceFill(['accounting_method' => AccountingMethod::Fakturametoden])->save();

    expect(unpaidItem($this->company->fresh(), $this->year)['status'])->toBe(ClosingChecklist::NOT_APPLICABLE)
        ->and(fn () => $this->closing->bookUnpaidInvoices($this->owner, $this->year))->toThrow(ValidationException::class, 'kontantmetoden');
});

it('counts a payment after the last day as unpaid on the last day', function () {
    app(CreateNextFiscalYear::class)($this->owner, $this->company);
    app(PaySupplierInvoice::class)($this->owner, $this->supplierInvoice->fresh(), CarbonImmutable::parse('2027-01-05'), Money::parse('1250'));

    expect(array_column(UnpaidInvoices::for($this->company, $this->year)['supplier'], 'open'))->toBe([125_000]);
});

it('waits for the last day and comes before the year\'s result', function () {
    app(CreateNextFiscalYear::class)($this->owner, $this->company);
    $this->travelTo(CarbonImmutable::parse('2026-12-20 10:00:00'));

    expect(fn () => $this->closing->bookUnpaidInvoices($this->owner, $this->year))->toThrow(ValidationException::class, 'efter årets sista dag');

    $this->travelTo(CarbonImmutable::parse('2027-01-15 10:00:00'));
    $this->closing->bookTax($this->owner, $this->year, '0');
    $this->closing->bookResult($this->owner, $this->year);

    expect(fn () => $this->closing->bookUnpaidInvoices($this->owner, $this->year))->toThrow(ValidationException::class, 'resultat');
});

it('can be confirmed as booked by hand, and only by those who close the year', function () {
    $this->closing->check($this->owner, $this->year, 'unpaid', true);

    expect(unpaidItem($this->company, $this->year)['status'])->toBe(ClosingChecklist::OK)
        ->and(fn () => $this->closing->check($this->owner, $this->year, 'nonsense', true))->toThrow(ValidationException::class)
        ->and(fn () => $this->closing->bookUnpaidInvoices(Bookkeeping::member($this->company, 'auditor'), $this->year))->toThrow(AuthorizationException::class);
});

it('books the rest of an invoice credited in part', function () {
    $this->travelTo(CarbonImmutable::parse('2026-12-01 10:00:00'));
    $invoice = unpaidCustomerInvoice($this->owner, $this->company, 'Delkredit Exempel AB', [['description' => 'Licens', 'quantity' => '1', 'price' => '1000', 'vat_rate' => 2500]]);
    $draft = app(CreditInvoice::class)($this->owner, $invoice);
    app(IssueInvoice::class)($this->owner, app(SaveDraftInvoice::class)($this->owner, $this->company, [
        'customer_id' => $invoice->customer_id, 'invoice_date' => '2026-12-01', 'due_date' => '2026-12-01',
        'rows' => [['description' => 'Licens', 'quantity' => '0,4', 'price' => '1000', 'vat_rate' => 2500]],
    ], $draft));
    $this->travelTo(CarbonImmutable::parse('2027-01-15 10:00:00'));

    $unpaid = UnpaidInvoices::for($this->company, $this->year);

    // 1 250 kr less a credit of 500 kr: 750 kr, of which 600 kr revenue and 150 kr VAT.
    expect(collect($unpaid['customer'])->firstWhere('title', "{$invoice->title()}, Delkredit Exempel AB")['open'])->toBe(75_000)
        ->and(collect($unpaid['customerRows'])->firstWhere('account', 1510)['amount'])->toBe(90_500 + 75_000)
        ->and(collect($unpaid['customerRows'])->firstWhere('account', 3001)['amount'])->toBe(-50_000 - 60_000)
        ->and(collect($unpaid['customerRows'])->firstWhere('account', 2611)['amount'])->toBe(-12_500 - 15_000);
});

it('books an invoice in euro at its rate, and the revaluation then values it at the closing rate', function () {
    unpaidCustomerInvoice($this->owner, $this->company, 'Exempel GmbH', [['description' => 'Licens', 'quantity' => '1', 'price' => '1000', 'vat_rate' => 0]], ['currency' => 'EUR', 'exchange_rate' => '11']);
    app(CreateNextFiscalYear::class)($this->owner, $this->company);

    expect(fn () => $this->closing->bookCurrencyRevaluation($this->owner, $this->year))->toThrow(ValidationException::class, 'obetalda fakturorna')
        ->and(unpaidItem($this->company, $this->year, 'currency')['status'])->toBe(ClosingChecklist::TODO);

    $receivables = collect($this->closing->bookUnpaidInvoices($this->owner, $this->year))->firstWhere('type', YearEndEntry::UNPAID_RECEIVABLES);

    // 905 kr in kronor and 1 000 € at 11 kr.
    expect($receivables->amount_ore->ore)->toBe(90_500 + 1_100_000)
        ->and(unpaidItem($this->company, $this->year, 'currency')['status'])->toBe(ClosingChecklist::TODO);

    $revaluation = $this->closing->bookCurrencyRevaluation($this->owner, $this->year);

    // Worth 11 500 kr on the last day: a gain of 500 kr.
    expect($revaluation->amount_ore->ore)->toBe(50_000)
        ->and(unpaidBalance(1510, $this->year))->toBe(90_500 + 1_100_000 + 50_000)
        ->and(unpaidItem($this->company, $this->year, 'currency')['status'])->toBe(ClosingChecklist::OK);
});

it('books them from the closing page', function () {
    app(CreateNextFiscalYear::class)($this->owner, $this->company);

    Livewire::test(ClosingPage::class, ['year' => $this->year->id])
        ->set('year', $this->year->id)
        ->assertSee('Obetalda fakturor vid årets slut')
        ->assertSee('Bokfört för hand')
        ->call('bookUnpaidInvoices')
        ->assertDispatched('toast');

    expect(YearEndEntry::query()->where('fiscal_year_id', $this->year->id)->pluck('type')->sort()->values()->all())->toBe([YearEndEntry::UNPAID_PAYABLES, YearEndEntry::UNPAID_RECEIVABLES]);
});

it('never lists another company\'s invoices', function () {
    [$otherOwner, $other] = Bookkeeping::company();
    $other->forceFill(['accounting_method' => AccountingMethod::Kontantmetoden, 'separate_supplier_approval' => false])->save();
    CurrentCompany::set($other);
    $this->travelTo(CarbonImmutable::parse('2026-11-02 10:00:00'));
    unpaidCustomerInvoice($otherOwner, $other, 'Annan Kund AB', [['description' => 'Tjänst', 'quantity' => '1', 'price' => '7000', 'vat_rate' => 2500]]);
    $this->travelTo(CarbonImmutable::parse('2027-01-15 10:00:00'));

    // Asked about this company while the other one is current: the other's invoice is not there.
    $unpaid = UnpaidInvoices::for($this->company, $this->year);

    expect(array_column($unpaid['customer'], 'open'))->toBe([90_500])
        ->and(array_column($unpaid['supplier'], 'open'))->toBe([125_000]);
});
