<?php

declare(strict_types=1);

use App\Actions\Accruals\Accruals;
use App\Domain\Accruals\AccrualPlan;
use App\Livewire\Accounting\AccrualsPage;
use App\Models\Accrual;
use App\Models\AccrualPosting;
use App\Models\Voucher;
use App\Support\Tenancy\CurrentCompany;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Tests\Support\Bookkeeping;

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-04-10 10:00:00'));
    [$this->owner, $this->company] = Bookkeeping::company();
    CurrentCompany::set($this->company);
    $this->actingAs($this->owner);
    $this->accruals = app(Accruals::class);
});

/** @param array<string, mixed> $overrides */
function insurance(array $overrides = []): Accrual
{
    return test()->accruals->save(test()->owner, test()->company, [
        'name' => 'Företagsförsäkring', 'kind' => 'PREPAID_EXPENSE', 'total' => '12 000', 'balance_account' => '1790',
        'result_account' => '6310', 'start_month' => '2026-01', 'months' => '12', 'move_from_result' => false, 'cost_center' => '', 'notes' => '',
        ...$overrides,
    ]);
}

/** @return array<int, int> signed öre per account number */
function accrualSums(Voucher $voucher): array
{
    return $voucher->load('rows.account')->rows->groupBy(fn ($row) => $row->account->number)
        ->map(fn ($rows) => $rows->sum(fn ($row) => $row->debit_ore->ore - $row->credit_ore->ore))->all();
}

it('spreads an amount so the months add up exactly', function () {
    $plan = new AccrualPlan(1_000_000, 3, '2026-11');

    expect(array_column($plan->schedule(), 'amount'))->toBe([333_333, 333_333, 333_334])
        ->and(array_column($plan->schedule(), 'month'))->toBe(['2026-11', '2026-12', '2027-01'])
        ->and($plan->releasedBy('2026-10'))->toBe(0)
        ->and($plan->releasedBy('2027-05'))->toBe(1_000_000);
});

it('releases a prepaid cost month by month and catches up a missed month', function () {
    $accrual = insurance();

    [$january] = $this->accruals->run($this->owner, $this->company, '2026-01');

    expect($january->voucher_date->toDateString())->toBe('2026-01-31')
        ->and(accrualSums($january))->toEqualCanonicalizing([6310 => 100_000, 1790 => -100_000])
        ->and($this->accruals->run($this->owner, $this->company, '2026-01'))->toBe([]);

    [$march] = $this->accruals->run($this->owner, $this->company, '2026-03');

    expect(accrualSums($march))->toEqualCanonicalizing([6310 => 200_000, 1790 => -200_000])
        ->and($accrual->fresh()->load('postings')->remainingOre())->toBe(900_000);
});

it('first moves a cost booked on the result account to the balance account', function () {
    insurance(['move_from_result' => true]);

    [$voucher] = $this->accruals->run($this->owner, $this->company, '2026-01');

    // 12 000 kr out of 6310, 1 000 kr back: the cost of January is 1 000 kr.
    expect(accrualSums($voucher))->toEqualCanonicalizing([6310 => -1_100_000, 1790 => 1_100_000]);
});

it('spreads prepaid income the other way', function () {
    insurance(['name' => 'Årsabonnemang', 'kind' => 'PREPAID_INCOME', 'balance_account' => '2990', 'result_account' => '3001']);

    [$voucher] = $this->accruals->run($this->owner, $this->company, '2026-01');

    expect(accrualSums($voucher))->toEqualCanonicalizing([2990 => 100_000, 3001 => -100_000]);
});

