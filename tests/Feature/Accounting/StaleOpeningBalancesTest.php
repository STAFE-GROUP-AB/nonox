<?php

declare(strict_types=1);

/*
 * Opening balances that no longer agree with the year before (ÅRL 2 kap. 4 §
 * första stycket 7, read on riksdagen.se 2026-11-04: "Den ingående balansen
 * för ett räkenskapsår ska stämma överens med den utgående balansen för
 * närmast föregående räkenskapsår"; the second paragraph allows a deviation
 * for special reasons). ADR-010, "Stale opening balances".
 */

use App\Actions\Accounting\CarryForwardBalances;
use App\Actions\Accounting\UpdateOpeningBalances;
use App\Actions\Closing\YearEndClosing;
use App\Domain\Accounting\StaleOpeningBalances;
use App\Domain\Closing\ClosingChecklist;
use App\Domain\Overview\Todos\OpeningBalanceTodos;
use App\Livewire\Accounting\FiscalYears;
use App\Livewire\Accounting\Reports;
use App\Livewire\Accounting\StaleOpeningBalancesNotice;
use App\Models\Account;
use App\Models\AuditLog;
use App\Models\FiscalYear;
use App\Models\OpeningBalance;
use App\Models\Voucher;
use App\Support\Tenancy\CurrentCompany;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Tests\Support\Bookkeeping;

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2027-01-15 10:00:00'));
    [$this->owner, $this->company] = Bookkeeping::company();
    CurrentCompany::set($this->company);
    $this->actingAs($this->owner);
    $this->year = FiscalYear::query()->whereDate('start_date', '2026-01-01')->sole();

    // The first year in NONOX: opening balances as from an SIE file, with no year before.
    OpeningBalance::create(['fiscal_year_id' => $this->year->id, 'account_id' => Account::query()->where('number', 1930)->value('id'), 'amount_ore' => 5_000_000]);
    OpeningBalance::create(['fiscal_year_id' => $this->year->id, 'account_id' => Account::query()->where('number', 2081)->value('id'), 'amount_ore' => -5_000_000]);
    Bookkeeping::post($this->owner, $this->company, 300_000, 1930, 3001, '2026-04-01');
    Bookkeeping::post($this->owner, $this->company, 100_000, 6110, 1930, '2026-05-01');
    $this->next = app(CarryForwardBalances::class)($this->owner, $this->year);
});

function ibOf(FiscalYear $year): array
{
    return OpeningBalance::query()->where('opening_balances.fiscal_year_id', $year->id)
        ->join('accounts', 'accounts.id', '=', 'opening_balances.account_id')
        ->orderBy('accounts.number')
        ->pluck('opening_balances.amount_ore', 'accounts.number')
        ->map(fn ($amount) => $amount->ore)
        ->all();
}

it('finds nothing while the opening balances equal last year\'s closing balances', function () {
    expect(StaleOpeningBalances::compute($this->company))->toBe([])
        ->and(StaleOpeningBalances::for($this->company))->toBe([]);
});

it('finds the accounts that differ after a late posting in the old year', function () {
    expect(StaleOpeningBalances::for($this->company))->toBe([]);

    Bookkeeping::post($this->owner, $this->company, 50_000, 1930, 3001, '2026-12-30');

    // The cached answer is refreshed by the posting (Översikt's version changes after commit).
    $stale = StaleOpeningBalances::for($this->company);

    expect($stale)->toHaveCount(1)
        ->and($stale[0])->toMatchArray(['from_id' => $this->year->id, 'to_id' => $this->next->id, 'from' => '2026', 'to' => '2027', 'to_open' => true, 'locked' => false])
        ->and(array_map(fn (array $account): array => [$account['number'], $account['stored'], $account['expected']], $stale[0]['accounts']))
        ->toBe([[1930, 5_200_000, 5_250_000], [2099, -200_000, -250_000]]);
});

it('updates the opening balances, touches no voucher and records before and after', function () {
    Bookkeeping::post($this->owner, $this->company, 50_000, 1930, 3001, '2026-12-30');
    $vouchers = DB::table('vouchers')->where('company_id', $this->company->id)->orderBy('id')->get()->toArray();

    $updated = app(UpdateOpeningBalances::class)($this->owner, $this->company);

    expect($updated)->toBe(['2027'])
        ->and(ibOf($this->next))->toBe([1930 => 5_250_000, 2081 => -5_000_000, 2099 => -250_000])
        ->and(StaleOpeningBalances::compute($this->company))->toBe([])
        ->and(DB::table('vouchers')->where('company_id', $this->company->id)->orderBy('id')->get()->toArray())->toEqual($vouchers)
        ->and(AuditLog::forCompany($this->company)->where('action', 'fiscal_year.opening_balances_updated')->sole()->payload)->toEqual([
            'from_id' => $this->year->id,
            'to_id' => $this->next->id,
            'from' => '2026',
            'to' => '2027',
            'accounts' => 2,
            'changes' => [
                ['account' => 1930, 'before_ore' => 5_200_000, 'after_ore' => 5_250_000],
                ['account' => 2099, 'before_ore' => -200_000, 'after_ore' => -250_000],
            ],
        ])
        ->and(AuditLog::forCompany($this->company)->where('action', 'fiscal_year.balances_carried_forward')->count())->toBe(2);

    expect(fn () => app(UpdateOpeningBalances::class)($this->owner, $this->company))->toThrow(ValidationException::class, 'stämmer redan');
});

