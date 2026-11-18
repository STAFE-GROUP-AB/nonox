<?php

declare(strict_types=1);

use App\Actions\Accounting\CreateAccount;
use App\Actions\Accounting\InitialiseBookkeeping;
use App\Actions\Group\ConfirmGroupMember;
use App\Actions\Group\ConsolidateGroup;
use App\Actions\Group\CreateCorporateGroup;
use App\Actions\Group\GrantGroupRole;
use App\Actions\Group\MapGroupAccount;
use App\Actions\Group\RecordHolding;
use App\Domain\Group\Consolidation;
use App\Domain\Group\ConsolidationView;
use App\Domain\Group\ControlBasis;
use App\Domain\Group\GroupAccessLevel;
use App\Livewire\Group\ConsolidationPage;
use App\Models\AuditLog;
use App\Models\Company;
use App\Models\CompanyHolding;
use App\Models\CorporateGroup;
use App\Models\CorporateGroupRun;
use App\Models\GroupAccountMap;
use App\Models\IntercompanyRowTag;
use App\Models\User;
use App\Models\VoucherRow;
use App\Support\Tenancy\CurrentCompany;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Tests\Support\Bookkeeping;

/*
 * The consolidation engine and its run log (ADR-060, addendum 2026-11-07,
 * 2.4; ADR-081 E24, S2-1.4). A parent P owns A and B. Olle owns P and is
 * administrator in A and B. The engine sums the companies' own posted
 * ledgers after the group's account map, cuts a subsidiary to the days it
 * was a member, stores a reproducible run, and never writes into any
 * company's books.
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

    $this->olle = $this->olle->fresh();
    $this->holdingA = e24Holding($this->olle, $this->p, $this->a);
    $this->holdingB = e24Holding($this->olle, $this->p, $this->b);
    $this->group = app(CreateCorporateGroup::class)($this->olle->fresh(), $this->p, 'Exempelkoncernen');
    app(ConfirmGroupMember::class)($this->olle->fresh(), $this->group->id, $this->a);
    app(ConfirmGroupMember::class)($this->olle->fresh(), $this->group->id, $this->b);

    // P sells for 1 000 kr; A sells for 500 kr on credit and pays 200 kr rent; B sells for 300 kr in February and buys for 100 kr in August.
    e24Post($this->olle, $this->p, '2026-03-01', [1930 => 100_000, 3001 => -100_000]);
    e24Post($this->olle, $this->a, '2026-04-01', [1510 => 50_000, 3001 => -50_000]);
    e24Post($this->olle, $this->a, '2026-05-01', [5010 => 20_000, 1930 => -20_000]);
    e24Post($this->olle, $this->b, '2026-02-01', [1930 => 30_000, 3001 => -30_000]);
    e24Post($this->olle, $this->b, '2026-08-01', [4010 => 10_000, 2440 => -10_000]);
});

function e24Holding(User $user, Company $holder, Company $held): CompanyHolding
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

/** @param  array<int, int>  $rows  account => signed öre */
function e24Post(User $user, Company $company, string $date, array $rows): void
{
    CurrentCompany::run($company, fn () => Bookkeeping::postRows($user->fresh(), $company, $date, $rows));
}

function e24Run(object $test, ?User $user = null, string $from = '2026-01-01', string $to = '2026-12-31'): CorporateGroupRun
{
    return app(ConsolidateGroup::class)(($user ?? $test->olle)->fresh(), $test->group->id, ['from' => $from, 'to' => $to]);
}

/** @return array<int, int> account => amount as presented, over the income statement */
function e24Income(CorporateGroupRun $run): array
{
    $amounts = [];

    foreach ($run->output['income']['groups'] as $group) {
        foreach ($group['rows'] as $row) {
            $amounts[$row['number']] = $row['amount'];
        }
    }

    return $amounts;
}

/** @return array<int, int> account => amount as presented, over both sides of the balance sheet */
function e24Balance(CorporateGroupRun $run): array
{
    $amounts = [];

    foreach (['assets', 'equity'] as $side) {
        foreach ($run->output['balance'][$side]['groups'] as $group) {
            foreach ($group['rows'] as $row) {
                $amounts[$row['number']] = $row['amount'];
            }
        }
    }

    return $amounts;
}

