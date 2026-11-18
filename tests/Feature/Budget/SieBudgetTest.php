<?php

declare(strict_types=1);

use App\Actions\Accounting\AnalyseSieFile;
use App\Actions\Accounting\ConfirmSieImport;
use App\Actions\Accounting\ExportSie;
use App\Actions\Budget\CreateBudgetVersion;
use App\Actions\Budget\ImportSieBudget;
use App\Actions\Budget\SaveBudgetLines;
use App\Domain\Accounting\Dimension;
use App\Domain\Accounting\SieImport\SieImportAnalyser;
use App\Domain\Accounting\SieImport\SieImportStatus;
use App\Domain\Budget\Sie\SieBudgetAnalyser;
use App\Livewire\Accounting\BudgetSieImport;
use App\Livewire\Accounting\FiscalYears;
use App\Livewire\Accounting\SieImportReport;
use App\Models\Account;
use App\Models\AuditLog;
use App\Models\BudgetLine;
use App\Models\BudgetVersion;
use App\Models\Company;
use App\Models\CostCenter;
use App\Models\FiscalYear;
use App\Models\Project;
use App\Models\SieImport;
use App\Models\User;
use App\Support\Build\BuildInfo;
use App\Support\Tenancy\CurrentCompany;
use App\Support\Toast;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Tests\Support\Bookkeeping;

/*
 * SIE #PBUDGET (ADR-081 E4, owner decision 5): the budget is read in whenever
 * a file has one, and written out only with "Ta med budget". Synthetic data
 * only (AGENTS.md §15.6).
 */

const E4_EXPORT_FIXTURE = __DIR__.'/../../Fixtures/sie/budget-export-2026.se';
const E4_TYPE2_FIXTURE = __DIR__.'/../../Fixtures/sie/budget-typ2-2026.se';

beforeEach(function () {
    Storage::fake('local');
    $this->travelTo(CarbonImmutable::parse('2026-10-29 10:00:00'));
    [$this->owner, $this->company] = Bookkeeping::company();
    $this->company->forceFill(['name' => 'Exempelbolaget', 'legal_name' => 'Exempelbolaget AB', 'org_number' => '556123-4567', 'legal_form' => 'AB', 'bas_version' => 'BAS 2026'])->save();
    $this->owner->forceFill(['name' => 'Anna Andersson'])->save();
    CurrentCompany::set($this->company);
    $this->actingAs($this->owner);
    $this->year = FiscalYear::query()->whereDate('start_date', '2026-01-01')->sole();
});

function e4Account(int $number): int
{
    return (int) Account::query()->where('number', $number)->value('id');
}

function e4Export(User $user, FiscalYear $year, bool $budget): string
{
    $stream = fopen('php://memory', 'w+b');
    app(ExportSie::class)($user, $year, $stream, $budget);
    rewind($stream);

    return (string) stream_get_contents($stream);
}

/** The lines without the identification records, which name the program, the time and the company. */
function e4Ledger(string $file): string
{
    return (string) preg_replace('/^#(PROGRAM|GEN|KSUMMA|ORGNR|FNAMN|ADRESS|FTYP)\b.*\r\n/m', '', $file);
}

/**
 * A budget with every shape a line has: the whole account, cost centres, a
 * project, and one line with both a cost centre and a project.
 */
function e4Budget(User $owner, Company $company, FiscalYear $year): BudgetVersion
{
    $ten = CostCenter::create(['code' => '10', 'name' => 'Butiken'])->id;
    $twenty = CostCenter::create(['code' => '20', 'name' => 'Lagret'])->id;
    $project = Project::create(['code' => 'P1', 'name' => 'Ombyggnad'])->id;
    Bookkeeping::post($owner, $company, 125_000, 1930, 3001, '2026-03-15');

    $version = app(CreateBudgetVersion::class)($owner, $company, ['fiscal_year_id' => $year->id, 'name' => 'Budget 2026', 'start' => CreateBudgetVersion::EMPTY]);

    return app(SaveBudgetLines::class)($owner, $version, [
        ['account_id' => e4Account(3001), 'cost_center_id' => null, 'project_id' => null, 'amounts' => ['2026-01' => -1_000_000, '2026-02' => -1_100_050]],
        ['account_id' => e4Account(5010), 'cost_center_id' => $ten, 'project_id' => null, 'amounts' => ['2026-02' => 300_000]],
        ['account_id' => e4Account(5010), 'cost_center_id' => $twenty, 'project_id' => null, 'amounts' => ['2026-02' => 200_000]],
        ['account_id' => e4Account(6110), 'cost_center_id' => null, 'project_id' => $project, 'amounts' => ['2026-03' => 50_000]],
        ['account_id' => e4Account(6110), 'cost_center_id' => $ten, 'project_id' => $project, 'amounts' => ['2026-03' => 7_000]],
    ]);
}

