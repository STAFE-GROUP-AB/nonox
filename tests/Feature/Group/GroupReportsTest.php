<?php

declare(strict_types=1);

use App\Actions\Accounting\InitialiseBookkeeping;
use App\Actions\Group\ConfirmGroupMember;
use App\Actions\Group\ConsolidateGroup;
use App\Actions\Group\CreateCorporateGroup;
use App\Actions\Group\GrantGroupRole;
use App\Actions\Group\RecordGroupEntry;
use App\Actions\Group\RecordHolding;
use App\Actions\Group\RemoveGroupMember;
use App\Domain\Accounting\AccountType;
use App\Domain\Group\ControlBasis;
use App\Domain\Group\EliminationSuggestions;
use App\Domain\Group\GroupAccess;
use App\Domain\Group\GroupAccessLevel;
use App\Domain\Group\GroupReport;
use App\Http\Controllers\Group\GroupReportController;
use App\Livewire\Group\ConsolidationPage;
use App\Livewire\Group\GroupReportsPage;
use App\Models\Account;
use App\Models\Company;
use App\Models\CompanyHolding;
use App\Models\CorporateGroupRun;
use App\Models\GroupEntry;
use App\Models\IntercompanyRowTag;
use App\Models\User;
use App\Models\Voucher;
use App\Support\Tenancy\CurrentCompany;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Tests\Support\Bookkeeping;

/*
 * Koncern → Koncernrapporter (ADR-060, addendum 2026-11-07, 3.2 S2-1.6;
 * ADR-081 E26). A parent P owns A and B. Olle owns P and is administrator
 * in A and B. A sells to B for 1 000 kr on credit, P lends A 500 kr and P
 * sells externally for 300 kr. A run before any elimination is the
 * companies' own figures; after the eliminations are accepted, a second run
 * of the same period is the group's. The report of the second run must show
 * the first run's figures, plus the group ledger's rows, equal to the second
 * run's figures, on every row and every total.
 */
beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-11-18 10:00:00'));
    $this->olle = User::factory()->withCompany()->create(['name' => 'Olle']);
    $this->p = $this->olle->currentCompany();
    $this->p->forceFill(['name' => 'Moder Exempel AB'])->save();
    app(InitialiseBookkeeping::class)($this->p, $this->olle, CarbonImmutable::parse('2026-01-15'));

    foreach (['a' => 'Dotter A Exempel AB', 'b' => 'Dotter B Exempel AB'] as $key => $name) {
        $owner = User::factory()->withCompany()->create();
        $company = $owner->currentCompany();
        $company->forceFill(['name' => $name])->save();
        app(InitialiseBookkeeping::class)($company, $owner, CarbonImmutable::parse('2026-01-15'));
        $company->users()->attach($this->olle, ['role' => 'admin']);
        $this->{$key} = $company;
    }

    foreach ([$this->p, $this->a, $this->b] as $company) {
        e26Accounts($company, [1320, 1510, 2360, 2440, 3011, 4010]);
    }

    $this->olle = $this->olle->fresh();
    $this->holdingA = e26Holding($this->olle, $this->p, $this->a);
    $this->holdingB = e26Holding($this->olle, $this->p, $this->b);
    $this->group = app(CreateCorporateGroup::class)($this->olle->fresh(), $this->p, 'Exempelkoncernen');
    app(ConfirmGroupMember::class)($this->olle->fresh(), $this->group->id, $this->a);
    app(ConfirmGroupMember::class)($this->olle->fresh(), $this->group->id, $this->b);

    $sale = e26Post($this->olle, $this->a, '2026-04-01', [1510 => 100_000, 3011 => -100_000]);
    e26Tag($this->a, $sale, 1510, $this->b, 'RECEIVABLE');
    e26Tag($this->a, $sale, 3011, $this->b, 'SALES');
    $purchase = e26Post($this->olle, $this->b, '2026-04-01', [4010 => 100_000, 2440 => -100_000]);
    e26Tag($this->b, $purchase, 4010, $this->a, 'PURCHASES');
    e26Tag($this->b, $purchase, 2440, $this->a, 'PAYABLE');
    $loan = e26Post($this->olle, $this->p, '2026-05-01', [1320 => 50_000, 1930 => -50_000]);
    e26Tag($this->p, $loan, 1320, $this->a, 'LOAN_RECEIVABLE');
    $borrowed = e26Post($this->olle, $this->a, '2026-05-01', [1930 => 50_000, 2360 => -50_000]);
    e26Tag($this->a, $borrowed, 2360, $this->p, 'LOAN_PAYABLE');
    e26Post($this->olle, $this->p, '2026-06-01', [1930 => 30_000, 3011 => -30_000]);
});