function e24SetHolding(Company $holder, CompanyHolding $holding, array $dates): void
{
    CurrentCompany::run($holder, fn () => CompanyHolding::query()->whereKey($holding->id)->update($dates));
}

/** @return list<string> every key anywhere in a nested array */
function e24Keys(array $value): array
{
    $keys = [];

    foreach ($value as $key => $item) {
        $keys[] = (string) $key;

        if (is_array($item)) {
            $keys = [...$keys, ...e24Keys($item)];
        }
    }

    return $keys;
}

it('sums the companies\' posted ledgers into a consolidated income statement and balance sheet', function () {
    $run = e24Run($this);

    expect(e24Income($run))->toBe([3001 => 180_000, 4010 => -10_000, 5010 => -20_000])
        ->and($run->output['income']['result'])->toBe(150_000)
        ->and(e24Balance($run))->toBe([1510 => 50_000, 1930 => 110_000, 2440 => 10_000])
        ->and($run->output['balance']['assets']['total'])->toBe(160_000)
        ->and($run->output['balance']['equity']['total'])->toBe(160_000)
        ->and($run->output['balance']['unbooked_result'])->toBe(150_000)
        ->and($run->output['result_outside_period'])->toBe(0)
        ->and($run->output['companies'])->toBe(['included' => 3, 'with_balance' => 3, 'cut' => 0])
        ->and($run->preliminary)->toBeTrue()
        ->and($run->intact())->toBeTrue()
        ->and(array_column($run->inputs['companies'], 'company_id'))->toBe([$this->p->id, ...collect([$this->a->id, $this->b->id])->sort()->values()->all()])
        ->and($run->inputs['group_entries'])->toBe([]);
});

it('stores the run append-only and logs it in the parent only, with counts', function () {
    $run = e24Run($this);
    $log = AuditLog::query()->where('action', 'corporate_group.consolidated')->sole();

    expect($log->company_id)->toBe($this->p->id)
        ->and($log->payload)->toEqual(['corporate_group_id' => $this->group->id, 'run_id' => $run->id, 'companies' => 3, 'excluded' => 0, 'preliminary' => true]);

    expect(fn () => $run->forceFill(['preliminary' => false])->save())->toThrow(LogicException::class)
        ->and(fn () => $run->delete())->toThrow(LogicException::class)
        ->and(fn () => DB::table('corporate_group_runs')->where('id', $run->id)->update(['preliminary' => false]))->toThrow(Exception::class, 'Consolidation runs cannot be changed or deleted.');
});

it('never writes into a member company\'s books, and every ledger still verifies', function () {
    $before = DB::table('voucher_rows')->whereIn('company_id', [$this->p->id, $this->a->id, $this->b->id])->orderBy('id')->get()->map(fn ($row): array => (array) $row)->all();
    $vouchers = DB::table('vouchers')->whereIn('company_id', [$this->p->id, $this->a->id, $this->b->id])->orderBy('id')->get()->map(fn ($row): array => (array) $row)->all();

    e24Run($this);
    app(MapGroupAccount::class)->save($this->olle->fresh(), $this->group->id, $this->a->id, ['account' => '5010', 'group_account' => '5011']);
    e24Run($this);

    expect(DB::table('voucher_rows')->whereIn('company_id', [$this->p->id, $this->a->id, $this->b->id])->orderBy('id')->get()->map(fn ($row): array => (array) $row)->all())->toBe($before)
        ->and(DB::table('vouchers')->whereIn('company_id', [$this->p->id, $this->a->id, $this->b->id])->orderBy('id')->get()->map(fn ($row): array => (array) $row)->all())->toBe($vouchers);

    foreach ([$this->p, $this->a, $this->b] as $company) {
        $this->artisan('ledger:verify', ['company' => $company->org_number])->assertSuccessful();
    }
});

