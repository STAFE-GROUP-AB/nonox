<?php

declare(strict_types=1);

/*
 * ADR-081 gap 6, package E6: cost centre and project on invoice, quote and
 * order rows, defaults on the article and the customer, and the invoice and
 * kontantmetoden payment vouchers per dimension. Every expected value is
 * computed by hand in the comments.
 */

use App\Actions\Accounting\SaveDimensionObject;
use App\Actions\Invoicing\CreditInvoice;
use App\Actions\Invoicing\IssueInvoice;
use App\Actions\Invoicing\ProgressSalesDocument;
use App\Actions\Invoicing\RegisterPayment;
use App\Actions\Invoicing\SaveArticle;
use App\Actions\Invoicing\SaveCustomer;
use App\Actions\Invoicing\SaveDraftInvoice;
use App\Actions\Invoicing\SaveSalesDocument;
use App\Domain\Accounting\Dimension;
use App\Domain\Accounting\ImmutableVoucher;
use App\Domain\Company\AccountingMethod;
use App\Domain\Invoicing\InvoiceBooking;
use App\Livewire\Invoicing\InvoiceEditor;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\InvoiceRow;
use App\Models\SalesDocument;
use App\Models\Voucher;
use App\Models\VoucherRow;
use App\Support\Money\Money;
use App\Support\Tenancy\CurrentCompany;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Tests\Support\Bookkeeping;

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-03-02 10:00:00'));
    [$this->owner, $this->company] = Bookkeeping::company();
    CurrentCompany::set($this->company);
    $this->actingAs($this->owner);

    foreach ([[Dimension::CostCenter, '10', 'Butik Lund'], [Dimension::CostCenter, '20', 'Butik Ystad'], [Dimension::Project, 'P1', 'Ombyggnad'], [Dimension::Project, 'P2', 'Service']] as [$dimension, $code, $name]) {
        app(SaveDimensionObject::class)($this->owner, $this->company, $dimension, ['code' => $code, 'name' => $name]);
    }
});

function e6Customer(array $extra = []): Customer
{
    return app(SaveCustomer::class)(test()->owner, test()->company, [
        'name' => 'Kundbolaget AB', 'org_number' => '5563343689',
        'address_line1' => 'Kundvägen 7', 'postal_code' => '211 20', 'city' => 'Malmö', 'payment_terms_days' => 30,
        ...$extra,
    ]);
}

/** @param  list<array<string, mixed>>  $rows */
function e6Issue(Customer $customer, array $rows): Invoice
{
    return app(IssueInvoice::class)(test()->owner, app(SaveDraftInvoice::class)(test()->owner, test()->company, [
        'customer_id' => $customer->id, 'invoice_date' => '2026-03-02', 'rows' => $rows,
    ]));
}

/**
 * The voucher's rows as [account, signed öre, cost centre code, project code], in order.
 *
 * @return list<array{0: int, 1: int, 2: ?string, 3: ?string}>
 */
function e6Rows(?string $voucherId): array
{
    return Voucher::query()->with(['rows.account', 'rows.costCenter', 'rows.project'])->findOrFail($voucherId)->rows
        ->map(fn (VoucherRow $row): array => [$row->account->number, $row->debit_ore->ore - $row->credit_ore->ore, $row->costCenter?->code, $row->project?->code])
        ->values()->all();
}

/** Signed revenue (credit negative) on posted rows of one project. */
function e6ProjectRevenue(string $project, int $account = 3001): int
{
    return (int) DB::table('voucher_rows')
        ->join('vouchers', 'vouchers.id', '=', 'voucher_rows.voucher_id')
        ->join('accounts', 'accounts.id', '=', 'voucher_rows.account_id')
        ->join('projects', 'projects.id', '=', 'voucher_rows.project_id')
        ->where('vouchers.company_id', CurrentCompany::id())
        ->where('vouchers.status', 'POSTED')
        ->where('accounts.number', $account)
        ->where('projects.code', $project)
        ->sum(DB::raw('voucher_rows.debit_ore - voucher_rows.credit_ore'));
}

function e6Balanced(?string $voucherId): bool
{
    $rows = Voucher::query()->with('rows')->findOrFail($voucherId)->rows;

    return $rows->sum(fn (VoucherRow $row): int => $row->debit_ore->ore) === $rows->sum(fn (VoucherRow $row): int => $row->credit_ore->ore);
}

