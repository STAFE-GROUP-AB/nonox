<?php

declare(strict_types=1);

use App\Actions\Accounting\CarryForwardBalances;
use App\Domain\Accounting\Reports\LedgerQuery;
use App\Domain\Accounting\Reports\LedgerReports;
use App\Livewire\Accounting\FiscalYears;
use App\Models\Account;
use App\Models\AuditLog;
use App\Models\FiscalYear;
use App\Models\OpeningBalance;
use App\Support\Tenancy\CurrentCompany;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Tests\Support\Bookkeeping;

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2027-01-15 10:00:00'));
    [$this->owner, $this->company] = Bookkeeping::company();
    CurrentCompany::set($this->company);
    $this->actingAs($this->owner);
    $this->year = FiscalYear::query()->whereDate('start_date', '2026-01-01')->sole();

    OpeningBalance::create(['fiscal_year_id' => $this->year->id, 'account_id' => Account::query()->where('number', 1930)->value('id'), 'amount_ore' => 5_000_000]);
    OpeningBalance::create(['fiscal_year_id' => $this->year->id, 'account_id' => Account::query()->where('number', 2081)->value('id'), 'amount_ore' => -5_000_000]);
    Bookkeeping::post($this->owner, $this->company, 300_000, 1930, 3001, '2026-04-01');
    Bookkeeping::post($this->owner, $this->company, 100_000, 6110, 1930, '2026-05-01');
});

function openingOf(FiscalYear $year): array
{
    return OpeningBalance::query()->where('opening_balances.fiscal_year_id', $year->id)
        ->join('accounts', 'accounts.id', '=', 'opening_balances.account_id')
        ->orderBy('accounts.number')
        ->pluck('opening_balances.amount_ore', 'accounts.number')
        ->map(fn ($amount) => $amount->ore)
        ->all();
}

it('carries the closing balances into a new year, with the result on 2099', function () {
    $next = app(CarryForwardBalances::class)($this->owner, $this->year);

    expect($next->label())->toBe('2027')
        ->and(openingOf($next))->toBe([1930 => 5_200_000, 2081 => -5_000_000, 2099 => -200_000])
        ->and(array_sum(openingOf($next)))->toBe(0)
        ->and(AuditLog::forCompany($this->company)->where('action', 'fiscal_year.balances_carried_forward')->sole()->payload)
        ->toMatchArray(['from' => '2026', 'to' => '2027', 'accounts' => 3, 'replaced' => 0, 'unbooked_result_ore' => -200_000]);

    $sheet = app(LedgerReports::class)->balanceSheet(LedgerQuery::forYear($next));
    expect($sheet['unbooked_result']['opening'])->toBe(0)
        ->and($sheet['sides']['assets']['opening'])->toBe($sheet['sides']['equity']['opening']);
});

it('puts a sole trader\'s result on 2019', function () {
    $this->company->forceFill(['legal_form' => 'EF'])->save();

    $next = app(CarryForwardBalances::class)($this->owner, $this->year);

    expect(openingOf($next))->toHaveKey(2019, -200_000);
});

it('can be run again after a late voucher', function () {
    $next = app(CarryForwardBalances::class)($this->owner, $this->year);
    Bookkeeping::post($this->owner, $this->company, 50_000, 1930, 3001, '2026-12-30');

    app(CarryForwardBalances::class)($this->owner, $this->year);

    expect(openingOf($next))->toBe([1930 => 5_250_000, 2081 => -5_000_000, 2099 => -250_000]);
});

it('refuses a closed next year and an empty year', function () {
    $next = FiscalYear::create(['start_date' => '2027-01-01', 'end_date' => '2027-12-31']);
    $next->forceFill(['status' => 'CLOSED'])->save();

    expect(fn () => app(CarryForwardBalances::class)($this->owner, $this->year))->toThrow(ValidationException::class, 'är stängt');

    $empty = FiscalYear::create(['start_date' => '2025-01-01', 'end_date' => '2025-12-31']);
    expect(fn () => app(CarryForwardBalances::class)($this->owner, $empty))->toThrow(ValidationException::class, 'inget bokfört');
});

it('carries forward from the fiscal years page with a toast', function () {
    Livewire::test(FiscalYears::class)
        ->assertSee('För över balanser till 2027')
        ->call('carryForward', $this->year->id)
        ->assertDispatched('toast', message: 'Utgående balanser för 2026 är ingående balanser för 2027.');
});

it('lets only those who manage fiscal years carry forward', function () {
    $this->actingAs(Bookkeeping::member($this->company, 'bookkeeper'));

    expect(fn () => app(CarryForwardBalances::class)(auth()->user(), $this->year))->toThrow(AuthorizationException::class);
});