/**
 * The version's lines summed per account and month, per cost centre and per
 * project: the three figures a SIE file carries.
 *
 * @return array<string, int>
 */
function e4Figures(BudgetVersion $version): array
{
    $figures = [];

    foreach (BudgetLine::query()->where('budget_version_id', $version->id)->with('account')->get() as $line) {
        $month = $line->period->format('Y-m');
        $account = $line->account->number;
        $codes = ['cc' => CostCenter::query()->whereKey($line->cost_center_id)->value('code'), 'p' => Project::query()->whereKey($line->project_id)->value('code')];

        $figures["{$account} {$month}"] = ($figures["{$account} {$month}"] ?? 0) + $line->amount_ore->ore;

        foreach ($codes as $kind => $code) {
            if ($code !== null) {
                $figures["{$account} {$month} {$kind}:{$code}"] = ($figures["{$account} {$month} {$kind}:{$code}"] ?? 0) + $line->amount_ore->ore;
            }
        }
    }

    ksort($figures);

    return array_filter($figures);
}

function e4ImportFull(User $user, Company $company, string $contents): SieImport
{
    $import = app(AnalyseSieFile::class)($user, $company, UploadedFile::fake()->createWithContent('bokforing.se', $contents));
    app(ConfirmSieImport::class)($user, $import);

    return $import->fresh();
}

function e4TempFile(string $contents): string
{
    $path = (string) tempnam(sys_get_temp_dir(), 'e4sie');
    file_put_contents($path, $contents);

    return $path;
}

it('leaves the budget out of the SIE file unless "Ta med budget" is ticked', function () {
    e4Budget($this->owner, $this->company, $this->year);

    $without = e4Export($this->owner, $this->year, false);
    $with = e4Export($this->owner, $this->year, true);

    expect($without)->not->toContain('#PBUDGET')
        ->and($with)->toContain('#PBUDGET 0 202601 3001 {} -10000.00')
        ->and(e4Ledger((string) preg_replace('/^#PBUDGET.*\r\n/m', '', $with)))->toBe(e4Ledger($without));

    $audit = AuditLog::query()->where('action', 'sie.exported')->latest('id')->firstOrFail();

    expect($audit->payload)->toMatchArray(['budget_version_id' => BudgetVersion::query()->value('id'), 'budget_records' => 8]);
});

it('writes the active budget exactly as the golden file', function () {
    // The golden file is written by version 0.1.0 without a build number.
    config(['app.version' => '0.1.0']);
    BuildInfo::fake(['build' => null, 'sha' => null, 'built_at' => null]);

    e4Budget($this->owner, $this->company, $this->year);
    $file = e4Export($this->owner, $this->year, true);

    if (getenv('UPDATE_SIE_FIXTURES')) {
        file_put_contents(E4_EXPORT_FIXTURE, $file);
    }

    expect($file)->toBe(file_get_contents(E4_EXPORT_FIXTURE));

    BuildInfo::fake(null);
});

it('writes nothing for the budget when the year has no active budget', function () {
    e4Budget($this->owner, $this->company, $this->year)->forceFill(['is_active' => false])->save();

    expect(e4Export($this->owner, $this->year, true))->not->toContain('#PBUDGET');
});

