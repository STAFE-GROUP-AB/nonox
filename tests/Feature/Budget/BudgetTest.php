<?php

declare(strict_types=1);

use App\Actions\Accounting\PostVoucher;
use App\Actions\Accounting\SaveDraftVoucher;
use App\Actions\Budget\CreateBudgetVersion;
use App\Actions\Budget\ManageBudgetVersion;
use App\Actions\Budget\SaveBudgetLines;
use App\Domain\Budget\BudgetComparison;
use App\Domain\Budget\BudgetCsv;
use App\Domain\Budget\BudgetMonths;
use App\Domain\Budget\BudgetStatus;
use App\Domain\Budget\LockedBudgetVersion;
use App\Livewire\Accounting\BudgetEditor;
use App\Livewire\Accounting\BudgetReport;
use App\Livewire\Accounting\BudgetsPage;
use App\Models\Account;
use App\Models\AuditLog;
use App\Models\BudgetLine;
use App\Models\BudgetVersion;
use App\Models\CostCenter;
use App\Models\FiscalYear;
use App\Models\Voucher;
use App\Models\VoucherRow;
use App\Support\Tenancy\CurrentCompany;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\QueryException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Tests\Support\Bookkeeping;

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-04-10 10:00:00'));
    [$this->owner, $this->company] = Bookkeeping::company();
    CurrentCompany::set($this->company);
    $this->actingAs($this->owner);
    $this->year = FiscalYear::query()->whereDate('start_date', '2026-01-01')->firstOrFail();
});

function e3Account(int $number): int
{
    return (int) Account::query()->where('number', $number)->value('id');
}

/** @param array<string, mixed> $input */
function e3Budget(array $input = []): BudgetVersion
{
    return app(CreateBudgetVersion::class)(test()->owner, test()->company, [
        'fiscal_year_id' => test()->year->id, 'name' => 'Budget 2026', 'start' => CreateBudgetVersion::EMPTY, ...$input,
    ]);
}

/**
 * @param  array<int, array<string, int>>  $perAccount  account number => ['Y-m' => ledger öre]
 */
function e3Save(BudgetVersion $version, array $perAccount, ?int $costCenterId = null): BudgetVersion
{
    $rows = [];

    foreach ($perAccount as $number => $amounts) {
        $rows[] = ['account_id' => e3Account($number), 'cost_center_id' => $costCenterId, 'project_id' => null, 'amounts' => $amounts];
    }

    return app(SaveBudgetLines::class)(test()->owner, $version, $rows);
}

function e3PreviousYear(): FiscalYear
{
    return FiscalYear::create(['start_date' => '2025-01-01', 'end_date' => '2025-12-31']);
}

it('gives budgets:view and budgets:manage to the roles in ADR-081', function () {
    $roles = ['accountant' => [true, true], 'bookkeeper' => [true, false], 'approver' => [false, false], 'auditor' => [true, false], 'admin' => [true, true]];

    foreach ($roles as $role => [$view, $manage]) {
        $member = Bookkeeping::member($this->company, $role);
        $company = $this->company->fresh();

        expect($member->hasTeamPermission($company, 'budgets:view'))->toBe($view, $role)
            ->and($member->hasTeamPermission($company, 'budgets:manage'))->toBe($manage, $role);
    }

    expect($this->owner->hasTeamPermission($this->company, 'budgets:manage'))->toBeTrue();
});

it('creates an empty budget, the first of the year active, and audits it without its name', function () {
    $first = e3Budget();
    $second = e3Budget(['name' => 'Alternativ']);

    expect($first->is_active)->toBeTrue()
        ->and($second->is_active)->toBeFalse()
        ->and($first->status)->toBe(BudgetStatus::Draft);

    $log = AuditLog::query()->where('action', 'budget.created')->where('subject_id', (string) $first->id)->firstOrFail();

    expect($log->payload)->toMatchArray(['fiscal_year_id' => $this->year->id, 'start' => 'empty', 'lines' => 0])
        ->and(json_encode($log->payload))->not->toContain('Budget 2026');
});

