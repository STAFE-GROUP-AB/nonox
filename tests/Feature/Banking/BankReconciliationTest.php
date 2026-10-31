<?php

declare(strict_types=1);

use App\Actions\Banking\BankReconciliations;
use App\Livewire\Accounting\BankReconciliationPage;
use App\Models\Account;
use App\Models\BankReconciliation;
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
    $this->travelTo(CarbonImmutable::parse('2026-05-10 10:00:00'));
    [$this->owner, $this->company] = Bookkeeping::company();
    CurrentCompany::set($this->company);
    $this->actingAs($this->owner);
    $this->reconciliations = app(BankReconciliations::class);
    $this->bank = Account::query()->where('number', 1930)->firstOrFail();

    // 1 000 kr in on 1 March, 200 kr out on 30 March that the bank draws on 2 April.
    Bookkeeping::post($this->owner, $this->company, 100_000, 1930, 3001, '2026-03-01');
    $this->payment = Bookkeeping::post($this->owner, $this->company, 20_000, 5010, 1930, '2026-03-30');
});

function bankRowOf(Voucher $voucher): int
{
    return $voucher->rows()->where('account_id', test()->bank->id)->value('id');
}

it('compares the statement with the ledger, minus what the bank has not drawn yet', function () {
    $prepared = $this->reconciliations->prepare($this->company, $this->bank, CarbonImmutable::parse('2026-03-31'));

    expect($prepared['ledger'])->toBe(80_000)
        ->and($prepared['rows'])->toHaveCount(2)
        ->and($prepared['previous'])->toBeNull();

    $march = $this->reconciliations->save($this->owner, $this->company, $this->bank, '2026-03-31', '1 000', [bankRowOf($this->payment)], null);

    expect($march->isBalanced())->toBeTrue()
        ->and($march->ledger_balance_ore->ore)->toBe(80_000)
        ->and($march->outstanding_ore->ore)->toBe(-20_000)
        ->and($march->outstanding_row_ids)->toBe([bankRowOf($this->payment)]);
});

it('carries outstanding rows to the next reconciliation until they reach the bank', function () {
    $this->reconciliations->save($this->owner, $this->company, $this->bank, '2026-03-31', '1 000', [bankRowOf($this->payment)], null);
    Bookkeeping::post($this->owner, $this->company, 30_000, 1930, 3001, '2026-04-10');

    $prepared = $this->reconciliations->prepare($this->company, $this->bank, CarbonImmutable::parse('2026-04-30'));

    // The April sale and the payment still outstanding from March.
    expect($prepared['rows']->pluck('id')->all())->toBe([bankRowOf($this->payment), $prepared['rows']->last()->id])
        ->and($prepared['previous']->statement_date->toDateString())->toBe('2026-03-31');

    $april = $this->reconciliations->save($this->owner, $this->company, $this->bank, '2026-04-30', '1 100', [], null);

    expect($april->isBalanced())->toBeTrue()
        ->and($this->reconciliations->prepare($this->company, $this->bank, CarbonImmutable::parse('2026-05-31'))['rows'])->toHaveCount(0);
});

it('needs an explanation for a difference, and keeps what was checked', function () {
    expect(fn () => $this->reconciliations->save($this->owner, $this->company, $this->bank, '2026-03-31', '790', [], ''))
        ->toThrow(ValidationException::class);

    $saved = $this->reconciliations->save($this->owner, $this->company, $this->bank, '2026-03-31', '790', [], 'Bankavgift 10 kr, bokförs i april.');

    expect($saved->difference_ore->ore)->toBe(-1_000)
        ->and(fn () => $saved->delete())->toThrow(LogicException::class)
        ->and(fn () => DB::table('bank_reconciliations')->update(['difference_ore' => 0]))->toThrow(QueryException::class);
});

it('reconciles only bank and cash accounts, in a fiscal year', function () {
    $sales = Account::query()->where('number', 3001)->firstOrFail();

    expect(fn () => $this->reconciliations->save($this->owner, $this->company, $sales, '2026-03-31', '0', [], null))->toThrow(ValidationException::class)
        ->and(fn () => $this->reconciliations->prepare($this->company, $this->bank, CarbonImmutable::parse('2019-12-31')))->toThrow(ValidationException::class);
});

it('is bookkeeping work, and the page reconciles with a click', function () {
    $auditor = Bookkeeping::member($this->company, 'auditor');
    $bookkeeper = Bookkeeping::member($this->company, 'bookkeeper');

    expect(fn () => $this->reconciliations->save($auditor, $this->company, $this->bank, '2026-03-31', '800', [], null))->toThrow(AuthorizationException::class);

    $this->actingAs($bookkeeper);
    Livewire::test(BankReconciliationPage::class)
        ->assertSet('account', 1930)
        ->assertSet('date', '2026-04-30')
        ->set('date', '2026-03-31')
        ->set('statement', '1 000')
        ->assertSee('Inte hos banken ännu')
        ->set('outstanding', [(string) bankRowOf($this->payment)])
        ->assertSee('Stämmer')
        ->call('save')
        ->assertDispatched('toast');

    expect(BankReconciliation::query()->sole()->reconciled_by)->toBe($bookkeeper->id);

    $this->actingAs($auditor);
    $this->get(route('accounting.bankReconciliation'))->assertForbidden();
});

it('shows the page without a query per row', function () {
    $queries = function (): int {
        DB::flushQueryLog();
        DB::enableQueryLog();
        Livewire::test(BankReconciliationPage::class)->set('date', '2026-03-31')->set('statement', '800');

        return count(DB::getQueryLog());
    };
    $this->reconciliations->save($this->owner, $this->company, $this->bank, '2026-02-28', '0', [], null);
    $queries();
    $before = $queries();

    foreach (range(2, 6) as $day) {
        Bookkeeping::post($this->owner, $this->company, 10_000, 1930, 3001, "2026-03-0{$day}");
    }

    expect($queries())->toBe($before);
});