function e26Holding(User $user, Company $holder, Company $held): CompanyHolding
{
    return app(RecordHolding::class)($user->fresh(), $holder, [
        'held_org_number' => $held->org_number,
        'held_name' => $held->name,
        'capital' => '100',
        'voting' => '100',
        'control_basis' => ControlBasis::Votes->value,
        'acquired_on' => '2024-01-01',
    ]);
}

/** @param  list<int>  $numbers */
function e26Accounts(Company $company, array $numbers): void
{
    CurrentCompany::run($company, function () use ($numbers): void {
        foreach ($numbers as $number) {
            if (! Account::query()->where('number', $number)->exists()) {
                Account::create(['number' => $number, 'name' => "Konto {$number}", 'type' => AccountType::forNumber($number), 'is_active' => true]);
            }
        }
    });
}

/** @param  array<int, int>  $rows  account => signed öre */
function e26Post(User $user, Company $company, string $date, array $rows): Voucher
{
    return CurrentCompany::run($company, fn () => Bookkeeping::postRows($user->fresh(), $company, $date, $rows));
}

function e26Tag(Company $company, Voucher $voucher, int $account, Company $counterparty, string $kind): void
{
    CurrentCompany::run($company, function () use ($voucher, $account, $counterparty, $kind): void {
        $row = $voucher->rows()->whereHas('account', fn ($query) => $query->where('number', $account))->firstOrFail();
        IntercompanyRowTag::create(['voucher_row_id' => $row->id, 'counterparty_org_number' => $counterparty->org_number, 'kind' => $kind, 'source' => 'MANUAL']);
    });
}

function e26AcceptAll(object $test): void
{
    $suggestions = app(EliminationSuggestions::class)->compute(GroupAccess::for($test->olle->fresh()), $test->group->fresh(), CarbonImmutable::parse('2026-01-01'), CarbonImmutable::parse('2026-12-31'))['suggestions'];

    foreach ($suggestions as $suggestion) {
        if ($suggestion['status'] === EliminationSuggestions::MATCHED) {
            app(RecordGroupEntry::class)->accept($test->olle->fresh(), $test->group->id, ['from' => '2026-01-01', 'to' => '2026-12-31', 'key' => $suggestion['key'], 'fingerprint' => $suggestion['fingerprint']]);
        }
    }
}

function e26Run(object $test, ?User $user = null, string $from = '2026-01-01', string $to = '2026-12-31'): CorporateGroupRun
{
    return app(ConsolidateGroup::class)(($user ?? $test->olle)->fresh(), $test->group->id, ['from' => $from, 'to' => $to]);
}

/** @return array<string, mixed> */
function e26Report(CorporateGroupRun $run, User $user): array
{
    return GroupReport::build(GroupAccess::for($user->fresh()), $run->fresh());
}

/**
 * Every account row of both statements, as number => [companies, eliminations, group].
 *
 * @param  array<string, mixed>  $report
 * @return array<int, array{0: ?int, 1: ?int, 2: int}>
 */