it('refuses budgets to a member without budgets:manage', function () {
    $bookkeeper = Bookkeeping::member($this->company, 'bookkeeper');

    expect(fn () => app(CreateBudgetVersion::class)($bookkeeper, $this->company, ['fiscal_year_id' => $this->year->id, 'name' => 'X', 'start' => 'empty']))
        ->toThrow(AuthorizationException::class);

    $version = e3Budget();

    expect(fn () => app(SaveBudgetLines::class)($bookkeeper, $version, []))->toThrow(AuthorizationException::class);
});

it('spreads a year evenly in whole kronor and changes amounts by a percentage', function () {
    expect(BudgetMonths::spreadEvenly(100_000_00, 12))->toBe([833_400, 833_400, 833_400, 833_400, 833_300, 833_300, 833_300, 833_300, 833_300, 833_300, 833_300, 833_300])
        ->and(array_sum(BudgetMonths::spreadEvenly(-1_000_00, 3)))->toBe(-1_000_00)
        ->and(BudgetMonths::adjusted(10_000_00, 500))->toBe(10_500_00)
        ->and(BudgetMonths::adjusted(-333_33, 250))->toBe(-342_00)
        ->and(BudgetMonths::percent('-2,5'))->toBe(-250)
        ->and(BudgetMonths::percent('abc'))->toBeNull()
        ->and(BudgetMonths::of($this->year))->toHaveCount(12)
        ->and(BudgetMonths::shown(3001, -500))->toBe(500)
        ->and(BudgetMonths::shown(5010, 500))->toBe(500);
});

it('saves the grid, replacing the lines, in ledger sign', function () {
    $version = e3Save(e3Budget(), [3001 => ['2026-01' => -50_000_00, '2026-02' => -60_000_00], 5010 => ['2026-01' => 10_000_00]]);

    expect(BudgetLine::query()->where('budget_version_id', $version->id)->count())->toBe(3);

    e3Save($version, [3001 => ['2026-03' => -1_00]]);

    $lines = BudgetLine::query()->where('budget_version_id', $version->id)->get();

    expect($lines)->toHaveCount(1)
        ->and($lines->first()->amount_ore->ore)->toBe(-1_00)
        ->and($lines->first()->period->toDateString())->toBe('2026-03-01');
});

it('refuses balance accounts, months outside the year and duplicate rows', function () {
    $version = e3Budget();

    expect(fn () => e3Save($version, [1930 => ['2026-01' => 100]]))->toThrow(ValidationException::class)
        ->and(fn () => e3Save($version, [3001 => ['2027-01' => 100]]))->toThrow(ValidationException::class)
        ->and(fn () => app(SaveBudgetLines::class)($this->owner, $version, [
            ['account_id' => e3Account(3001), 'cost_center_id' => null, 'project_id' => null, 'amounts' => []],
            ['account_id' => e3Account(3001), 'cost_center_id' => null, 'project_id' => null, 'amounts' => []],
        ]))->toThrow(ValidationException::class);
});

it('refuses another company\'s cost centre on a budget row', function () {
    [, $other] = Bookkeeping::company();
    $foreign = CurrentCompany::run($other, fn () => CostCenter::create(['code' => 'X1', 'name' => 'Annan']));
    CurrentCompany::set($this->company);

    expect(fn () => e3Save(e3Budget(), [3001 => ['2026-01' => 100]], $foreign->id))->toThrow(ValidationException::class);
});

it('copies a budget into the next year by calendar month with a percentage', function () {
    $source = e3Save(e3Budget(), [3001 => ['2026-01' => -10_000_00, '2026-12' => -20_000_00]]);
    $next = FiscalYear::create(['start_date' => '2027-01-01', 'end_date' => '2027-12-31']);

    $copy = e3Budget(['fiscal_year_id' => $next->id, 'name' => 'Budget 2027', 'start' => CreateBudgetVersion::COPY, 'source_version_id' => $source->id, 'percent' => '10']);

    $lines = BudgetLine::query()->where('budget_version_id', $copy->id)->orderBy('period')->get();

    expect($lines->map(fn (BudgetLine $line): array => [$line->period->format('Y-m'), $line->amount_ore->ore])->all())
        ->toBe([['2027-01', -11_000_00], ['2027-12', -22_000_00]])
        ->and($copy->source_version_id)->toBe($source->id)
        ->and($copy->is_active)->toBeTrue();
});