it('books an accrued cost at the month end and reverses it on the first of the next month', function () {
    $electricity = insurance(['name' => 'El mars', 'kind' => 'ACCRUED_EXPENSE', 'total' => '2 500', 'balance_account' => '2990', 'result_account' => '5010', 'start_month' => '2026-03', 'months' => '12']);

    expect($electricity->months)->toBe(1)
        ->and($this->accruals->run($this->owner, $this->company, '2026-02'))->toBe([]);

    [$march] = $this->accruals->run($this->owner, $this->company, '2026-03');
    [$reversal] = $this->accruals->run($this->owner, $this->company, '2026-04');

    expect($march->voucher_date->toDateString())->toBe('2026-03-31')
        ->and(accrualSums($march))->toEqualCanonicalizing([5010 => 250_000, 2990 => -250_000])
        ->and($reversal->voucher_date->toDateString())->toBe('2026-04-01')
        ->and(accrualSums($reversal))->toEqualCanonicalizing([5010 => -250_000, 2990 => 250_000])
        ->and($electricity->fresh()->load('postings')->isDone())->toBeTrue()
        ->and($this->accruals->run($this->owner, $this->company, '2026-05'))->toBe([]);
});

it('books reversals and new items of the same month as two vouchers', function () {
    insurance(['name' => 'Arbete mars', 'kind' => 'ACCRUED_INCOME', 'total' => '8 000', 'balance_account' => '1790', 'result_account' => '3001', 'start_month' => '2026-03']);
    insurance(['name' => 'Arbete april', 'kind' => 'ACCRUED_INCOME', 'total' => '5 000', 'balance_account' => '1790', 'result_account' => '3001', 'start_month' => '2026-04']);
    $this->accruals->run($this->owner, $this->company, '2026-03');

    $vouchers = $this->accruals->run($this->owner, $this->company, '2026-04');

    expect($vouchers)->toHaveCount(2)
        ->and(accrualSums($vouchers[0]))->toEqualCanonicalizing([1790 => -800_000, 3001 => 800_000])
        ->and(accrualSums($vouchers[1]))->toEqualCanonicalizing([1790 => 500_000, 3001 => -500_000]);
});

it('keeps the plan once a month is booked, and the booked steps themselves', function () {
    $accrual = insurance();
    $this->accruals->run($this->owner, $this->company, '2026-01');

    $saved = $this->accruals->save($this->owner, $this->company, ['name' => 'Försäkring If', 'kind' => 'PREPAID_EXPENSE', 'total' => '99 000', 'balance_account' => '1790', 'result_account' => '6310', 'start_month' => '2026-01', 'months' => '3'], $accrual);

    expect($saved->name)->toBe('Försäkring If')
        ->and($saved->fresh()->total_ore->ore)->toBe(1_200_000)
        ->and(fn () => $this->accruals->delete($this->owner, $accrual))->toThrow(ValidationException::class)
        ->and(fn () => AccrualPosting::query()->first()->delete())->toThrow(LogicException::class)
        ->and(fn () => DB::table('accrual_postings')->update(['amount_ore' => 1]))->toThrow(QueryException::class);
});

it('validates the accounts and the amount', function () {
    expect(fn () => insurance(['result_account' => '1930']))->toThrow(ValidationException::class)
        ->and(fn () => insurance(['total' => '0']))->toThrow(ValidationException::class);
});

it('is for those who close the year, and lists accruals without a query per row', function () {
    $bookkeeper = Bookkeeping::member($this->company, 'bookkeeper');
    insurance();
    insurance(['name' => 'Hyra', 'result_account' => '5010', 'balance_account' => '1710']);

    expect(fn () => $this->accruals->run($bookkeeper, $this->company, '2026-01'))->toThrow(AuthorizationException::class);

    $queries = function (): int {
        DB::flushQueryLog();
        DB::enableQueryLog();
        Livewire::test(AccrualsPage::class);

        return count(DB::getQueryLog());
    };
    $queries();
    $before = $queries();
    insurance(['name' => 'Leasing']);
    insurance(['name' => 'El mars', 'kind' => 'ACCRUED_EXPENSE', 'balance_account' => '2990', 'start_month' => '2026-03']);
    expect($queries())->toBe($before);

    Livewire::test(AccrualsPage::class)
        ->assertSee('Företagsförsäkring')
        ->set('month', '2026-02')
        ->call('book')
        ->assertDispatched('toast')
        ->call('create')
        ->set('form.kind', 'ACCRUED_EXPENSE')
        ->assertSet('form.balance_account', '2990')
        ->assertSet('form.months', '1');

    $this->actingAs($bookkeeper);
    $this->get(route('accounting.accruals'))->assertForbidden();
});