it('gives the same output for the same inputs, and changed inputs when the books, tags or map change', function () {
    $first = e24Run($this);
    $second = e24Run($this);

    expect($second->inputs_hash)->toBe($first->inputs_hash)
        ->and($second->output_hash)->toBe($first->output_hash)
        ->and(app(ConsolidateGroup::class)->check($this->olle->fresh(), $this->group->id, $first->id))->toBe(['inputs_unchanged' => true, 'output_reproduced' => true, 'stored_intact' => true]);

    // A row tag changes the inputs but no figure: tags are metadata beside the rows.
    $row = CurrentCompany::run($this->a, fn () => VoucherRow::query()->whereHas('account', fn ($query) => $query->where('number', 1510))->firstOrFail());
    CurrentCompany::run($this->a, fn () => IntercompanyRowTag::create(['voucher_row_id' => $row->id, 'counterparty_org_number' => $this->b->org_number, 'kind' => 'RECEIVABLE', 'source' => 'MANUAL']));
    $tagged = e24Run($this);

    expect($tagged->inputs_hash)->not->toBe($first->inputs_hash)
        ->and($tagged->output_hash)->toBe($first->output_hash)
        ->and(app(ConsolidateGroup::class)->check($this->olle->fresh(), $this->group->id, $first->id)['inputs_unchanged'])->toBeFalse();

    // A voucher posted later changes both.
    e24Post($this->olle, $this->b, '2026-09-01', [1930 => 5_000, 3001 => -5_000]);
    $posted = e24Run($this);

    expect($posted->inputs_hash)->not->toBe($tagged->inputs_hash)
        ->and($posted->output_hash)->not->toBe($tagged->output_hash)
        ->and(e24Income($posted)[3001])->toBe(185_000);

    app(MapGroupAccount::class)->save($this->olle->fresh(), $this->group->id, $this->b->id, ['account' => '4010', 'group_account' => '4000']);

    expect(e24Run($this)->inputs_hash)->not->toBe($posted->inputs_hash);
});

it('applies the account map of one company without touching the others', function () {
    app(MapGroupAccount::class)->save($this->olle->fresh(), $this->group->id, $this->a->id, ['account' => '3001', 'group_account' => '3002']);

    $run = e24Run($this);

    expect(e24Income($run))->toBe([3001 => 130_000, 3002 => 50_000, 4010 => -10_000, 5010 => -20_000])
        ->and($run->output['income']['result'])->toBe(150_000);
});

it('counts a subsidiary\'s income from the acquisition day and its balance when it is a member on the last day', function () {
    e24SetHolding($this->p, $this->holdingB, ['acquired_on' => '2026-07-01']);

    $run = e24Run($this);
    $b = collect($run->inputs['companies'])->firstWhere('company_id', $this->b->id);

    // B's February sale is before the acquisition: in its balance sheet, but not in the group's income.
    expect(e24Income($run))->toBe([3001 => 150_000, 4010 => -10_000, 5010 => -20_000])
        ->and($run->output['balance']['unbooked_result'])->toBe(150_000)
        ->and($run->output['result_outside_period'])->toBe(30_000)
        ->and($b)->toMatchArray(['income_from' => '2026-07-01', 'income_to' => '2026-12-31', 'balance' => true])
        ->and($run->output['companies']['cut'])->toBe(1);
});

it('counts a disposed subsidiary\'s income until the day before the disposal and leaves its balance out', function () {
    e24SetHolding($this->p, $this->holdingB, ['disposed_on' => '2026-08-01']);

    $run = e24Run($this);

    // The purchase on the disposal day is no longer the group's.
    expect(e24Income($run))->toBe([3001 => 180_000, 5010 => -20_000])
        ->and(e24Balance($run))->toBe([1510 => 50_000, 1930 => 80_000])
        ->and(collect($run->inputs['companies'])->firstWhere('company_id', $this->b->id))->toMatchArray(['income_from' => '2026-01-01', 'income_to' => '2026-07-31', 'balance' => false]);
});