it('starts a budget from last year\'s outcome, month by month', function () {
    $previous = e3PreviousYear();
    Bookkeeping::post($this->owner, $this->company, ore: 12_345_67, date: '2025-03-15');

    $version = e3Budget(['start' => CreateBudgetVersion::LAST_YEAR, 'percent' => '0']);

    $line = BudgetLine::query()->where('budget_version_id', $version->id)->sole();

    expect($line->period->toDateString())->toBe('2026-03-01')
        ->and($line->amount_ore->ore)->toBe(-12_346_00)
        ->and($previous->id)->not->toBe($this->year->id);
});

it('refuses last year\'s outcome when there is no year before', function () {
    expect(fn () => e3Budget(['start' => CreateBudgetVersion::LAST_YEAR]))->toThrow(ValidationException::class);
});

it('keeps a locked version read-only in the model and in the database', function () {
    $version = e3Save(e3Budget(), [3001 => ['2026-01' => -100_00]]);
    app(ManageBudgetVersion::class)->lock($this->owner, $version);
    $version->refresh();

    $line = BudgetLine::query()->where('budget_version_id', $version->id)->firstOrFail();

    expect($version->isLocked())->toBeTrue()
        ->and(fn () => e3Save($version, []))->toThrow(AuthorizationException::class)
        ->and(fn () => $line->update(['amount_ore' => 5]))->toThrow(LockedBudgetVersion::class)
        ->and(fn () => $version->update(['name' => 'Ny']))->toThrow(LockedBudgetVersion::class);

    foreach ([
        fn () => DB::table('budget_lines')->where('id', $line->id)->update(['amount_ore' => 1]),
        fn () => DB::table('budget_lines')->where('id', $line->id)->delete(),
        fn () => DB::table('budget_lines')->insert(['company_id' => $this->company->id, 'budget_version_id' => $version->id, 'account_id' => e3Account(5010), 'period' => '2026-02-01', 'amount_ore' => 1, 'is_override' => false]),
        fn () => DB::table('budget_versions')->where('id', $version->id)->update(['status' => 'DRAFT']),
    ] as $attempt) {
        DB::beginTransaction();

        try {
            expect($attempt)->toThrow(QueryException::class);
        } finally {
            DB::rollBack();
        }
    }

    // Which version is active may still change.
    expect(fn () => $version->update(['is_active' => false]))->not->toThrow(Throwable::class);
});

it('deletes a locked version with its lines and audits the deletion', function () {
    $version = e3Save(e3Budget(), [3001 => ['2026-01' => -100_00]]);
    app(ManageBudgetVersion::class)->lock($this->owner, $version);

    app(ManageBudgetVersion::class)->delete($this->owner, $version->refresh());

    expect(BudgetVersion::query()->find($version->id))->toBeNull()
        ->and(BudgetLine::query()->where('budget_version_id', $version->id)->count())->toBe(0)
        ->and(AuditLog::query()->where('action', 'budget.deleted')->where('subject_id', (string) $version->id)->value('payload'))->toMatchArray(['status' => 'LOCKED', 'lines' => 1]);
});

it('keeps exactly one active budget per year', function () {
    $first = e3Budget();
    $second = e3Budget(['name' => 'B']);

    app(ManageBudgetVersion::class)->activate($this->owner, $second);

    expect($first->refresh()->is_active)->toBeFalse()
        ->and($second->refresh()->is_active)->toBeTrue()
        ->and(fn () => DB::transaction(fn () => DB::table('budget_versions')->where('id', $first->id)->update(['is_active' => true])))->toThrow(QueryException::class);
});