it('moves the budget to a fresh company with the bookkeeping and writes the same file back', function () {
    $source = e4Budget($this->owner, $this->company, $this->year);
    $file = e4Export($this->owner, $this->year, true);
    $expected = e4Figures($source);

    // The target company has the same organisation number, as a company moving between programs does.
    $this->company->forceFill(['org_number' => '556987-6542'])->save();
    [$owner, $target] = Bookkeeping::company();
    $target->forceFill(['org_number' => '556123-4567', 'legal_form' => 'AB', 'bas_version' => 'BAS 2026'])->save();
    CurrentCompany::set($target);
    $this->actingAs($owner);
    $owner->forceFill(['name' => 'Anna Andersson'])->save();

    $import = e4ImportFull($owner, $target, $file);
    $year = FiscalYear::query()->whereDate('start_date', '2026-01-01')->sole();
    $version = BudgetVersion::query()->sole();

    expect($import->status)->toBe(SieImportStatus::Completed)
        ->and($import->result)->toMatchArray(['budget_version_id' => $version->id, 'budget_lines' => 7])
        ->and($version->is_active)->toBeTrue()
        ->and($version->name)->toBe('Budget från SIE-fil')
        ->and($version->fiscal_year_id)->toBe($year->id)
        ->and(CostCenter::query()->orderBy('code')->pluck('code')->all())->toBe(['10', '20'])
        ->and(e4Figures($version))->toBe($expected)
        ->and(e4Ledger(e4Export($owner, $year, true)))->toBe(e4Ledger($file));

    $audit = AuditLog::query()->where('action', 'budget.created')->latest('id')->firstOrFail();

    expect($audit->payload)->toMatchArray(['start' => 'sie', 'source' => 'sie_import', 'sie_import_id' => $import->id, 'lines' => 7])
        ->and(json_encode($audit->payload))->not->toContain('Budget från');
});

it('shows the budget in the report of a whole SIE4 file before anything is read in', function () {
    e4Budget($this->owner, $this->company, $this->year);
    $file = e4Export($this->owner, $this->year, true);
    $this->company->forceFill(['org_number' => '556987-6542'])->save();
    [$owner, $target] = Bookkeeping::company();
    $target->forceFill(['org_number' => '556123-4567'])->save();
    CurrentCompany::set($target);
    $this->actingAs($owner);

    $import = app(AnalyseSieFile::class)($owner, $target, UploadedFile::fake()->createWithContent('bokforing.se', $file));

    expect($import->report['budget'])->toBe(['lines' => 7, 'accounts' => 3, 'skipped' => false])
        ->and(BudgetVersion::query()->count())->toBe(0);

    Livewire::test(SieImportReport::class, ['sieImport' => $import])
        ->assertSee('Budget')
        ->assertSee('7 rader på 3 konton, blir en ny budget');
});

it('reads the bookkeeping in even when the budget cannot be read, and says so', function () {
    $contents = (string) iconv('UTF-8', 'CP437', implode("\r\n", [
        '#FLAGGA 0', '#FORMAT PC8', '#SIETYP 4', '#ORGNR 556123-4567', '#RAR 0 20260101 20261231',
        '#PBUDGET 0 202701 3001 {} -100.00',
        '#VER A 1 20260210 "Test"', '{', '#TRANS 1930 {} 100.00', '#TRANS 3001 {} -100.00', '}',
    ])."\r\n");

    $import = e4ImportFull($this->owner, $this->company, $contents);

    expect($import->status)->toBe(SieImportStatus::Completed)
        ->and($import->report['budget']['skipped'])->toBeTrue()
        ->and(implode(' ', $import->report['warnings']))->toContain('Budgeten i filen (#PBUDGET) läses inte in, men bokföringen gör det')
        ->and(BudgetVersion::query()->count())->toBe(0);
});