it('leaves out a subsidiary acquired after the period, and one whose fiscal year does not cover it', function () {
    e24SetHolding($this->p, $this->holdingB, ['acquired_on' => '2027-02-01']);
    CurrentCompany::run($this->a, fn () => DB::table('fiscal_years')->where('company_id', $this->a->id)->update(['start_date' => '2026-02-01', 'end_date' => '2027-01-31']));

    $run = e24Run($this);

    expect(collect($run->inputs['excluded'])->pluck('reason', 'company_id')->all())->toEqual([$this->a->id => Consolidation::EXCLUDED_NO_FISCAL_YEAR, $this->b->id => Consolidation::EXCLUDED_NOT_MEMBER])
        ->and(e24Income($run))->toBe([3001 => 100_000])
        ->and($run->output['companies']['included'])->toBe(1);
});

it('refuses a period outside the parent\'s fiscal years and an inverted period, in Swedish', function () {
    expect(fn () => e24Run($this, from: '2025-12-01', to: '2026-01-31'))->toThrow(ValidationException::class, 'Moderföretaget har inget räkenskapsår som omfattar hela perioden.')
        ->and(fn () => e24Run($this, from: '2026-05-01', to: '2026-04-01'))->toThrow(ValidationException::class, 'Periodens sista dag kan inte vara före den första.')
        ->and(fn () => e24Run($this, from: '2026-02-30', to: '2026-04-01'))->toThrow(ValidationException::class)
        ->and(CorporateGroupRun::query()->count())->toBe(0);
});

it('blocks a group whose holdings exceed 100 % of the votes in one company until corrected', function () {
    // A second holding in B, recorded by A's own owner who does not administer the group (ADR-060 S2-1.1: no vote oracle).
    CurrentCompany::run($this->a, fn () => CompanyHolding::create(['held_org_number' => $this->b->org_number, 'held_name' => $this->b->name, 'capital_bp' => 3000, 'voting_bp' => 3000, 'control_basis' => 'NONE', 'acquired_on' => '2025-01-01']));

    expect(fn () => e24Run($this))->toThrow(ValidationException::class, 'mer än 100 % av rösterna');

    CurrentCompany::run($this->a, fn () => CompanyHolding::query()->where('held_org_number', $this->b->org_number)->update(['disposed_on' => '2026-01-01']));

    expect(e24Run($this)->output['income']['result'])->toBe(150_000);
});

it('blocks a member with another base currency', function () {
    $this->b->forceFill(['base_currency' => 'EUR'])->save();

    expect(fn () => e24Run($this))->toThrow(ValidationException::class, 'annan redovisningsvaluta');
});

it('is preliminary until every company has locked the period or closed the year', function () {
    expect(e24Run($this)->preliminary)->toBeTrue();

    foreach ([$this->p, $this->a] as $company) {
        $company->forceFill(['locked_until_date' => '2026-12-31'])->save();
    }

    expect(e24Run($this)->preliminary)->toBeTrue();

    $this->b->forceFill(['locked_until_date' => '2027-01-31'])->save();

    expect(e24Run($this)->preliminary)->toBeFalse();
});

it('lets a consolidated viewer run and read the consolidation, but never a per-company figure', function () {
    $viewer = Bookkeeping::member($this->p, 'bookkeeper');
    app(GrantGroupRole::class)($this->olle->fresh(), $this->group->id, $viewer->id);

    $run = e24Run($this, $viewer);
    $view = ConsolidationView::present($run, GroupAccessLevel::Consolidated);

    expect($view['companies'])->toBe([])
        ->and($view['excluded'])->toBe([])
        ->and($view['result'])->toBe(150_000)
        ->and(e24Keys($run->output))->not->toContain('company_id')
        ->and(e24Keys($view))->not->toContain('company_id')
        ->and(e24Keys($view))->not->toContain('income_from');

    $this->actingAs($viewer->fresh());
    Livewire::test(ConsolidationPage::class)
        ->assertOk()
        ->assertSee('Intern koncernrapport, inte en koncernredovisning enligt ÅRL')
        ->assertSee('Dotter A Exempel AB')
        // The Swedish plural from the package's sv.json, not the English one (AppServiceProvider empties the cache).
        ->assertSee('3 företag ingår')
        ->assertDontSee('Vad körningen läste')
        ->assertDontSee('Kontomappning');
});