it('compares the outcome with the budget for the month and the year to date', function () {
    Bookkeeping::post($this->owner, $this->company, ore: 90_000_00, date: '2026-01-20');
    Bookkeeping::post($this->owner, $this->company, ore: 120_000_00, date: '2026-02-20');
    Bookkeeping::post($this->owner, $this->company, ore: 15_000_00, debit: 5010, credit: 1930, date: '2026-02-01');

    $version = e3Save(e3Budget(), [
        3001 => ['2026-01' => -100_000_00, '2026-02' => -100_000_00, '2026-03' => -100_000_00],
        5010 => ['2026-02' => 10_000_00],
    ]);

    $report = BudgetComparison::build($version, '2026-02');
    $revenue = collect($report['groups'])->firstWhere('key', 'revenue')['rows'][0];
    $rent = collect($report['groups'])->firstWhere('key', 'external')['rows'][0];

    expect($revenue['number'])->toBe(3001)
        ->and($revenue['month'])->toBe(['actual' => 120_000_00, 'budget' => 100_000_00, 'difference' => 20_000_00, 'percent' => 2000])
        ->and($revenue['ytd'])->toBe(['actual' => 210_000_00, 'budget' => 200_000_00, 'difference' => 10_000_00, 'percent' => 500])
        ->and($revenue['year_budget'])->toBe(300_000_00)
        ->and($rent['month'])->toBe(['actual' => -15_000_00, 'budget' => -10_000_00, 'difference' => -5_000_00, 'percent' => -5000])
        ->and($report['result']['ytd'])->toBe(['actual' => 195_000_00, 'budget' => 190_000_00, 'difference' => 5_000_00, 'percent' => 263]);
});

it('filters actual and budget by cost centre', function () {
    $shop = CostCenter::create(['code' => 'K1', 'name' => 'Butik']);
    app(PostVoucher::class)($this->owner, app(SaveDraftVoucher::class)($this->owner, $this->company, [
        'voucher_date' => '2026-01-10', 'description' => 'Försäljning', 'series' => 'A',
        'rows' => [
            ['account' => 1930, 'debit_ore' => 3_000_00],
            ['account' => 3001, 'credit_ore' => 1_000_00, 'cost_center' => 'K1'],
            ['account' => 3001, 'credit_ore' => 2_000_00],
        ],
    ]));

    $version = e3Budget();
    app(SaveBudgetLines::class)($this->owner, $version, [
        ['account_id' => e3Account(3001), 'cost_center_id' => $shop->id, 'project_id' => null, 'amounts' => ['2026-01' => -1_500_00]],
        ['account_id' => e3Account(3001), 'cost_center_id' => null, 'project_id' => null, 'amounts' => ['2026-01' => -2_000_00]],
    ]);

    $all = BudgetComparison::build($version, '2026-01')['result']['month'];
    $only = BudgetComparison::build($version, '2026-01', $shop->id)['result']['month'];

    expect($all)->toMatchArray(['actual' => 3_000_00, 'budget' => 3_500_00])
        ->and($only)->toMatchArray(['actual' => 1_000_00, 'budget' => 1_500_00, 'difference' => -500_00]);
});