function e26Rows(array $report): array
{
    $rows = [];
    $groups = [...$report['income']['groups'], ...$report['balance']['assets']['groups'], ...$report['balance']['equity']['groups']];

    foreach ($groups as $group) {
        foreach ($group['rows'] as $row) {
            $rows[$row['number']] = [$row['companies'], $row['eliminations'], $row['group']];
        }
    }

    ksort($rows);

    return $rows;
}

/** @return array<int, int> account => amount as presented in a run's output, over both statements */
function e26Figures(CorporateGroupRun $run): array
{
    $amounts = [];

    foreach ([$run->output['income']['groups'], $run->output['balance']['assets']['groups'], $run->output['balance']['equity']['groups']] as $groups) {
        foreach ($groups as $group) {
            foreach ($group['rows'] as $row) {
                $amounts[$row['number']] = $row['amount'];
            }
        }
    }

    return $amounts;
}

/** @return list<list<string>> */
function e26Csv(string $content): array
{
    return array_map(fn (string $line): array => str_getcsv($line, ';', '"', ''), explode("\n", trim(ltrim($content, "\xEF\xBB\xBF"))));
}

it('shows the companies\' figures plus the eliminations equal to the run, on every row and every total', function () {
    $gross = e26Run($this);
    e26AcceptAll($this);
    $run = e26Run($this);
    $report = e26Report($run, $this->olle);

    expect($report['detailed'])->toBeTrue()
        ->and($report['consistent'])->toBeTrue()
        ->and($report['entries_intact'])->toBeTrue()
        ->and($run->inputs['group_entries'])->toHaveCount(3);

    // The group ledger's rows, summed per group account independently of the report, presented as the statements present them.
    $presented = [];

    foreach (GroupEntry::query()->whereIn('id', array_column($run->inputs['group_entries'], 'id'))->with('rows')->get() as $entry) {
        foreach ($entry->rows as $row) {
            $sign = $row->group_account_number < 2000 ? 1 : -1;
            $presented[$row->group_account_number] = ($presented[$row->group_account_number] ?? 0) + $sign * ($row->debit_ore - $row->credit_ore);
        }
    }

    $companies = e26Figures($gross);
    $group = e26Figures($run);

    foreach (e26Rows($report) as $number => [$own, $eliminated, $total]) {
        expect($own + $eliminated)->toBe($total, "account {$number}")
            ->and($own)->toBe($companies[$number] ?? 0, "companies {$number}")
            ->and($eliminated)->toBe($presented[$number] ?? 0, "eliminations {$number}")
            ->and($total)->toBe($group[$number] ?? 0, "group {$number}");
    }

    // Every account the run or the entries touch is in the report, also one eliminated to zero.
    expect(array_keys(e26Rows($report)))->toEqualCanonicalizing(array_keys($companies + $group + $presented))
        // By hand: A's 1 000 and P's 300 in sales, A's sale to B eliminated.
        ->and(e26Rows($report)[3011])->toBe([130_000, -100_000, 30_000])
        ->and(e26Rows($report)[4010])->toBe([-100_000, 100_000, 0])
        ->and(e26Rows($report)[1510])->toBe([100_000, -100_000, 0])
        ->and(e26Rows($report)[2360])->toBe([50_000, -50_000, 0]);

    // The totals: the result, the subtotals and both sides of the balance sheet.
    $result = $report['income']['result'];
    $assets = $report['balance']['assets']['total'];
    $equity = $report['balance']['equity']['total'];

    expect($result['companies'] + $result['eliminations'])->toBe($result['group'])
        ->and($result['companies'])->toBe($gross->output['income']['result'])
        ->and($result['group'])->toBe($run->output['income']['result'])
        ->and($assets['companies'])->toBe($gross->output['balance']['assets']['total'])
        ->and($assets['group'])->toBe($run->output['balance']['assets']['total'])
        ->and($equity['companies'])->toBe($gross->output['balance']['equity']['total'])
        ->and($equity['group'])->toBe($run->output['balance']['equity']['total'])
        ->and($assets['companies'] + $assets['eliminations'])->toBe($assets['group'])
        ->and($equity['companies'] + $equity['eliminations'])->toBe($equity['group']);

    foreach ($report['income']['groups'] as $line) {
        expect($line['total']['companies'] + $line['total']['eliminations'])->toBe($line['total']['group']);

        if ($line['subtotal'] !== null) {
            expect($line['subtotal']['companies'] + $line['subtotal']['eliminations'])->toBe($line['subtotal']['group']);
        }
    }
});