it('answers 404 to a member who does not see every company, and to a limited member of the parent with the role', function () {
    $partial = Bookkeeping::member($this->p, 'accountant');
    $this->a->users()->attach($partial, ['role' => 'accountant']);

    expect(fn () => e24Run($this, $partial))->toThrow(ModelNotFoundException::class);

    $this->actingAs($partial->fresh());
    $this->get(route('group.consolidation', ['koncern' => $this->group->id]))->assertNotFound();
    $this->get(route('group.consolidation'))->assertOk()->assertSee('Du ser ingen koncern')->assertDontSee('Exempelkoncernen');

    $limited = Bookkeeping::member($this->p, 'bookkeeper');
    app(GrantGroupRole::class)($this->olle->fresh(), $this->group->id, $limited->id);
    $this->p->users()->updateExistingPivot($limited->id, ['dimension_scoped' => true]);

    expect(fn () => e24Run($this, $limited))->toThrow(ModelNotFoundException::class);
});

it('drops FULL access at once when a membership in one company ends', function () {
    $user = Bookkeeping::member($this->p, 'accountant');
    $this->a->users()->attach($user, ['role' => 'accountant']);
    $this->b->users()->attach($user, ['role' => 'accountant']);

    $run = e24Run($this, $user);
    $this->b->users()->detach($user);

    expect(fn () => app(ConsolidateGroup::class)->check($user->fresh(), $this->group->id, $run->id))->toThrow(ModelNotFoundException::class)
        ->and(fn () => e24Run($this, $user))->toThrow(ModelNotFoundException::class);
});

it('lets only whoever administers the group keep the account map, inside the company, and logs it there', function () {
    $accountant = Bookkeeping::member($this->p, 'accountant');
    $this->a->users()->attach($accountant, ['role' => 'accountant']);
    $this->b->users()->attach($accountant, ['role' => 'accountant']);

    expect(fn () => app(MapGroupAccount::class)->save($accountant->fresh(), $this->group->id, $this->a->id, ['account' => '3001', 'group_account' => '3002']))->toThrow(AuthorizationException::class);

    $map = app(MapGroupAccount::class)->save($this->olle->fresh(), $this->group->id, $this->a->id, ['account' => '3001', 'group_account' => '3002']);
    $log = AuditLog::query()->where('action', 'group_account_map.saved')->sole();

    expect($map->company_id)->toBe($this->a->id)
        ->and($log->company_id)->toBe($this->a->id)
        ->and($log->payload)->toEqual(['corporate_group_id' => $this->group->id, 'account_number' => 3001, 'group_account_number' => 3002]);

    // Saving again changes the row instead of adding one.
    app(MapGroupAccount::class)->save($this->olle->fresh(), $this->group->id, $this->a->id, ['account' => '3001', 'group_account' => '3003']);

    expect(CurrentCompany::run($this->a, fn () => GroupAccountMap::query()->pluck('group_account_number')->all()))->toBe([3003]);

    app(MapGroupAccount::class)->remove($this->olle->fresh(), $this->group->id, $map->id);

    expect(CurrentCompany::run($this->a, fn () => GroupAccountMap::query()->count()))->toBe(0)
        ->and(AuditLog::query()->where('action', 'group_account_map.removed')->sole()->company_id)->toBe($this->a->id);
});

it('validates the account map in Swedish', function (array $input, string $message) {
    expect(fn () => app(MapGroupAccount::class)->save($this->olle->fresh(), $this->group->id, $this->a->id, $input))->toThrow(ValidationException::class, $message);
})->with([
    'another class' => [['account' => '3001', 'group_account' => '4001'], 'samma kontoklass'],
    'identity' => [['account' => '3001', 'group_account' => '3001'], 'Det behövs ingen mappning'],
    'outside the chart range' => [['account' => '9100', 'group_account' => '9101'], 'mellan 1000 och 8999'],
    'unknown account' => [['account' => '3999', 'group_account' => '3001'], 'Företaget har inget konto 3999'],
]);

it('refuses to map an account of a company outside the group as if it did not exist', function () {
    [, $outside] = Bookkeeping::company();
    $outside->users()->attach($this->olle, ['role' => 'admin']);

    expect(fn () => app(MapGroupAccount::class)->save($this->olle->fresh(), $this->group->id, $outside->id, ['account' => '3001', 'group_account' => '3002']))->toThrow(ModelNotFoundException::class);
});