it('reads its own CSV back and reports bad rows without reading any', function () {
    $months = BudgetMonths::of($this->year);
    CostCenter::create(['code' => 'K1', 'name' => 'Butik']);

    $csv = BudgetCsv::write($months, [
        ['number' => 3001, 'name' => '=Försäljning', 'cost_center' => 'K1', 'project' => '', 'amounts' => ['2026-01' => -1_234_50, '2026-12' => -100_00]],
        ['number' => 5010, 'name' => 'Lokalhyra', 'cost_center' => '', 'project' => '', 'amounts' => ['2026-02' => 8_000_00]],
    ]);

    expect($csv)->toStartWith("\u{FEFF}Konto;Kontonamn;Kostnadsställe;Projekt;2026-01")
        ->and($csv)->toContain("3001;'=Försäljning;K1;;1234,50")
        ->and($csv)->toContain("\r\n");

    $read = BudgetCsv::read($csv, $months);

    expect($read['errors'])->toBe([])
        ->and($read['rows'])->toHaveCount(2)
        ->and($read['rows'][0]['amounts']['2026-01'])->toBe(-1_234_50)
        ->and($read['rows'][0]['cost_center_id'])->toBe(CostCenter::query()->where('code', 'K1')->value('id'))
        ->and($read['rows'][1]['amounts']['2026-02'])->toBe(8_000_00);

    $bad = BudgetCsv::read("Konto;2026-01\n1930;5\n3001;abc\n9999;1\n3001;1\n", $months);

    expect($bad['rows'])->toHaveCount(1)
        ->and($bad['errors'])->toHaveCount(3);

    $pasted = BudgetCsv::read("3001\t100\t200\n", $months);

    expect($pasted['rows'][0]['amounts'])->toMatchArray(['2026-01' => -100_00, '2026-02' => -200_00]);
});

it('works the grid from the keyboard page: spread, paste, last year, import and save', function () {
    e3PreviousYear();
    Bookkeeping::post($this->owner, $this->company, ore: 50_000_00, date: '2025-05-05');
    $version = e3Budget();

    $component = Livewire::test(BudgetEditor::class, ['version' => $version])
        ->set('newAccount', '3001')
        ->call('addRow')
        ->assertHasNoErrors()
        ->assertDispatched('toast');

    $key = e3Account(3001).'-0-0';

    $component->call('spread', $key, '120 000')
        ->assertSet("cells.{$key}.0", '10000')
        ->call('pasteBlock', 0, 10, "7\t8\t9")
        ->assertSet("cells.{$key}.10", '7')
        ->assertSet("cells.{$key}.11", '8')
        ->call('save')
        ->assertHasNoErrors()
        ->assertDispatched('budget-saved');

    expect(BudgetLine::query()->where('budget_version_id', $version->id)->count())->toBe(12)
        ->and(BudgetLine::query()->where('budget_version_id', $version->id)->whereDate('period', '2026-01-01')->toBase()->value('amount_ore'))->toBe(-10_000_00);

    $component->set('percent', '10')->call('lastYear', $key)->assertSet("cells.{$key}.4", '55000');

    $file = UploadedFile::fake()->createWithContent('budget.csv', "Konto;2026-01\n5010;2500\n");
    $component->set('file', $file)->call('import')->assertSet('importErrors', [])->call('save');

    expect(BudgetLine::query()->where('budget_version_id', $version->id)->where('account_id', e3Account(5010))->toBase()->value('amount_ore'))->toBe(2_500_00);

    $component->set("cells.{$key}.0", 'tio')->call('save')->assertHasErrors("cells.{$key}.0");
});

it('shows a locked budget read-only and refuses changes', function () {
    $version = e3Save(e3Budget(), [3001 => ['2026-01' => -100_00]]);
    app(ManageBudgetVersion::class)->lock($this->owner, $version);

    Livewire::test(BudgetEditor::class, ['version' => $version->refresh()])
        ->assertSee('Budgeten är låst och kan inte ändras.')
        ->call('save')
        ->assertForbidden();
});

it('creates, activates and deletes budgets from the list with toasts', function () {
    $other = e3Budget(['name' => 'Annan']);

    Livewire::test(BudgetsPage::class)
        ->call('create')
        ->set('form.name', 'Plan')
        ->call('save')
        ->assertHasNoErrors()
        ->assertRedirect();

    $plan = BudgetVersion::query()->where('name', 'Plan')->firstOrFail();

    Livewire::test(BudgetsPage::class)
        ->assertSee('Plan')
        ->call('activate', $plan->id)
        ->assertDispatched('toast')
        ->call('delete', $other->id)
        ->assertDispatched('toast');

    expect($plan->refresh()->is_active)->toBeTrue()
        ->and(BudgetVersion::query()->find($other->id))->toBeNull();
});