it('refuses when the later year is closed', function () {
    Bookkeeping::post($this->owner, $this->company, 50_000, 1930, 3001, '2026-12-30');
    $this->next->forceFill(['status' => 'CLOSED'])->save();

    expect(StaleOpeningBalances::compute($this->company)[0]['to_open'])->toBeFalse()
        ->and(fn () => app(UpdateOpeningBalances::class)($this->owner, $this->company))->toThrow(ValidationException::class, 'Räkenskapsåret 2027 är stängt')
        ->and(ibOf($this->next))->toBe([1930 => 5_200_000, 2081 => -5_000_000, 2099 => -200_000]);
});

it('refuses when the period lock covers the first day of the later year, but the closing still carries forward', function () {
    Bookkeeping::post($this->owner, $this->company, 50_000, 1930, 3001, '2026-12-30');
    $this->company->forceFill(['locked_until_date' => '2027-01-31'])->save();

    expect(StaleOpeningBalances::compute($this->company->fresh())[0]['locked'])->toBeTrue()
        ->and(fn () => app(UpdateOpeningBalances::class)($this->owner, $this->company))->toThrow(ValidationException::class, 'låst till och med 2027-01-31')
        ->and(fn () => app(CarryForwardBalances::class)($this->owner, $this->year))->toThrow(ValidationException::class, 'De förs över på nytt när du stänger 2026 under Bokslut')
        ->and(ibOf($this->next))->toBe([1930 => 5_200_000, 2081 => -5_000_000, 2099 => -200_000]);

    app(CarryForwardBalances::class)($this->owner, $this->year, partOfClosing: true);

    expect(ibOf($this->next))->toBe([1930 => 5_250_000, 2081 => -5_000_000, 2099 => -250_000]);
});

it('does not compare a first year with opening balances from a file, or a year after an empty one', function () {
    // An empty year before the first one: nothing booked in NONOX to compare with.
    FiscalYear::create(['start_date' => '2025-01-01', 'end_date' => '2025-12-31']);
    // 2026's opening balances differ from anything 2025 would give, and that is fine.
    expect(StaleOpeningBalances::compute($this->company))->toBe([]);

    $checklist = collect(ClosingChecklist::for($this->company, $this->year))->keyBy('key');
    expect($checklist['opening']['status'])->toBe(ClosingChecklist::OK);

    $alone = Bookkeeping::company()[1];
    $first = CurrentCompany::run($alone, fn () => FiscalYear::query()->sole());
    expect(collect(ClosingChecklist::for($alone, $first))->keyBy('key')['opening']['status'])->toBe(ClosingChecklist::NOT_APPLICABLE);
});

it('follows the chain N → N+1 → N+2 and updates every year in order', function () {
    Bookkeeping::post($this->owner, $this->company, 70_000, 1930, 3001, '2027-01-10');
    $third = app(CarryForwardBalances::class)($this->owner, $this->next);
    expect(ibOf($third))->toBe([1930 => 5_270_000, 2081 => -5_000_000, 2099 => -270_000]);

    Bookkeeping::post($this->owner, $this->company, 50_000, 1930, 3001, '2026-12-30');
    $stale = StaleOpeningBalances::compute($this->company);

    expect(array_column($stale, 'to'))->toBe(['2027', '2028'])
        ->and(array_map(fn (array $account): array => [$account['number'], $account['stored'], $account['expected']], $stale[1]['accounts']))
        ->toBe([[1930, 5_270_000, 5_320_000], [2099, -270_000, -320_000]]);

    expect(app(UpdateOpeningBalances::class)($this->owner, $this->company))->toBe(['2027', '2028'])
        ->and(ibOf($third))->toBe([1930 => 5_320_000, 2081 => -5_000_000, 2099 => -320_000])
        ->and(StaleOpeningBalances::compute($this->company))->toBe([])
        ->and(AuditLog::forCompany($this->company)->where('action', 'fiscal_year.opening_balances_updated')->count())->toBe(2);
});

it('reads with a fixed number of queries, however many years and vouchers', function () {
    Bookkeeping::post($this->owner, $this->company, 50_000, 1930, 3001, '2026-12-30');
    $count = function (): int {
        DB::flushQueryLog();
        DB::enableQueryLog();
        StaleOpeningBalances::compute($this->company);
        $queries = count(DB::getQueryLog());
        DB::disableQueryLog();

        return $queries;
    };

    $short = $count();

    foreach (range(1, 15) as $day) {
        Bookkeeping::post($this->owner, $this->company, 1_000 * $day, 1930, 3001, sprintf('2026-11-%02d', $day));
        Bookkeeping::post($this->owner, $this->company, 1_000 * $day, 1930, 3001, sprintf('2027-01-%02d', $day));
    }
    app(CarryForwardBalances::class)($this->owner, $this->next);

    expect($count())->toBe($short)
        ->and($short)->toBe(4)
        ->and(array_column(StaleOpeningBalances::compute($this->company), 'to'))->toBe(['2027', '2028']);
});