it('runs, shows, checks and maps through the page with Swedish toasts', function () {
    $this->actingAs($this->olle->fresh());

    $page = Livewire::test(ConsolidationPage::class)
        ->assertOk()
        ->assertSet('group', (string) $this->group->id)
        ->assertSet('from', '2026-01-01')
        ->assertSet('to', '2026-12-31')
        ->assertSee('Ingen körning ännu')
        ->call('consolidate')
        ->assertDispatched('toast', message: 'Konsolideringen är klar och sparad i körloggen. Den är preliminär eftersom någon period inte är låst.', type: 'success')
        ->assertSee('Konsoliderat 2026-01-01 – 2026-12-31')
        ->assertSee('Vad körningen läste')
        ->assertSee('Körlogg');

    $run = CorporateGroupRun::query()->sole();

    $page->call('check', $run->id)
        ->assertDispatched('toast', message: 'Indata är oförändrade och ger samma siffror som när körningen gjordes.', type: 'success')
        ->set('mapCompany', (string) $this->a->id)
        ->set('mapAccount', '3001')
        ->set('mapGroupAccount', '4001')
        ->call('saveMap')
        ->assertDispatched('toast', message: 'Koncernkontot måste vara i samma kontoklass som företagets konto (samma första siffra).', type: 'error')
        ->set('mapGroupAccount', '3002')
        ->call('saveMap')
        ->assertDispatched('toast', message: 'Kontomappningen är sparad. Den gäller från nästa konsolidering.', type: 'success');

    $page->call('check', $run->id)
        ->assertDispatched('toast', message: 'Bokföringen, markeringarna eller kontomappningen har ändrats sedan körningen. Kör en ny konsolidering för aktuella siffror.', type: 'error')
        ->set('from', '2026-06-01')
        ->set('to', '2026-05-01')
        ->call('consolidate')
        ->assertDispatched('toast', message: 'Periodens sista dag kan inte vara före den första.', type: 'error');

    $map = CurrentCompany::run($this->a, fn () => GroupAccountMap::query()->sole());
    $page->call('removeMap', $map->id)
        ->assertDispatched('toast', message: 'Kontomappningen är borttagen. Kontot följer sitt eget nummer från nästa konsolidering.', type: 'success');
});

it('runs the consolidation in the same number of queries for a short and a long history', function () {
    $count = function (int $extra): int {
        foreach (range(1, $extra) as $day) {
            foreach ([$this->p, $this->a, $this->b] as $company) {
                e24Post($this->olle, $company, sprintf('2026-06-%02d', $day), [1930 => 1_000, 3001 => -1_000]);
            }
        }

        e24Run($this);

        DB::flushQueryLog();
        DB::enableQueryLog();
        e24Run($this);
        $queries = count(DB::getQueryLog());
        DB::disableQueryLog();

        return $queries;
    };

    $short = $count(1);
    $long = $count(20);

    expect($long)->toBe($short);
});

it('loads the page in the same number of queries for a short and a long run log', function () {
    $this->actingAs($this->olle->fresh());

    $count = function (int $runs): int {
        foreach (range(1, $runs) as $ignored) {
            e24Run($this);
        }

        $this->get(route('group.consolidation'))->assertOk();

        DB::flushQueryLog();
        DB::enableQueryLog();
        $this->get(route('group.consolidation'))->assertOk()->assertSee('Körlogg');
        $queries = count(DB::getQueryLog());
        DB::disableQueryLog();

        return $queries;
    };

    $short = $count(1);
    $long = $count(25);

    expect($long)->toBe($short);
});

it('says so on the page when the group can no longer be seen', function () {
    $user = Bookkeeping::member($this->p, 'accountant');
    $this->a->users()->attach($user, ['role' => 'accountant']);
    $this->b->users()->attach($user, ['role' => 'accountant']);
    $this->actingAs($user->fresh());

    $page = Livewire::test(ConsolidationPage::class)->assertSet('group', (string) $this->group->id);
    $this->b->users()->detach($user);
    // The next request reads the memberships afresh, as a real one does.
    $this->actingAs($user->fresh());

    $page->call('$refresh')
        ->assertDispatched('toast', message: 'Koncernen visas inte längre. Du kan ha förlorat behörigheten i ett av företagen.', type: 'error')
        ->assertSet('group', '');

    expect(CorporateGroup::query()->count())->toBe(1);
});