it('equals each company\'s own ledger in the column Företagen when nothing is eliminated', function () {
    $run = e26Run($this);
    $rows = e26Rows(e26Report($run, $this->olle));

    // P sold for 300 kr, A for 1 000 kr; B bought for 1 000 kr. Nothing is accepted, so nothing is eliminated.
    expect($rows[3011])->toBe([130_000, 0, 130_000])
        ->and($rows[4010])->toBe([-100_000, 0, -100_000])
        ->and($rows[1930])->toBe([30_000, 0, 30_000])
        ->and($run->inputs['group_entries'])->toBe([]);
});

it('downloads the report as CSV with the label first and in the file name', function () {
    e26AcceptAll($this);
    $run = e26Run($this);
    $this->actingAs($this->olle->fresh());

    $response = $this->get(route('group.reports.csv', ['run' => $run->id]))->assertOk();
    $lines = e26Csv($response->streamedContent());

    expect($response->headers->get('Content-Type'))->toContain('text/csv')
        ->and($response->headers->get('Content-Disposition'))->toContain("intern-koncernrapport_2026-01-01_2026-12-31_korning-{$run->id}.csv")
        ->and($response->headers->get('Content-Disposition'))->not->toContain('koncernredovisning')
        ->and($lines[0])->toBe(['Intern koncernrapport, inte en koncernredovisning enligt ÅRL'])
        ->and($lines)->toContain(['Konto', 'Benämning', 'Företagen', 'Elimineringar', 'Koncernen'])
        ->and($lines)->toContain(['3011', 'Konto 3011', '1300,00', '-1000,00', '300,00'])
        ->and($lines)->toContain(['Kontrollsumma för resultatet', $run->output_hash])
        ->and($lines[array_key_last($lines)])->toBe(['Intern koncernrapport, inte en koncernredovisning enligt ÅRL']);
});

it('downloads the report as PDF with the label on every page and in the file name', function () {
    e26AcceptAll($this);
    $run = e26Run($this);
    $this->actingAs($this->olle->fresh());

    $response = $this->get(route('group.reports.pdf', ['run' => $run->id]))->assertOk();

    expect($response->headers->get('Content-Type'))->toBe('application/pdf')
        ->and($response->headers->get('Content-Disposition'))->toContain("intern-koncernrapport_2026-01-01_2026-12-31_korning-{$run->id}.pdf")
        ->and(substr((string) $response->getContent(), 0, 5))->toBe('%PDF-');

    // The template the PDF is rendered from: the label as a fixed footer (every page) and at the top.
    $html = view('group.report-pdf', ['report' => e26Report($run, $this->olle)])->render();

    expect(substr_count($html, 'Intern koncernrapport, inte en koncernredovisning enligt ÅRL'))->toBeGreaterThanOrEqual(3)
        ->and($html)->toContain('<footer>')
        ->and($html)->toContain('Företagen');
});