it('reads only the budget of a type 2 file into a new budget, in two steps with toasts', function () {
    $upload = UploadedFile::fake()->createWithContent('budget.se', (string) file_get_contents(E4_TYPE2_FIXTURE));

    $component = Livewire::test(BudgetSieImport::class)
        ->set('file', $upload)
        ->call('analyse')
        ->assertDispatched('toast', type: 'success')
        ->assertSee('7 rader på 3 konton')
        ->assertSee('Läs in budgeten');

    $report = $component->get('report');

    expect($report['blockers'])->toBe([])
        ->and($report['result_ore'])->toBe(2_415_050)
        ->and(implode(' ', $report['warnings']))->toContain('andra räkenskapsår')->toContain('1930')->toContain('SIE-typ 2')->toContain('Kvantiteter')
        ->and(BudgetVersion::query()->count())->toBe(0);

    $component->call('import');
    $version = BudgetVersion::query()->sole();

    $component->assertRedirect(route('accounting.budgets.show', ['budgetVersion' => $version->id]));

    expect($version->is_active)->toBeTrue()
        ->and(e4Figures($version))->toBe([
            '3001 2026-01' => -1_200_000, '3001 2026-02' => -1_200_000, '3001 2026-03' => -1_250_050,
            '5010 2026-01' => 400_000, '5010 2026-02' => 400_000, '5010 2026-03' => 400_000,
            '6110 2026-02' => 35_000,
        ])
        ->and(session(Toast::SESSION_KEY))->toBe([['message' => 'Budgeten är inläst som en ny budget med 7 rader.', 'type' => 'success']])
        ->and(DB::table('vouchers')->where('company_id', $this->company->id)->count())->toBe(0)
        ->and(AuditLog::query()->where('action', 'budget.created')->latest('id')->firstOrFail()->payload)
        ->toMatchArray(['start' => 'sie', 'source' => 'sie_file', 'sha256' => hash_file('sha256', E4_TYPE2_FIXTURE), 'lines' => 7]);
});

it('does not make an imported budget active when the year already has one', function () {
    $existing = e4Budget($this->owner, $this->company, $this->year);

    $version = app(ImportSieBudget::class)($this->owner, $this->company, E4_TYPE2_FIXTURE, 'x');

    expect($version->is_active)->toBeFalse()
        ->and($existing->fresh()->is_active)->toBeTrue();
});

it('refuses a budget file that cannot be read in here, and says why', function (array $lines, string $reason) {
    $contents = (string) iconv('UTF-8', 'CP437', implode("\r\n", ['#FLAGGA 0', '#FORMAT PC8', ...$lines])."\r\n");

    $component = Livewire::test(BudgetSieImport::class)
        ->set('file', UploadedFile::fake()->createWithContent('budget.se', $contents))
        ->call('analyse')
        ->assertDispatched('toast', type: 'error')
        ->assertDontSeeHtml('wire:click="import"');

    expect(implode(' ', $component->get('report')['blockers']))->toContain($reason);

    expect(fn () => app(ImportSieBudget::class)($this->owner, $this->company, e4TempFile($contents), 'x'))->toThrow(ValidationException::class)
        ->and(BudgetVersion::query()->count())->toBe(0);
})->with([
    'a year that is not here' => [['#SIETYP 2', '#RAR 0 20270101 20271231', '#PBUDGET 0 202701 3001 {} -1.00'], 'finns inte här'],
    'another organisation number' => [['#SIETYP 2', '#ORGNR 556334-3689', '#RAR 0 20260101 20261231', '#PBUDGET 0 202601 3001 {} -1.00'], 'organisationsnummer'],
    'type 1' => [['#SIETYP 1', '#RAR 0 20260101 20261231'], 'SIE-typ 1'],
    'no budget' => [['#SIETYP 2', '#RAR 0 20260101 20261231', '#PSALDO 0 202601 3001 {} -1.00'], 'ingen budget'],
    'an account that is not in the chart' => [['#SIETYP 2', '#RAR 0 20260101 20261231', '#PBUDGET 0 202601 3999 {} -1.00'], 'Konto 3999 finns inte'],
    'a cost centre that is not here' => [['#SIETYP 3', '#RAR 0 20260101 20261231', '#PBUDGET 0 202601 5010 {1 "77"} 1.00'], 'Kostnadsställe 77 finns inte'],
    'the same month twice' => [['#SIETYP 2', '#RAR 0 20260101 20261231', '#PBUDGET 0 202601 5010 {} 1.00', '#PBUDGET 0 202601 5010 {} 2.00'], 'finns redan på rad'],
]);