it('never computes or shows a run of fewer than two companies to someone who sees only the sums', function () {
    // Cold review E24, P1-1: an approver in the parent without reports:view, with the group role, picked a period
    // before the subsidiaries were acquired, and the "consolidated" figures were the parent's own books.
    $approver = Bookkeeping::member($this->p, 'approver');
    app(GrantGroupRole::class)($this->olle->fresh(), $this->group->id, $approver->id);
    e24SetHolding($this->p, $this->holdingA, ['acquired_on' => '2026-07-01']);
    e24SetHolding($this->p, $this->holdingB, ['acquired_on' => '2026-07-01']);

    expect(fn () => e24Run($this, $approver, '2026-01-01', '2026-06-30'))->toThrow(ValidationException::class, 'Den här körningen kan inte visas för den som bara ser koncernens summor, eftersom den har färre än två företag eller något företag har lämnats utanför eller bara ingår en del av perioden.')
        ->and(CorporateGroupRun::query()->count())->toBe(0);

    // FULL access keeps everything: the same period runs, and its stored run stays hidden from the viewer.
    $own = e24Run($this, from: '2026-01-01', to: '2026-06-30');

    expect(e24Income($own))->toBe([3001 => 100_000])
        ->and(fn () => app(ConsolidateGroup::class)->check($approver->fresh(), $this->group->id, $own->id))->toThrow(ValidationException::class, 'bara ser koncernens summor');

    $this->actingAs($approver->fresh());
    Livewire::test(ConsolidationPage::class)
        ->set('run', (string) $own->id)
        ->assertSee('Körningen visas inte')
        ->assertDontSee('Konsoliderat 2026-01-01 – 2026-06-30');
});

it('never shows a consolidated viewer a run with a company left out by its fiscal year', function () {
    $viewer = Bookkeeping::member($this->p, 'bookkeeper');
    app(GrantGroupRole::class)($this->olle->fresh(), $this->group->id, $viewer->id);
    CurrentCompany::run($this->a, fn () => DB::table('fiscal_years')->where('company_id', $this->a->id)->update(['start_date' => '2026-02-01', 'end_date' => '2027-01-31']));

    expect(fn () => e24Run($this, $viewer))->toThrow(ValidationException::class, 'bara ser koncernens summor');

    $full = e24Run($this);

    expect(collect($full->inputs['excluded'])->pluck('company_id')->all())->toBe([$this->a->id])
        ->and(ConsolidateGroup::shownToConsolidated($full->inputs))->toBeFalse();
});

it('never shows a consolidated viewer a run where a member is cut at a disposal or an acquisition', function () {
    // Re-check of P1-1: with A and B disposed of on the last day, the run holds only P's balance sheet, and its
    // difference to the run to the day before would be A's and B's balance sheets account by account.
    $viewer = Bookkeeping::member($this->p, 'bookkeeper');
    app(GrantGroupRole::class)($this->olle->fresh(), $this->group->id, $viewer->id);
    e24SetHolding($this->p, $this->holdingA, ['disposed_on' => '2026-08-01']);
    e24SetHolding($this->p, $this->holdingB, ['disposed_on' => '2026-08-01']);

    expect(fn () => e24Run($this, $viewer, '2026-01-01', '2026-08-01'))->toThrow(ValidationException::class, 'bara ingår en del av perioden')
        ->and(e24Run($this, $viewer, '2026-01-01', '2026-07-31')->inputs['companies'])->toHaveCount(3);

    $full = e24Run($this, from: '2026-01-01', to: '2026-08-01');

    expect($full->output['companies']['cut'])->toBe(2)
        ->and(ConsolidateGroup::shownToConsolidated($full->inputs))->toBeFalse();

    // An acquisition during the period, with the period starting before it, is cut the same way.
    e24SetHolding($this->p, $this->holdingA, ['disposed_on' => null, 'acquired_on' => '2026-03-01']);
    e24SetHolding($this->p, $this->holdingB, ['disposed_on' => null]);

    expect(fn () => e24Run($this, $viewer, '2026-01-01', '2026-12-31'))->toThrow(ValidationException::class, 'bara ingår en del av perioden')
        ->and(e24Run($this, $viewer, '2026-03-01', '2026-12-31')->inputs['companies'])->toHaveCount(3);
});