it('books the revenue on each row\'s cost centre and project, and VAT and the receivable on none', function () {
    // 2 × 1 000 on P1/10, 1 × 500 on P2, 1 × 252 without: net 2 752, VAT 25 % 688, total 3 440 kr (no rounding).
    $invoice = e6Issue(e6Customer(), [
        ['description' => 'Snickeri', 'quantity' => '2', 'price' => '1 000', 'vat_rate' => 2500, 'cost_center' => '10', 'project' => 'P1'],
        ['description' => 'Service', 'quantity' => '1', 'price' => '500', 'vat_rate' => 2500, 'project' => 'P2'],
        ['description' => 'Frakt', 'quantity' => '1', 'price' => '252', 'vat_rate' => 2500],
    ]);

    expect(e6Rows($invoice->voucher_id))->toBe([
        [1510, 344_000, null, null],
        [3001, -200_000, '10', 'P1'],
        [3001, -50_000, null, 'P2'],
        [3001, -25_200, null, null],
        [2611, -68_800, null, null],
    ])
        ->and(e6Balanced($invoice->voucher_id))->toBeTrue()
        ->and(Voucher::query()->find($invoice->voucher_id)->number)->toBe(1);
});

it('books an invoice without dimensions exactly as before, one row per account', function () {
    $invoice = e6Issue(e6Customer(), [
        ['description' => 'A', 'quantity' => '1', 'price' => '100', 'vat_rate' => 2500],
        ['description' => 'B', 'quantity' => '1', 'price' => '300', 'vat_rate' => 2500],
    ]);

    $invoice->load('rows.account');

    expect(e6Rows($invoice->voucher_id))->toBe([[1510, 50_000, null, null], [3001, -40_000, null, null], [2611, -10_000, null, null]])
        ->and(array_map(fn (array $row): array => [$row['account'], $row['amount']], InvoiceBooking::issueRows($invoice, $invoice->rows)))
        ->toBe(array_map(null, array_keys(InvoiceBooking::issue($invoice, $invoice->rows)), array_values(InvoiceBooking::issue($invoice, $invoice->rows))));
});

it('takes the article\'s default before the customer\'s, and keeps what a row says, even none', function () {
    app(SaveArticle::class)($this->owner, $this->company, ['number' => 'SERV', 'name' => 'Service', 'unit' => 'st', 'price' => '100', 'vat_rate' => 2500, 'default_project' => 'P2']);
    $customer = e6Customer(['default_cost_center' => '20', 'default_project' => 'P1']);

    expect($customer->fresh()->default_project_id)->not->toBeNull();

    $draft = app(SaveDraftInvoice::class)($this->owner, $this->company, [
        'customer_id' => $customer->id, 'invoice_date' => '2026-03-02', 'rows' => [
            ['article' => 'SERV', 'description' => 'Service', 'quantity' => '1', 'price' => '100', 'vat_rate' => 2500],
            ['description' => 'Fritt', 'quantity' => '1', 'price' => '100', 'vat_rate' => 2500],
            ['description' => 'Utan', 'quantity' => '1', 'price' => '100', 'vat_rate' => 2500, 'cost_center' => '', 'project' => ''],
        ],
    ])->load(['rows.costCenter', 'rows.project']);

    expect($draft->rows->map(fn (InvoiceRow $row): array => [$row->costCenter?->code, $row->project?->code])->all())
        ->toBe([['20', 'P2'], ['20', 'P1'], [null, null]]);
});

it('refuses an unknown code, and another company\'s code', function () {
    [$otherOwner, $other] = Bookkeeping::company();
    app(SaveDimensionObject::class)($otherOwner, $other, Dimension::Project, ['code' => 'X9', 'name' => 'Främmande']);
    CurrentCompany::set($this->company);

    expect(fn () => app(SaveDraftInvoice::class)($this->owner, $this->company, [
        'customer_id' => e6Customer()->id, 'invoice_date' => '2026-03-02',
        'rows' => [['description' => 'A', 'quantity' => '1', 'price' => '100', 'vat_rate' => 2500, 'project' => 'X9']],
    ]))->toThrow(ValidationException::class, 'Rad 1: projektet X9 finns inte.');

    expect(fn () => app(SaveCustomer::class)($this->owner, $this->company, ['name' => 'Kund', 'payment_terms_days' => 30, 'default_project' => 'X9']))
        ->toThrow(ValidationException::class, 'Projektet X9 finns inte.');
});

it('copies the dimensions to a credit invoice, which reverses them', function () {
    $invoice = e6Issue(e6Customer(), [
        ['description' => 'Snickeri', 'quantity' => '2', 'price' => '1 000', 'vat_rate' => 2500, 'cost_center' => '10', 'project' => 'P1'],
        ['description' => 'Service', 'quantity' => '1', 'price' => '500', 'vat_rate' => 2500, 'project' => 'P2'],
    ]);

    $credit = app(IssueInvoice::class)($this->owner, app(CreditInvoice::class)($this->owner, $invoice));

    expect(e6Rows($credit->voucher_id))->toBe([
        [1510, -312_500, null, null],
        [3001, 200_000, '10', 'P1'],
        [3001, 50_000, null, 'P2'],
        [2611, 62_500, null, null],
    ])
        ->and(e6ProjectRevenue('P1'))->toBe(0)
        ->and(e6ProjectRevenue('P2'))->toBe(0);
});