it('opens the pages and the CSV file, and refuses a member without budgets:view', function () {
    $version = e3Save(e3Budget(), [3001 => ['2026-01' => -100_00]]);

    $this->get(route('accounting.budgets'))->assertOk()->assertSee('Budget 2026');
    $this->get(route('accounting.budgets.show', $version))->assertOk()->assertSee('Budget per konto och månad');
    $this->get(route('accounting.budgets.report'))->assertOk()->assertSee('Resultat mot budget');
    $this->get(route('accounting.budgets.csv', $version))->assertOk()->assertHeader('content-type', 'text/csv; charset=UTF-8');

    $approver = Bookkeeping::member($this->company, 'approver');
    $this->actingAs($approver);

    $this->get(route('accounting.budgets'))->assertForbidden();
    $this->get(route('accounting.budgets.show', $version))->assertForbidden();
    $this->get(route('accounting.budgets.report'))->assertForbidden();
});

it('links each account in the report to its account analysis', function () {
    Bookkeeping::post($this->owner, $this->company, ore: 1_000_00, date: '2026-02-03');
    $version = e3Save(e3Budget(), [3001 => ['2026-02' => -900_00]]);

    Livewire::test(BudgetReport::class)
        ->set('manad', '2026-02')
        ->assertSee('3001')
        ->assertSee(route('accounting.reports', ['rapport' => 'huvudbok', 'ar' => $version->fiscal_year_id, 'fran' => '2026-01-01', 'till' => '2026-02-28', 'konto' => '3001']));
});

it('never writes a voucher', function () {
    e3PreviousYear();
    Bookkeeping::post($this->owner, $this->company, ore: 1_000_00, date: '2025-02-03');
    $before = Voucher::query()->count();
    $rowsBefore = VoucherRow::query()->count();
    $lastAudit = (int) AuditLog::query()->max('id');

    $version = e3Save(e3Budget(['start' => CreateBudgetVersion::LAST_YEAR]), [3001 => ['2026-01' => -1]]);
    app(ManageBudgetVersion::class)->lock($this->owner, $version);
    app(ManageBudgetVersion::class)->activate($this->owner, $version->refresh());
    BudgetComparison::build($version, '2026-01');
    app(ManageBudgetVersion::class)->delete($this->owner, $version);

    $actions = AuditLog::query()->where('id', '>', $lastAudit)->pluck('action')->unique()->values()->all();

    expect(Voucher::query()->count())->toBe($before)
        ->and(VoucherRow::query()->count())->toBe($rowsBefore)
        ->and($actions)->not->toBe([])
        ->and(array_filter($actions, fn (string $action): bool => ! str_starts_with($action, 'budget.')))->toBe([]);
});

it('treats class 3 and 8000–8399 as revenue and the other result accounts as costs', function () {
    expect(BudgetMonths::isRevenue(3001))->toBeTrue()
        ->and(BudgetMonths::isRevenue(8310))->toBeTrue()
        ->and(BudgetMonths::isRevenue(8410))->toBeFalse()
        ->and(BudgetMonths::isRevenue(5010))->toBeFalse()
        ->and(BudgetMonths::shown(8310, -500_00))->toBe(500_00)
        ->and(BudgetMonths::shown(8410, 300_00))->toBe(300_00)
        ->and(BudgetMonths::shown(8410, -100_00))->toBe(-100_00);

    $version = e3Budget();
    $component = Livewire::test(BudgetEditor::class, ['version' => $version]);

    foreach (['8310', '8410'] as $number) {
        $component->set('newAccount', $number)->call('addRow')->assertHasNoErrors();
    }

    $interestIncome = e3Account(8310).'-0-0';
    $interestCost = e3Account(8410).'-0-0';

    $component->set("cells.{$interestIncome}.0", '500')
        ->set("cells.{$interestCost}.0", '300')
        ->call('save')
        ->assertHasNoErrors()
        ->assertSee('Intäkt')
        ->assertSee('Kostnad');

    $lines = BudgetLine::query()->where('budget_version_id', $version->id)->toBase()->pluck('amount_ore', 'account_id');

    expect((int) $lines[e3Account(8310)])->toBe(-500_00)
        ->and((int) $lines[e3Account(8410)])->toBe(300_00)
        ->and(BudgetComparison::build($version, '2026-01')['result']['month']['budget'])->toBe(200_00);
});