it('reads a subsidiary in its first, shorter fiscal year from that year', function () {
    e24SetHolding($this->p, $this->holdingB, ['acquired_on' => '2026-02-01']);
    CurrentCompany::run($this->b, fn () => DB::table('fiscal_years')->where('company_id', $this->b->id)->update(['start_date' => '2026-02-01']));

    $run = e24Run($this);

    expect($run->inputs['excluded'])->toBe([])
        ->and(collect($run->inputs['companies'])->firstWhere('company_id', $this->b->id))->toMatchArray(['income_from' => '2026-02-01', 'balance' => true])
        ->and(e24Income($run))->toBe([3001 => 180_000, 4010 => -10_000, 5010 => -20_000]);
});

it('names a group account from the parent\'s chart or the BAS standard, never with a subsidiary\'s own text', function () {
    app(CreateAccount::class)($this->olle->fresh(), $this->a, ['number' => 3011, 'name' => 'Hemligt namn i dotterbolaget']);
    DB::table('accounts')->where('company_id', $this->a->id)->where('number', 3001)->update(['name' => 'Dotterbolagets eget namn']);
    e24Post($this->olle, $this->a, '2026-06-01', [1930 => 7_000, 3011 => -7_000]);

    $run = e24Run($this);
    $names = collect($run->output['income']['groups'])->flatMap(fn (array $group) => $group['rows'])->pluck('name', 'number')->all();
    $parentName = DB::table('accounts')->where('company_id', $this->p->id)->where('number', 3001)->value('name');

    expect($names[3011])->toBe('')
        ->and($names[3001])->toBe($parentName)
        ->and(json_encode($run->output, JSON_UNESCAPED_UNICODE))->not->toContain('Hemligt namn')->not->toContain('Dotterbolagets eget namn')
        ->and($run->inputs['bas_names_hash'])->toBe(Consolidation::hash(Consolidation::basNames()));

    // A new BAS file names accounts differently: that is a changed input, so check() never reports a false mismatch.
    $cache = new ReflectionProperty(Consolidation::class, 'basNames');
    $original = $cache->getValue();

    try {
        $cache->setValue(null, [...Consolidation::basNames(), 3011 => 'Nytt BAS-namn']);

        expect(app(ConsolidateGroup::class)->check($this->olle->fresh(), $this->group->id, $run->id))->toBe(['inputs_unchanged' => false, 'output_reproduced' => null, 'stored_intact' => true]);
    } finally {
        $cache->setValue(null, $original);
    }
});

it('reads in a REPEATABLE READ, READ ONLY transaction when none is open', function () {
    // The test's own transaction is on the default connection; a second connection to the same database has none.
    config(['database.connections.e24_snapshot' => config('database.connections.'.config('database.default'))]);
    $connection = DB::connection('e24_snapshot');

    try {
        $seen = ConsolidateGroup::snapshot(fn (): array => [
            'level' => $connection->transactionLevel(),
            'isolation' => $connection->selectOne('SHOW transaction_isolation')->transaction_isolation,
            'read_only' => $connection->selectOne('SHOW transaction_read_only')->transaction_read_only,
        ], 'e24_snapshot');

        expect($seen)->toBe(['level' => 1, 'isolation' => 'repeatable read', 'read_only' => 'on'])
            ->and(fn () => ConsolidateGroup::snapshot(fn () => $connection->update('UPDATE corporate_group_runs SET preliminary = preliminary WHERE false'), 'e24_snapshot'))
            ->toThrow(Exception::class, 'read-only transaction')
            ->and($connection->transactionLevel())->toBe(0);
    } finally {
        DB::purge('e24_snapshot');
    }
});