it('gives a consolidated viewer the column Koncernen only, never a company or an account\'s elimination', function () {
    e26AcceptAll($this);
    $viewer = Bookkeeping::member($this->p, 'bookkeeper');
    app(GrantGroupRole::class)($this->olle->fresh(), $this->group->id, $viewer->id);
    $run = e26Run($this, $viewer);
    $report = e26Report($run, $viewer);

    expect($report['detailed'])->toBeFalse()
        ->and(collect(e26Rows($report))->every(fn (array $row): bool => $row[0] === null && $row[1] === null))->toBeTrue()
        // Accounts eliminated to zero are not in the run, so they are not shown: a zero row would reveal the elimination.
        ->and(e26Rows($report))->not->toHaveKey(1510)
        ->and(e26Rows($report)[3011])->toBe([null, null, 30_000]);

    $this->actingAs($viewer->fresh());
    $csv = $this->get(route('group.reports.csv', ['run' => $run->id]))->assertOk()->streamedContent();
    $lines = e26Csv($csv);

    expect($lines)->toContain(['Konto', 'Benämning', 'Koncernen'])
        ->and($csv)->not->toContain('Företagen;')
        ->and($csv)->not->toContain('-1000,00')
        // One pair per type: only the number of entries (E25, P2-2).
        ->and($lines)->toContain(['Försäljning och inköp', 'en post']);

    Livewire::test(GroupReportsPage::class)
        ->assertOk()
        ->assertSee('Intern koncernrapport, inte en koncernredovisning enligt ÅRL')
        ->assertSee('Koncernen')
        ->assertDontSee('Företagen är företagens egen bokföring')
        ->assertDontSee('1510 Kundfordringar');
});

it('withholds from a consolidated viewer a run where a company is cut, on the page and in the files', function () {
    $viewer = Bookkeeping::member($this->p, 'bookkeeper');
    app(GrantGroupRole::class)($this->olle->fresh(), $this->group->id, $viewer->id);
    // Olle (FULL) runs a period that starts before B was acquired: B is cut, so the run is one the viewer could subtract.
    CurrentCompany::run($this->p, fn () => CompanyHolding::query()->whereKey($this->holdingB->id)->update(['acquired_on' => '2026-03-01']));
    $run = e26Run($this);

    expect(fn () => e26Report($run, $viewer))->toThrow(ModelNotFoundException::class);

    $this->actingAs($viewer->fresh());
    $this->get(route('group.reports.csv', ['run' => $run->id]))->assertNotFound();
    $this->get(route('group.reports.pdf', ['run' => $run->id]))->assertNotFound();

    Livewire::test(GroupReportsPage::class)
        ->assertSee('Körningen visas inte')
        ->assertDontSee('Intern koncernrapport 2026-01-01');
});

it('withholds a run that read a company the user cannot see, though the user sees every member today', function () {
    e26AcceptAll($this);
    $run = e26Run($this);

    // Kim has reports:view in P and B, never in A. While A is a member, Kim does not see the group at all.
    $kim = Bookkeeping::member($this->p, 'accountant');
    $this->b->users()->attach($kim, ['role' => 'accountant']);

    expect(fn () => e26Report($run, $kim))->toThrow(ModelNotFoundException::class);

    // A leaves the group: Kim now sees every member, but the run still holds A's figures (Koncernen − P − B = A).
    app(RemoveGroupMember::class)($this->olle->fresh(), $this->group->id, $this->a->id);
    $kim = $kim->fresh();

    expect(ConsolidateGroup::level(GroupAccess::for($kim), $this->group->fresh(), $run->period_from))->toBe(GroupAccessLevel::Full)
        ->and(ConsolidateGroup::runLevel(GroupAccess::for($kim), $this->group->fresh(), $run->fresh()))->toBe(GroupAccessLevel::None)
        ->and(fn () => e26Report($run, $kim))->toThrow(ModelNotFoundException::class)
        ->and(fn () => app(ConsolidateGroup::class)->check($kim, $this->group->id, $run->id))->toThrow(ModelNotFoundException::class);

    $this->actingAs($kim);
    $this->get(route('group.reports.csv', ['run' => $run->id]))->assertNotFound();
    $this->get(route('group.reports.pdf', ['run' => $run->id]))->assertNotFound();

    Livewire::test(GroupReportsPage::class, ['run' => (string) $run->id])
        ->assertSee('Körningen visas inte')
        ->assertDontSee('Intern koncernrapport 2026-01-01')
        ->assertDontSee('Dotter A Exempel AB');
    Livewire::test(ConsolidationPage::class)
        ->assertSee('Körningen visas inte')
        ->assertDontSee('Dotter A Exempel AB');

    // A new run without A is Kim's to see in full.
    expect(e26Report(e26Run($this, $kim), $kim)['detailed'])->toBeTrue();
});