it('never budgets 8999 Årets resultat', function () {
    expect(BudgetMonths::isBudgetable(8999))->toBeFalse()
        ->and(BudgetMonths::isBudgetable(8998))->toBeTrue()
        ->and(fn () => e3Save(e3Budget(), [8999 => ['2026-01' => 100]]))->toThrow(ValidationException::class);
});

it('refuses to delete a locked budget of a closed fiscal year, but deletes a draft of one', function () {
    $locked = e3Save(e3Budget(), [3001 => ['2026-01' => -100_00]]);
    app(ManageBudgetVersion::class)->lock($this->owner, $locked);
    $draft = e3Budget(['name' => 'Utkast']);
    DB::table('fiscal_years')->where('id', $this->year->id)->update(['status' => 'CLOSED']);

    expect(fn () => app(ManageBudgetVersion::class)->delete($this->owner, $locked->refresh()))
        ->toThrow(ValidationException::class, 'En låst budget för ett stängt räkenskapsår kan inte tas bort.');

    Livewire::test(BudgetsPage::class)->call('delete', $locked->id)->assertDispatched('toast', type: 'error');

    app(ManageBudgetVersion::class)->delete($this->owner, $draft);

    expect(BudgetVersion::query()->find($locked->id))->not->toBeNull()
        ->and(BudgetVersion::query()->find($draft->id))->toBeNull();
});

it('keeps who created and locked a locked version in the database too', function () {
    $version = e3Budget();
    app(ManageBudgetVersion::class)->lock($this->owner, $version);
    $other = Bookkeeping::member($this->company, 'accountant');

    foreach ([['created_by' => $other->id], ['locked_by' => $other->id], ['locked_at' => '2020-01-01 00:00:00'], ['company_id' => Bookkeeping::company()[1]->id]] as $change) {
        DB::beginTransaction();

        try {
            expect(fn () => DB::table('budget_versions')->where('id', $version->id)->update($change))->toThrow(QueryException::class);
        } finally {
            DB::rollBack();
        }
    }

    // Deleting the source of a copy still clears the copy's reference.
    $copy = e3Budget(['name' => 'Kopia', 'start' => CreateBudgetVersion::COPY, 'source_version_id' => $version->id]);
    app(ManageBudgetVersion::class)->lock($this->owner, $copy);
    app(ManageBudgetVersion::class)->delete($this->owner, $version->refresh());

    expect($copy->refresh()->source_version_id)->toBeNull();
});

it('refuses amounts too large to store instead of failing', function () {
    expect(BudgetMonths::parseCell('99999999999999999999999'))->toBeNull()
        ->and(BudgetMonths::parseCell('100 000 000 000'))->toBe(10_000_000_000_000)
        ->and(BudgetMonths::parseCell('100 000 000 001'))->toBeNull();

    $version = e3Budget();
    $key = e3Account(3001).'-0-0';

    Livewire::test(BudgetEditor::class, ['version' => $version])
        ->set('newAccount', '3001')->call('addRow')
        ->call('spread', $key, '99999999999999999999999')
        ->assertHasErrors("cells.{$key}")
        ->set("cells.{$key}.0", '99999999999999999999999')
        ->call('save')
        ->assertHasErrors("cells.{$key}.0");

    $read = BudgetCsv::read("3001;99999999999999999999999\n", BudgetMonths::of($this->year));

    expect($read['rows'])->toBe([])
        ->and($read['errors'])->toHaveCount(1);
});