it('carries the dimensions from a quote to an order and on to the invoice', function () {
    $quote = app(SaveSalesDocument::class)($this->owner, $this->company, SalesDocument::QUOTE, [
        'customer_id' => e6Customer()->id, 'document_date' => '2026-03-02',
        'rows' => [['description' => 'Kök', 'quantity' => '1', 'price' => '10 000', 'vat_rate' => 2500, 'cost_center' => '20', 'project' => 'P1']],
    ]);
    $progress = app(ProgressSalesDocument::class);
    $order = $progress->toOrder($this->owner, $quote);
    $invoice = app(IssueInvoice::class)($this->owner, $progress->toInvoice($this->owner, $order));

    expect($order->rows()->with(['costCenter', 'project'])->sole()->project->code)->toBe('P1')
        ->and(e6Rows($invoice->voucher_id)[1])->toBe([3001, -1_000_000, '20', 'P1']);
});

describe('under kontantmetoden', function () {
    beforeEach(function () {
        $this->company->forceFill(['accounting_method' => AccountingMethod::Kontantmetoden])->save();
    });

    it('splits a part payment over the dimensions by the largest remainder, and the last payment takes the rest', function () {
        // Rows 100 (P1), 100 (P2), 100 (P2): 3001 −300,00, of which P1 −100,00 and P2 −200,00; VAT 75,00; total 375,00.
        $invoice = e6Issue(e6Customer(), [
            ['description' => 'A', 'quantity' => '1', 'price' => '100', 'vat_rate' => 2500, 'project' => 'P1'],
            ['description' => 'B', 'quantity' => '1', 'price' => '100', 'vat_rate' => 2500, 'project' => 'P2'],
            ['description' => 'C', 'quantity' => '1', 'price' => '100', 'vat_rate' => 2500],
        ]);

        expect($invoice->voucher_id)->toBeNull();

        // 100,00 of 375,00: 3001 share = −30 000 × 10 000/37 500 = −8 000 öre exactly; VAT −2 000.
        // −8 000 over (−10 000, −10 000, −10 000), split as 8 000 and given its sign back:
        // 8 000 × 10 000 / 30 000 = 2 666 r 20 000 each; the 2 öre left go to the first two: 2 667, 2 667, 2 666.
        $first = app(RegisterPayment::class)($this->owner, $invoice, CarbonImmutable::parse('2026-03-10'), Money::ofOre(10_000));

        expect(e6Rows($first->voucher_id))->toBe([
            [1930, 10_000, null, null],
            [3001, -2_667, null, 'P1'],
            [3001, -2_667, null, 'P2'],
            [3001, -2_666, null, null],
            [2611, -2_000, null, null],
        ])->and(e6Balanced($first->voucher_id))->toBeTrue();

        $last = app(RegisterPayment::class)($this->owner, $invoice->fresh(), CarbonImmutable::parse('2026-03-20'), Money::ofOre(27_500));

        // The rest: P1 −10 000 + 2 667 = −7 333; P2 −7 333; none −7 334; VAT −5 500.
        expect(e6Rows($last->voucher_id))->toBe([
            [1930, 27_500, null, null],
            [3001, -7_333, null, 'P1'],
            [3001, -7_333, null, 'P2'],
            [3001, -7_334, null, null],
            [2611, -5_500, null, null],
        ])
            ->and(e6Balanced($last->voucher_id))->toBeTrue()
            ->and(e6ProjectRevenue('P1'))->toBe(-10_000)
            ->and(e6ProjectRevenue('P2'))->toBe(-10_000);
    });

    it('books a payment of an invoice without dimensions exactly as before', function () {
        $invoice = e6Issue(e6Customer(), [['description' => 'A', 'quantity' => '1', 'price' => '100', 'vat_rate' => 2500]]);
        $payment = app(RegisterPayment::class)($this->owner, $invoice, CarbonImmutable::parse('2026-03-10'), Money::ofOre(12_500));

        expect(e6Rows($payment->voucher_id))->toBe([[1930, 12_500, null, null], [3001, -10_000, null, null], [2611, -2_500, null, null]]);
    });

    it('keeps the payment series gapless', function () {
        $invoice = e6Issue(e6Customer(), [['description' => 'A', 'quantity' => '3', 'price' => '100', 'vat_rate' => 2500, 'project' => 'P1']]);

        foreach ([10_000, 10_000, 17_500] as $amount) {
            app(RegisterPayment::class)($this->owner, $invoice->fresh(), CarbonImmutable::parse('2026-03-10'), Money::ofOre($amount));
        }

        expect(Voucher::query()->whereHas('series', fn ($query) => $query->where('code', 'C'))->orderBy('number')->pluck('number')->all())->toBe([1, 2, 3])
            ->and(e6ProjectRevenue('P1'))->toBe(-30_000);
    });
});