it('shows the warning, the differences and the update button on Räkenskapsår', function () {
    Bookkeeping::post($this->owner, $this->company, 50_000, 1930, 3001, '2026-12-30');

    Livewire::test(FiscalYears::class)->assertSeeLivewire(StaleOpeningBalancesNotice::class);

    Livewire::test(StaleOpeningBalancesNotice::class)
        ->assertSee('De ingående balanserna för 2027 stämmer inte längre med de utgående balanserna för 2026 (2 konton skiljer).')
        ->assertSee('Visa skillnaden per konto')
        ->assertSee('Uppdatera ingående balanser')
        ->assertSee('Inga verifikationer ändras')
        ->call('update')
        ->assertDispatched('toast', message: 'De ingående balanserna för 2027 är uppdaterade.')
        ->assertDispatched('opening-balances-updated')
        ->assertDontSee('stämmer inte längre');

    expect(ibOf($this->next))->toBe([1930 => 5_250_000, 2081 => -5_000_000, 2099 => -250_000]);
});

it('shows the warning on the reports of the later year only', function () {
    Bookkeeping::post($this->owner, $this->company, 50_000, 1930, 3001, '2026-12-30');

    Livewire::withQueryParams(['ar' => (string) $this->next->id, 'rapport' => 'balans'])->test(Reports::class)->assertSeeLivewire(StaleOpeningBalancesNotice::class);
    Livewire::test(StaleOpeningBalancesNotice::class, ['fiscalYearId' => $this->next->id])->assertSee('stämmer inte längre');
    Livewire::test(StaleOpeningBalancesNotice::class, ['fiscalYearId' => $this->year->id])->assertDontSee('stämmer inte längre');
});

it('lists it under Att göra and in the year-end checklist of the later year', function () {
    Bookkeeping::post($this->owner, $this->company, 50_000, 1930, 3001, '2026-12-30');

    $todos = app(OpeningBalanceTodos::class)->todos($this->owner, $this->company, CarbonImmutable::today());
    expect($todos)->toHaveCount(1)
        ->and($todos[0]->title)->toBe('Uppdatera ingående balanser för 2027')
        ->and($todos[0]->subtitle)->toBe('2 konton skiljer från de utgående balanserna för 2026.')
        ->and(app(OpeningBalanceTodos::class)->todos(Bookkeeping::member($this->company, 'bookkeeper'), $this->company, CarbonImmutable::today()))->toBe([]);

    $item = collect(ClosingChecklist::for($this->company, $this->next))->keyBy('key')['opening'];
    expect($item['status'])->toBe(ClosingChecklist::TODO)
        ->and($item['confirm'])->toBe('Skillnaden är avsiktlig');

    // A person may confirm a deliberate difference (the second paragraph of ÅRL 2 kap. 4 §).
    app(YearEndClosing::class)->check($this->owner, $this->next, 'opening', true);
    expect(collect(ClosingChecklist::for($this->company, $this->next))->keyBy('key')['opening']['status'])->toBe(ClosingChecklist::OK);
});

it('lets only those who manage fiscal years update, and shows others no button', function () {
    Bookkeeping::post($this->owner, $this->company, 50_000, 1930, 3001, '2026-12-30');
    $bookkeeper = Bookkeeping::member($this->company, 'bookkeeper');
    $this->actingAs($bookkeeper);

    expect(fn () => app(UpdateOpeningBalances::class)($bookkeeper, $this->company))->toThrow(AuthorizationException::class);

    Livewire::test(StaleOpeningBalancesNotice::class)
        ->assertSee('stämmer inte längre')
        ->assertDontSee('Uppdatera ingående balanser')
        ->assertSee('Be någon som får hantera räkenskapsår att uppdatera dem.');
});

it('never shows or updates another company\'s opening balances', function () {
    Bookkeeping::post($this->owner, $this->company, 50_000, 1930, 3001, '2026-12-30');
    [$otherOwner, $other] = Bookkeeping::company();
    CurrentCompany::set($other);

    expect(StaleOpeningBalances::compute($other))->toBe([]);

    $this->actingAs($otherOwner);
    Livewire::test(StaleOpeningBalancesNotice::class)->assertDontSee('stämmer inte längre');

    expect(fn () => app(UpdateOpeningBalances::class)($otherOwner, $this->company))->toThrow(AuthorizationException::class)
        ->and(CurrentCompany::run($this->company, fn () => ibOf($this->next)))->toBe([1930 => 5_200_000, 2081 => -5_000_000, 2099 => -200_000])
        ->and(Voucher::query()->count())->toBe(0);
});