it('refuses the budget import to a member without budgets:manage', function () {
    $bookkeeper = Bookkeeping::member($this->company, 'bookkeeper');
    $this->actingAs($bookkeeper);

    Livewire::test(BudgetSieImport::class)
        ->assertDontSee('Läs in budget från SIE-fil')
        ->set('file', UploadedFile::fake()->createWithContent('budget.se', (string) file_get_contents(E4_TYPE2_FIXTURE)))
        ->call('analyse')
        ->assertForbidden();

    expect(fn () => app(ImportSieBudget::class)($bookkeeper, $this->company, E4_TYPE2_FIXTURE, 'x'))->toThrow(AuthorizationException::class);
});

it('offers "Ta med budget" only for a year with an active budget, unticked, and downloads with it', function () {
    Livewire::test(FiscalYears::class)->assertDontSee('Ta med budget');

    e4Budget($this->owner, $this->company, $this->year);

    Livewire::test(FiscalYears::class)
        ->assertSee('Ta med budget')
        ->assertSeeHtml('name="budget" value="1" class')
        ->assertDontSeeHtml('name="budget" value="1" checked');

    expect($this->get(route('accounting.fiscalYears.sie', $this->year))->streamedContent())->not->toContain('#PBUDGET')
        ->and($this->get(route('accounting.fiscalYears.sie', ['fiscalYear' => $this->year, 'budget' => 1]))->streamedContent())->toContain('#PBUDGET');
});

it('renders the year list and reads a budget file in a fixed number of queries', function () {
    e4Budget($this->owner, $this->company, $this->year);
    // The page checks the first year's dates while there are one or two years; from three on every check runs.
    FiscalYear::create(['start_date' => '2025-01-01', 'end_date' => '2025-12-31']);
    FiscalYear::create(['start_date' => '2027-01-01', 'end_date' => '2027-12-31']);
    Livewire::test(FiscalYears::class);

    $count = function (Closure $callback): int {
        DB::flushQueryLog();
        DB::enableQueryLog();
        $callback();
        $queries = count(DB::getQueryLog());
        DB::disableQueryLog();

        return $queries;
    };

    $file = function (int $months, array $accounts): string {
        $lines = ['#FLAGGA 0', '#SIETYP 3', '#ORGNR 556123-4567', '#RAR 0 20260101 20261231'];

        foreach (range(1, $months) as $month) {
            foreach ($accounts as $account) {
                $lines[] = sprintf('#PBUDGET 0 2026%02d %d {} %d.00', $month, $account, $month * 100);
                $lines[] = sprintf('#PBUDGET 0 2026%02d %d {1 "10"} %d.00', $month, $account, $month * 40);
                $lines[] = sprintf('#PBUDGET 0 2026%02d %d {6 "P1"} %d.00', $month, $account, $month * 10);
            }
        }

        return e4TempFile(implode("\r\n", $lines)."\r\n");
    };

    $short = $count(fn () => Livewire::test(FiscalYears::class));
    $analyseShort = $count(fn () => app(SieBudgetAnalyser::class)->analyse($this->company, $file(1, [5010])));

    foreach (['2024', '2023', '2022'] as $label) {
        $year = FiscalYear::create(['start_date' => "{$label}-01-01", 'end_date' => "{$label}-12-31"]);
        app(CreateBudgetVersion::class)($this->owner, $this->company, ['fiscal_year_id' => $year->id, 'name' => "Budget {$label}", 'start' => CreateBudgetVersion::EMPTY]);
    }

    $plan = app(SieBudgetAnalyser::class)->analyse($this->company, $file(12, [5010, 6110, 3001]));
    // The opening-balance notice caches its check per company; new years clear it, so warm it again.
    Livewire::test(FiscalYears::class);

    expect($plan->blockers)->toBe([])
        ->and(count($plan->lines))->toBe(108)
        ->and($count(fn () => Livewire::test(FiscalYears::class)))->toBe($short)
        ->and($count(fn () => app(SieBudgetAnalyser::class)->analyse($this->company, $file(12, [5010, 6110, 3001]))))->toBe($analyseShort);
});