it('limits what is pasted or read in, and accepts only text in the cells', function () {
    $months = BudgetMonths::of($this->year);
    $tooMany = str_repeat("3001;1\n", BudgetMonths::MAX_IMPORT_ROWS + 1);

    expect(BudgetCsv::read("Konto;2026-01\n".$tooMany, $months)['errors'][0])->toContain('fler än 2000 rader');

    $version = e3Budget();
    $key = e3Account(3001).'-0-0';

    Livewire::test(BudgetEditor::class, ['version' => $version])
        ->set('newAccount', '3001')->call('addRow')
        ->set('paste', str_repeat('x', BudgetEditor::MAX_PASTE_LENGTH + 1))
        ->call('import')
        ->assertSet('importErrors', ['Det inklistrade är för stort. Läs in det som en CSV-fil i stället.'])
        ->set("cells.{$key}.0", ['inte', 'text'])
        ->call('save')
        ->assertHasErrors("cells.{$key}.0");

    expect(BudgetLine::query()->where('budget_version_id', $version->id)->count())->toBe(0);
});

it('makes only the first budget of a year active, under a lock on the year', function () {
    DB::flushQueryLog();
    DB::enableQueryLog();
    $first = e3Budget();
    $log = DB::getQueryLog();
    DB::disableQueryLog();

    $second = e3Budget(['name' => 'Samtidig']);

    expect(collect($log)->contains(fn (array $query): bool => str_contains($query['query'], 'from "fiscal_years"') && str_contains($query['query'], 'for update')))->toBeTrue()
        ->and($first->is_active)->toBeTrue()
        ->and($second->is_active)->toBeFalse();
});

/** @return int queries run while rendering $callback */
function e3Queries(Closure $callback): int
{
    DB::flushQueryLog();
    DB::enableQueryLog();
    $callback();
    $count = count(DB::getQueryLog());
    DB::disableQueryLog();

    return $count;
}

function e3History(int $months, int $costCenters): BudgetVersion
{
    $centres = [];

    for ($i = 1; $i <= $costCenters; $i++) {
        $centres[] = CostCenter::create(['code' => "Q{$months}-{$i}", 'name' => "Ställe {$i}"])->id;
    }

    for ($month = 1; $month <= $months; $month++) {
        Bookkeeping::post(test()->owner, test()->company, ore: 1_000_00 * $month, date: sprintf('2026-%02d-10', $month));
        Bookkeeping::post(test()->owner, test()->company, ore: 300_00, debit: 5010, credit: 1930, date: sprintf('2026-%02d-11', $month));
    }

    $version = e3Budget(['name' => "Mätning {$months}"]);
    $rows = [['account_id' => e3Account(3001), 'cost_center_id' => null, 'project_id' => null, 'amounts' => []]];

    foreach ($centres as $centre) {
        $rows[] = ['account_id' => e3Account(5010), 'cost_center_id' => $centre, 'project_id' => null, 'amounts' => []];
    }

    foreach (array_keys($rows) as $index) {
        foreach (array_slice(BudgetMonths::of(test()->year), 0, $months) as $month) {
            $rows[$index]['amounts'][$month] = 100_00;
        }
    }

    return app(SaveBudgetLines::class)(test()->owner, $version, $rows);
}

it('renders the budget pages in a fixed number of queries', function () {
    $short = e3History(1, 1);
    // The first render loads what the request caches (the member's role); measure after it.
    Livewire::test(BudgetsPage::class);

    $listShort = e3Queries(fn () => Livewire::test(BudgetsPage::class));
    $editorShort = e3Queries(fn () => Livewire::test(BudgetEditor::class, ['version' => $short]));
    $reportShort = e3Queries(fn () => Livewire::withQueryParams(['budget' => (string) $short->id])->test(BudgetReport::class));

    $long = e3History(12, 8);
    e3Budget(['name' => 'Tredje']);

    expect(e3Queries(fn () => Livewire::test(BudgetsPage::class)))->toBe($listShort)
        ->and(e3Queries(fn () => Livewire::test(BudgetEditor::class, ['version' => $long])))->toBe($editorShort)
        ->and(e3Queries(fn () => Livewire::withQueryParams(['budget' => (string) $long->id])->test(BudgetReport::class)))->toBe($reportShort);
});