it('withholds every run from a member limited to cost centres in a subsidiary', function () {
    $run = e26Run($this);
    $limited = Bookkeeping::member($this->p, 'accountant');
    $this->a->users()->attach($limited, ['role' => 'accountant']);
    $this->b->users()->attach($limited, ['role' => 'bookkeeper', 'dimension_scoped' => true]);
    $limited = $limited->fresh();

    expect(ConsolidateGroup::runLevel(GroupAccess::for($limited), $this->group->fresh(), $run->fresh()))->toBe(GroupAccessLevel::None)
        ->and(fn () => e26Report($run, $limited))->toThrow(ModelNotFoundException::class);

    $this->actingAs($limited);
    $this->get(route('group.reports.csv', ['run' => $run->id]))->assertNotFound();
    $this->get(route('group.reports.pdf', ['run' => $run->id]))->assertNotFound();
});

it('says so and shows no breakdown when a group ledger row was changed outside NONOX', function () {
    e26AcceptAll($this);
    $run = e26Run($this);
    $entry = GroupEntry::query()->whereIn('id', array_column($run->inputs['group_entries'], 'id'))->orderBy('id')->firstOrFail();

    // Both rows of one entry changed alike, so it still balances: only the recomputed hash shows it.
    DB::statement('SET CONSTRAINTS ALL IMMEDIATE');
    DB::statement('ALTER TABLE group_entry_rows DISABLE TRIGGER USER');
    DB::table('group_entry_rows')->where('group_entry_id', $entry->id)->where('debit_ore', '>', 0)->update(['debit_ore' => 1_000]);
    DB::table('group_entry_rows')->where('group_entry_id', $entry->id)->where('credit_ore', '>', 0)->update(['credit_ore' => 1_000]);
    DB::statement('ALTER TABLE group_entry_rows ENABLE TRIGGER USER');

    $report = e26Report($run, $this->olle);

    expect($report['entries_intact'])->toBeFalse()
        ->and($report['detailed'])->toBeFalse()
        ->and(GroupReportController::rows($report))->toContain(['Körningen eller koncernjournalen stämmer inte med sina kontrollsummor. Använd inte siffrorna och kontakta den som driver NONOX.']);
});

it('downloads the CSV and builds the PDF in the same number of queries for a short and a long history', function () {
    $this->actingAs($this->olle->fresh());

    $count = function (int $extra): array {
        foreach (range(1, $extra) as $day) {
            $sale = e26Post($this->olle, $this->a, sprintf('2026-08-%02d', $day), [1510 => 1_000, 3011 => -1_000]);
            e26Tag($this->a, $sale, 3011, $this->b, 'SALES');
            app(RecordGroupEntry::class)->manual($this->olle->fresh(), $this->group->id, ['from' => '2026-01-01', 'to' => '2026-12-31', 'type' => 'OTHER', 'reason' => 'Prov', 'rows' => [
                ['company' => $this->a->id, 'account' => '3011', 'debit' => '1', 'credit' => ''],
                ['company' => $this->b->id, 'account' => '4010', 'debit' => '', 'credit' => '1'],
            ]]);
        }

        $run = e26Run($this);
        // A first request warms what any signed-in request loads once; only the second one is counted.
        $this->get(route('group.reports.csv', ['run' => $run->id]))->assertOk()->streamedContent();

        DB::flushQueryLog();
        DB::enableQueryLog();
        $this->get(route('group.reports.csv', ['run' => $run->id]))->assertOk()->streamedContent();
        $csv = count(DB::getQueryLog());

        DB::flushQueryLog();
        view('group.report-pdf', ['report' => GroupReport::build(GroupAccess::for($this->olle->fresh()), CorporateGroupRun::query()->with('runner:id,name')->findOrFail($run->id))])->render();
        $pdf = count(DB::getQueryLog());
        DB::disableQueryLog();

        return [$csv, $pdf];
    };

    $short = $count(1);
    $long = $count(12);

    expect($long)->toBe($short);
});

