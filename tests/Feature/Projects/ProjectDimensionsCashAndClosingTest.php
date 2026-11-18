<?php

declare(strict_types=1);

/*
 * ADR-081 gap 6, E6 review fixes: dimensions on the kontantmetoden year-end
 * entry for unpaid customer invoices, on customer losses and recoveries, on
 * accounts whose rows net to zero, with a partial credit between payments
 * and with rows of mixed signs, and the project's default cost centre on
 * the server.
 */

use App\Actions\Accounting\CreateNextFiscalYear;
use App\Actions\Accounting\SaveDimensionObject;
use App\Actions\Accounting\SaveProjectDetails;
use App\Actions\Closing\YearEndClosing;
use App\Actions\Invoicing\CreditInvoice;
use App\Actions\Invoicing\IssueInvoice;
use App\Actions\Invoicing\RegisterPayment;
use App\Actions\Invoicing\SaveCustomer;
use App\Actions\Invoicing\SaveDraftInvoice;
use App\Actions\Invoicing\WriteOffInvoice;
use App\Domain\Accounting\AccountType;
use App\Domain\Accounting\Dimension;
use App\Domain\Company\AccountingMethod;
use App\Models\Account;
use App\Models\FiscalYear;
use App\Models\Invoice;
use App\Models\InvoiceRow;
use App\Models\Project;
use App\Models\Voucher;
use App\Models\VoucherRow;
use App\Models\YearEndEntry;
use App\Support\Money\Money;
use App\Support\Tenancy\CurrentCompany;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Tests\Support\Bookkeeping;

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-11-02 10:00:00'));
    [$this->owner, $this->company] = Bookkeeping::company();
    CurrentCompany::set($this->company);
    $this->actingAs($this->owner);

    foreach ([[Dimension::CostCenter, '10', 'Verkstad'], [Dimension::Project, 'P1', 'Ombyggnad'], [Dimension::Project, 'P2', 'Service']] as [$dimension, $code, $name]) {
        app(SaveDimensionObject::class)($this->owner, $this->company, $dimension, ['code' => $code, 'name' => $name]);
    }

    $this->customer = app(SaveCustomer::class)($this->owner, $this->company, ['name' => 'Kundbolaget AB', 'payment_terms_days' => 30]);
});

function e6cCash(): void
{
    test()->company->forceFill(['accounting_method' => AccountingMethod::Kontantmetoden])->save();
}

/** @param  list<array<string, mixed>>  $rows */
function e6cIssue(array $rows, string $date = '2026-11-10'): Invoice
{
    return app(IssueInvoice::class)(test()->owner, app(SaveDraftInvoice::class)(test()->owner, test()->company, [
        'customer_id' => test()->customer->id, 'invoice_date' => $date, 'rows' => $rows,
    ]));
}

function e6cPay(Invoice $invoice, int $ore, string $date = '2026-11-20'): Voucher
{
    $payment = app(RegisterPayment::class)(test()->owner, $invoice->fresh(), CarbonImmutable::parse($date), Money::ofOre($ore));

    return Voucher::query()->with('rows')->findOrFail($payment->voucher_id);
}

/** Signed sum (debit positive) on posted rows of an account, optionally one project or none. */
function e6cSum(int $account, string|false|null $project = false): int
{
    return (int) DB::table('voucher_rows')
        ->join('vouchers', 'vouchers.id', '=', 'voucher_rows.voucher_id')
        ->join('accounts', 'accounts.id', '=', 'voucher_rows.account_id')
        ->leftJoin('projects', 'projects.id', '=', 'voucher_rows.project_id')
        ->where('vouchers.company_id', CurrentCompany::id())
        ->where('vouchers.status', 'POSTED')
        ->where('accounts.number', $account)
        ->when($project !== false, fn ($query) => $project === null ? $query->whereNull('voucher_rows.project_id') : $query->where('projects.code', $project))
        ->sum(DB::raw('voucher_rows.debit_ore - voucher_rows.credit_ore'));
}

function e6cBalanced(Voucher $voucher): bool
{
    return $voucher->rows->sum(fn (VoucherRow $row): int => $row->debit_ore->ore) === $voucher->rows->sum(fn (VoucherRow $row): int => $row->credit_ore->ore);
}