function e4LedgerFile(array $budget): string
{
    return (string) iconv('UTF-8', 'CP437', implode("\r\n", [
        '#FLAGGA 0', '#FORMAT PC8', '#SIETYP 4', '#ORGNR 556123-4567', '#RAR 0 20260101 20261231',
        ...$budget,
        '#VER A 1 20260210 "Test"', '{', '#TRANS 1930 {} 100.00', '#TRANS 3001 {} -100.00', '}',
    ])."\r\n");
}

it('reads the bookkeeping in when a budget object code is too long, and leaves only the budget out', function () {
    $import = e4ImportFull($this->owner, $this->company, e4LedgerFile([
        '#PBUDGET 0 202601 5010 {} 100.00',
        '#PBUDGET 0 202601 5010 {1 "ABCDEFGHIJKLMNOPQRSTU"} 100.00',
    ]));

    expect($import->status)->toBe(SieImportStatus::Completed)
        ->and($import->report['blockers'])->toBe([])
        ->and($import->report['budget']['skipped'])->toBeTrue()
        ->and(implode(' ', $import->report['warnings']))->toContain('längre än 20 tecken')
        ->and(CostCenter::query()->count())->toBe(0)
        ->and(BudgetVersion::query()->count())->toBe(0);
});

it('creates no accounts or objects for the budget of a user who may not make budgets', function () {
    $path = e4TempFile(e4LedgerFile([
        '#PBUDGET 0 202601 3999 {} -100.00',
        '#PBUDGET 0 202601 5010 {1 "88"} 100.00',
    ]));

    $without = app(SieImportAnalyser::class)->analyse($this->company, $path, budgets: false);
    $with = app(SieImportAnalyser::class)->analyse($this->company, $path);

    expect($without->budgetRefused)->toBeTrue()
        ->and($without->budgetLines)->toBe([])
        ->and(array_keys($without->accountsToCreate))->not->toContain(3999)
        ->and($without->objectsToCreate)->toBe([])
        ->and(implode(' ', $without->warnings))->toContain('du har inte behörighet att göra budgetar')
        ->and(array_keys($with->accountsToCreate))->toContain(3999)
        ->and(array_keys($with->objectsToCreate[Dimension::CostCenter->value] ?? []))->toContain(88);
});

it('refuses the budget to a member who may export SIE but not see budgets, on the server', function () {
    e4Budget($this->owner, $this->company, $this->year);
    // No role has export:sie without budgets:view today; deny budgets:view the way such a role would.
    Gate::before(fn (User $user, string $ability, array $arguments) => $ability === 'viewAny' && ($arguments[0] ?? null) === BudgetVersion::class ? false : null);
    $exports = fn (): int => AuditLog::query()->where('action', 'sie.exported')->count();

    $this->get(route('accounting.fiscalYears.sie', ['fiscalYear' => $this->year, 'budget' => 1]))->assertForbidden();

    expect($exports())->toBe(0);

    $plain = $this->get(route('accounting.fiscalYears.sie', $this->year));
    $plain->assertOk();

    expect($plain->streamedContent())->not->toContain('#PBUDGET')
        ->and($exports())->toBe(1)
        ->and(fn () => e4Export($this->owner, $this->year, true))->toThrow(AuthorizationException::class)
        ->and($exports())->toBe(1);
});

it('reads a budget file in once, however often the button is pressed', function () {
    $component = Livewire::test(BudgetSieImport::class)
        ->set('file', UploadedFile::fake()->createWithContent('budget.se', (string) file_get_contents(E4_TYPE2_FIXTURE)))
        ->call('analyse');

    // A request that arrives while the first one holds the lock does nothing.
    $lock = Cache::lock("budget-sie-import:{$this->owner->id}:".hash_file('sha256', E4_TYPE2_FIXTURE), 60);
    $lock->get();
    $component->call('import');
    $lock->release();

    expect(BudgetVersion::query()->count())->toBe(0);

    $component->call('import')->call('import');

    expect(BudgetVersion::query()->count())->toBe(1)
        ->and($component->get('report'))->toBeNull();
});