it('answers 404 for a run of a group the user does not see, and for a run that does not exist', function () {
    $run = e26Run($this);
    $stranger = User::factory()->withCompany()->create();
    $this->actingAs($stranger);

    $this->get(route('group.reports.csv', ['run' => $run->id]))->assertNotFound();
    $this->get(route('group.reports.pdf', ['run' => $run->id]))->assertNotFound();
    $this->get(route('group.reports', ['koncern' => $this->group->id]))->assertNotFound();

    $this->actingAs($this->olle->fresh());
    $this->get(route('group.reports.csv', ['run' => $run->id + 1000]))->assertNotFound();

    // An approver in A sees reports nowhere in the group: no FULL access, no role.
    $approver = Bookkeeping::member($this->a, 'approver');
    expect(fn () => e26Report($run, $approver))->toThrow(ModelNotFoundException::class);
});

it('shows the statements with both columns and the downloads on the page, and says so when there is no run', function () {
    $this->actingAs($this->olle->fresh());

    Livewire::test(GroupReportsPage::class)
        ->assertOk()
        ->assertSee('Ingen körning ännu')
        ->assertSee('Till Konsolidering');

    e26AcceptAll($this);
    $first = e26Run($this, null, '2026-01-01', '2026-06-30');
    $run = e26Run($this);

    Livewire::test(GroupReportsPage::class)
        ->assertSee('Intern koncernrapport 2026-01-01 – 2026-12-31')
        ->assertSee('Företagen')
        ->assertSee('Elimineringar')
        ->assertSee('1510 Kundfordringar')
        ->assertSee(route('group.reports.csv', ['run' => $run->id]))
        ->assertSee(route('group.reports.pdf', ['run' => $run->id]))
        ->call('selectRun', (string) $first->id)
        ->assertSee('Intern koncernrapport 2026-01-01 – 2026-06-30');

    $this->get(route('group.reports'))->assertOk()->assertSee('Koncernrapporter');
});

it('loads the page in the same number of queries for a short and a long history', function () {
    $this->actingAs($this->olle->fresh());

    $count = function (int $extra): int {
        foreach (range(1, $extra) as $day) {
            $sale = e26Post($this->olle, $this->a, sprintf('2026-07-%02d', $day), [1510 => 1_000, 3011 => -1_000]);
            e26Tag($this->a, $sale, 3011, $this->b, 'SALES');
            app(RecordGroupEntry::class)->manual($this->olle->fresh(), $this->group->id, ['from' => '2026-01-01', 'to' => '2026-12-31', 'type' => 'OTHER', 'reason' => 'Prov', 'rows' => [
                ['company' => $this->a->id, 'account' => '3011', 'debit' => '1', 'credit' => ''],
                ['company' => $this->b->id, 'account' => '4010', 'debit' => '', 'credit' => '1'],
            ]]);
            e26Run($this);
        }

        $page = Livewire::test(GroupReportsPage::class);

        DB::flushQueryLog();
        DB::enableQueryLog();
        $page->call('$refresh');
        $queries = count(DB::getQueryLog());
        DB::disableQueryLog();

        return $queries;
    };

    $short = $count(1);
    $long = $count(12);

    expect($long)->toBe($short);
});

it('builds the CSV lines from the report alone, the label first', function () {
    $run = e26Run($this);
    $rows = GroupReportController::rows(e26Report($run, $this->olle));

    expect($rows[0])->toBe(['Intern koncernrapport, inte en koncernredovisning enligt ÅRL'])
        ->and(GroupReportController::fileName(['from' => '2026-01-01', 'to' => '2026-12-31', 'run_id' => 7], 'csv'))->toBe('intern-koncernrapport_2026-01-01_2026-12-31_korning-7.csv');
});