function e6cRequireProject(int $number): void
{
    Account::query()->firstOrCreate(['number' => $number], ['name' => "Konto {$number}", 'type' => AccountType::forNumber($number)]);
    Account::query()->where('number', $number)->update(['requires_project' => true]);
}

it('posts the year-end entry for an unpaid invoice on an account that requires a project, split per project', function () {
    e6cCash();
    e6cRequireProject(3001);
    // 1 000 on P1 and 500 on P2 at 25 %: 1 875 kr; 625 paid in the year, so 1 250 open on the last day.
    $invoice = e6cIssue([
        ['description' => 'A', 'quantity' => '1', 'price' => '1000', 'vat_rate' => 2500, 'project' => 'P1'],
        ['description' => 'B', 'quantity' => '1', 'price' => '500', 'vat_rate' => 2500, 'project' => 'P2'],
    ]);
    e6cPay($invoice, 62_500, '2026-12-10');

    $this->travelTo(CarbonImmutable::parse('2027-01-15 10:00:00'));
    app(CreateNextFiscalYear::class)($this->owner, $this->company);
    $year = FiscalYear::query()->whereDate('start_date', '2026-01-01')->firstOrFail();
    $entries = collect(app(YearEndClosing::class)->bookUnpaidInvoices($this->owner, $year))->keyBy('type');
    $voucher = Voucher::query()->with(['rows.account', 'rows.project'])->findOrFail($entries[YearEndEntry::UNPAID_RECEIVABLES]->voucher_id);

    // 3001 open: −150 000 × 125 000 / 187 500 = −100 000, split −66 667 (P1) and −33 333 (P2).
    $revenue = $voucher->rows->filter(fn (VoucherRow $row): bool => $row->account->number === 3001)
        ->mapWithKeys(fn (VoucherRow $row): array => [$row->project?->code ?? '' => $row->debit_ore->ore - $row->credit_ore->ore])->all();

    expect($revenue)->toBe(['P1' => -66_667, 'P2' => -33_333])
        ->and(e6cBalanced($voucher))->toBeTrue()
        ->and(e6cSum(3001, null))->toBe(0);
});

it('books the dimensions of an account whose rows net to zero on the settling payment', function () {
    e6cCash();
    // 3001: +1 000 on P1 and −1 000 on P2 net to zero; 3002: 500 at 12 %. Total 560 kr.
    $invoice = e6cIssue([
        ['description' => 'Arbete', 'quantity' => '1', 'price' => '1000', 'vat_rate' => 2500, 'project' => 'P1'],
        ['description' => 'Avräkning', 'quantity' => '-1', 'price' => '1000', 'vat_rate' => 2500, 'project' => 'P2'],
        ['description' => 'Material', 'quantity' => '1', 'price' => '500', 'vat_rate' => 1200],
    ]);

    $voucher = e6cPay($invoice, 56_000);

    expect(e6cBalanced($voucher))->toBeTrue()
        ->and(e6cSum(3001, 'P1'))->toBe(-100_000)
        ->and(e6cSum(3001, 'P2'))->toBe(100_000)
        ->and(e6cSum(3002))->toBe(-50_000);
});

it('ends each project at its rows with a partial credit between two payments', function () {
    e6cCash();
    // 100 on P1, 200 on P2 at 25 %: 375 kr.
    $invoice = e6cIssue([
        ['description' => 'A', 'quantity' => '1', 'price' => '100', 'vat_rate' => 2500, 'project' => 'P1'],
        ['description' => 'B', 'quantity' => '1', 'price' => '200', 'vat_rate' => 2500, 'project' => 'P2'],
    ]);
    $first = e6cPay($invoice, 10_000);

    // Credit 100 of P2's row: −125 kr.
    $draft = app(CreditInvoice::class)($this->owner, $invoice->fresh());
    $draft = app(SaveDraftInvoice::class)($this->owner, $this->company, [
        'customer_id' => $this->customer->id, 'invoice_date' => '2026-11-25',
        'rows' => [['description' => 'B', 'quantity' => '1', 'price' => '100', 'vat_rate' => 2500, 'project' => 'P2']],
    ], $draft);
    app(IssueInvoice::class)($this->owner, $draft);

    // 375 − 100 − 125 = 150 left.
    $last = e6cPay($invoice, 15_000, '2026-11-30');

    expect(e6cBalanced($first))->toBeTrue()
        ->and(e6cBalanced($last))->toBeTrue()
        ->and(e6cSum(3001, 'P1'))->toBe(-10_000)
        ->and(e6cSum(3001, 'P2'))->toBe(-10_000)
        ->and(e6cSum(3001, null))->toBe(0)
        ->and(e6cSum(2611))->toBe(-5_000);
});