it('splits by the largest remainder for any sign, always to the total', function () {
    expect(InvoiceBooking::largestRemainder(100, [1, 1, 1]))->toBe([34, 33, 33])
        ->and(InvoiceBooking::largestRemainder(-100, [-1, -1, -1]))->toBe([-34, -33, -33])
        ->and(InvoiceBooking::largestRemainder(10, [30, -10]))->toBe([15, -5])
        ->and(array_sum(InvoiceBooking::largestRemainder(-8_001, ['a' => -7, 'b' => -11, 'c' => -13])))->toBe(-8_001)
        ->and(fn () => InvoiceBooking::largestRemainder(5, [1, -1]))->toThrow(InvalidArgumentException::class);

    // Random rows always split to the exact total.
    mt_srand(81);

    for ($i = 0; $i < 200; $i++) {
        $weights = array_map(fn (): int => mt_rand(-50_000, 200_000) ?: 1, range(1, mt_rand(1, 6)));

        if (array_sum($weights) === 0) {
            continue;
        }

        $total = mt_rand(-1_000_000, 1_000_000);
        expect(array_sum(InvoiceBooking::largestRemainder($total, $weights)))->toBe($total);
    }
});

it('never lets the dimensions of an issued invoice or its posted voucher change', function () {
    $invoice = e6Issue(e6Customer(), [['description' => 'A', 'quantity' => '1', 'price' => '100', 'vat_rate' => 2500, 'project' => 'P1']]);
    $other = DB::table('projects')->where('code', 'P2')->value('id');

    expect(fn () => DB::transaction(fn () => DB::table('invoice_rows')->where('invoice_id', $invoice->id)->update(['project_id' => $other])))->toThrow(QueryException::class)
        ->and(fn () => DB::transaction(fn () => DB::table('voucher_rows')->where('voucher_id', $invoice->voucher_id)->whereNotNull('project_id')->update(['project_id' => $other])))->toThrow(QueryException::class)
        ->and(fn () => DB::transaction(fn () => DB::table('voucher_rows')->where('voucher_id', $invoice->voucher_id)->whereNotNull('project_id')->delete()))->toThrow(QueryException::class)
        ->and(fn () => DB::transaction(fn () => VoucherRow::query()->where('voucher_id', $invoice->voucher_id)->whereNotNull('project_id')->firstOrFail()->update(['project_id' => $other])))->toThrow(ImmutableVoucher::class);

    expect(e6ProjectRevenue('P1'))->toBe(-10_000);
});

describe('in the invoice editor', function () {
    it('shows cost centre and project columns and saves them', function () {
        $customer = e6Customer(['default_project' => 'P1']);

        Livewire::test(InvoiceEditor::class)
            ->assertSee('Kostnadsställe')
            ->assertSee('P1 Ombyggnad')
            ->set('customerId', (string) $customer->id)
            ->set('rows', [['article' => '', 'description' => 'Arbete', 'quantity' => '1', 'unit_id' => '', 'price' => '100', 'discount' => '', 'vat_rate' => '2500', 'account' => '', 'cost_center' => '10', 'project' => 'P2']])
            ->call('save')
            ->assertHasNoErrors();

        expect(InvoiceRow::query()->with(['costCenter', 'project'])->sole()->project->code)->toBe('P2');
    });

    it('hides the columns from a company without cost centres or projects', function () {
        [$owner, $company] = Bookkeeping::company();
        CurrentCompany::set($company);
        $this->actingAs($owner);

        Livewire::test(InvoiceEditor::class)->assertDontSee('Kostnadsställe, rad');
    });

    it('renders in a fixed number of queries however many projects and customers there are', function () {
        $count = function (): int {
            DB::flushQueryLog();
            DB::enableQueryLog();
            Livewire::test(InvoiceEditor::class);
            DB::disableQueryLog();

            return count(DB::getQueryLog());
        };

        e6Customer(['default_project' => 'P1']);
        // The first render also warms the caches the app keeps per request.
        $count();
        $short = $count();

        foreach (range(3, 12) as $n) {
            app(SaveDimensionObject::class)($this->owner, $this->company, Dimension::Project, ['code' => "P{$n}", 'name' => "Projekt {$n}"]);
            e6Customer(['default_project' => "P{$n}", 'org_number' => null]);
        }

        expect($count())->toBe($short);
    });
});