it('ends each project at its rows when the rows of one account have mixed signs', function () {
    e6cCash();
    // 3001: +1 000 on P1, −200 on P2 (a deduction): 800 net, 1 000 kr with VAT.
    $invoice = e6cIssue([
        ['description' => 'Arbete', 'quantity' => '1', 'price' => '1000', 'vat_rate' => 2500, 'project' => 'P1'],
        ['description' => 'Avdrag', 'quantity' => '-1', 'price' => '200', 'vat_rate' => 2500, 'project' => 'P2'],
    ]);

    $first = e6cPay($invoice, 33_333);
    $last = e6cPay($invoice, 66_667, '2026-11-30');

    expect(e6cBalanced($first))->toBeTrue()
        ->and(e6cBalanced($last))->toBeTrue()
        ->and(e6cSum(3001, 'P1'))->toBe(-100_000)
        ->and(e6cSum(3001, 'P2'))->toBe(20_000)
        ->and(e6cSum(3001, null))->toBe(0);
});

it('splits a customer loss and its recoveries per project when the loss account requires one', function () {
    e6cRequireProject(6351);
    // Fakturametoden: 1 000 on P1 and 500 on P2 at 25 %, 1 875 kr, written off in full.
    $invoice = e6cIssue([
        ['description' => 'A', 'quantity' => '1', 'price' => '1000', 'vat_rate' => 2500, 'project' => 'P1'],
        ['description' => 'B', 'quantity' => '1', 'price' => '500', 'vat_rate' => 2500, 'project' => 'P2'],
    ]);
    app(WriteOffInvoice::class)($this->owner, $invoice->fresh(), CarbonImmutable::parse('2026-11-20'), 'Konkurs');

    // Loss 150 000 split 2 : 1; output VAT 37 500 back.
    expect(e6cSum(6351, 'P1'))->toBe(100_000)
        ->and(e6cSum(6351, 'P2'))->toBe(50_000)
        ->and(e6cSum(2611))->toBe(0);

    $first = e6cPay($invoice, 50_000, '2026-11-25');
    $last = e6cPay($invoice, 137_500, '2026-11-30');

    expect(e6cBalanced($first))->toBeTrue()
        ->and(e6cBalanced($last))->toBeTrue()
        ->and(e6cSum(6351, 'P1'))->toBe(0)
        ->and(e6cSum(6351, 'P2'))->toBe(0)
        ->and(e6cSum(6351, null))->toBe(0)
        ->and(e6cSum(2611))->toBe(-37_500);
});

it('gives a row with a project and no cost centre the project\'s default cost centre on the server', function () {
    $project = Project::query()->where('code', 'P1')->sole();
    app(SaveProjectDetails::class)($this->owner, $this->company, $project, ['status' => 'ACTIVE', 'default_cost_center' => '10']);

    $draft = app(SaveDraftInvoice::class)($this->owner, $this->company, [
        'customer_id' => $this->customer->id, 'invoice_date' => '2026-11-10', 'rows' => [
            ['description' => 'Utan kostnadsställe', 'quantity' => '1', 'price' => '100', 'vat_rate' => 2500, 'project' => 'P1'],
            ['description' => 'Uttryckligen inget', 'quantity' => '1', 'price' => '100', 'vat_rate' => 2500, 'project' => 'P1', 'cost_center' => ''],
        ],
    ])->load(['rows.costCenter']);

    expect($draft->rows->map(fn (InvoiceRow $row): ?string => $row->costCenter?->code)->all())->toBe(['10', null]);
});
