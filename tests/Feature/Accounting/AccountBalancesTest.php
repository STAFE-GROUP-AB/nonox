<?php

declare(strict_types=1);

use App\Actions\Accounting\PostVoucher;
use App\Actions\Accounting\SaveDraftVoucher;
use App\Domain\Accounting\AccountBalances;
use App\Domain\Accounting\AccountType;
use App\Livewire\Accounting\VoucherEditor;
use App\Models\Account;
use App\Models\Company;
use App\Models\CostCenter;
use App\Models\FiscalYear;
use App\Models\OpeningBalance;
use App\Models\Project;
use App\Models\User;
use App\Models\Voucher;
use App\Support\Tenancy\CurrentCompany;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Tests\Support\Bookkeeping;

/*
 * Kontosaldo while bookkeeping and on the voucher, the column order the
 * customers asked for (Konto, Kostnadsställe, Projekt, Radtext, Debet,
 * Kredit, Kontosaldo) and accounts that lead to the Huvudbok.
 */

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-06-15 10:00:00'));
    [$this->owner, $this->company] = Bookkeeping::company();
    CurrentCompany::set($this->company);
    $this->actingAs($this->owner);
    $this->year = FiscalYear::query()->whereDate('start_date', '2026-01-01')->sole();
});

/** @param  list<array<string, mixed>>  $rows */
function balanceVoucher(User $user, Company $company, string $date, array $rows, bool $post = true): Voucher
{
    $draft = app(SaveDraftVoucher::class)($user, $company, ['voucher_date' => $date, 'description' => 'Händelse', 'rows' => $rows]);

    return $post ? app(PostVoucher::class)($user, $draft) : $draft;
}

function balanceOpening(FiscalYear $year, int $number, int $ore): void
{
    OpeningBalance::create(['fiscal_year_id' => $year->id, 'account_id' => Account::query()->where('number', $number)->value('id'), 'amount_ore' => $ore]);
}

/** Amounts are written with a non-breaking space between thousands. */
function nbsp(string $amount): string
{
    return str_replace(' ', "\u{a0}", $amount);
}

/** The positions of the texts in the HTML, which must be increasing. */
function inOrder(string $html, array $texts): bool
{
    $positions = array_map(fn (string $text) => mb_strpos($html, $text), $texts);

    return ! in_array(false, $positions, true) && $positions === array_values(collect($positions)->sort()->all());
}

/** A bank account with 10 000 kr in, a sale of 1 000 kr in March and one of 500 kr in May. */
function bankYear(User $owner, Company $company, FiscalYear $year): array
{
    balanceOpening($year, 1930, 1_000_000);
    balanceOpening($year, 2081, -1_000_000);

    return [
        balanceVoucher($owner, $company, '2026-03-01', [['account' => 1930, 'debit_ore' => 100_000], ['account' => 3001, 'credit_ore' => 100_000]]),
        balanceVoucher($owner, $company, '2026-05-01', [['account' => 1930, 'debit_ore' => 50_000], ['account' => 3001, 'credit_ore' => 50_000]]),
    ];
}

it('adds the opening balance and the posted vouchers up to and including the date, never drafts', function () {
    bankYear($this->owner, $this->company, $this->year);
    balanceVoucher($this->owner, $this->company, '2026-04-01', [['account' => 1930, 'debit_ore' => 999_900], ['account' => 3001, 'credit_ore' => 999_900]], post: false);

    expect(AccountBalances::atDate($this->year->id, CarbonImmutable::parse('2026-02-28'))->all())->toEqualCanonicalizing([1930 => 1_000_000, 2081 => -1_000_000])
        ->and(AccountBalances::atDate($this->year->id, CarbonImmutable::parse('2026-03-01'))[1930])->toBe(1_100_000)
        ->and(AccountBalances::atDate($this->year->id, CarbonImmutable::parse('2026-04-30'))[3001])->toBe(-100_000)
        ->and(AccountBalances::atDate($this->year->id, CarbonImmutable::parse('2026-05-01'))[1930])->toBe(1_150_000);
});

it('shows a balance on the account\'s normal side', function () {
    expect(AccountBalances::forDisplay(1_000, AccountType::Asset))->toBe(1_000)
        ->and(AccountBalances::forDisplay(1_000, AccountType::Expense))->toBe(1_000)
        ->and(AccountBalances::forDisplay(-1_000, AccountType::LiabilityEquity))->toBe(1_000)
        ->and(AccountBalances::forDisplay(-1_000, AccountType::Revenue))->toBe(1_000)
        ->and(AccountBalances::forDisplay(-1_000, AccountType::Asset))->toBe(-1_000);
});

it('gives the balance after each row in the order of the general ledger', function () {
    [$march, $may] = bankYear($this->owner, $this->company, $this->year);
    $split = balanceVoucher($this->owner, $this->company, '2026-05-01', [
        ['account' => 6110, 'debit_ore' => 30_000],
        ['account' => 1930, 'credit_ore' => 10_000],
        ['account' => 1930, 'credit_ore' => 20_000],
    ]);

    $balances = AccountBalances::afterRows($this->year->id, $split->rows);

    expect($split->rows->map(fn ($row) => $balances[$row->id])->all())->toBe([30_000, 1_140_000, 1_120_000])
        ->and(AccountBalances::afterRows($this->year->id, $march->rows))->toBe([$march->rows[0]->id => 1_100_000, $march->rows[1]->id => -100_000])
        ->and(AccountBalances::afterRows($this->year->id, $may->rows)[$may->rows[0]->id])->toBe(1_150_000);
});

it('shows the voucher with Kontosaldo up to and including it, and links each account to the Huvudbok', function () {
    [$march] = bankYear($this->owner, $this->company, $this->year);

    $this->get(route('accounting.vouchers.show', $march))
        ->assertOk()
        ->assertSeeInOrder(['Konto', 'Radtext', 'Debet', 'Kredit', 'Kontosaldo'])
        // 1930: 10 000 + 1 000; 3001 is revenue, shown positive although it is a credit.
        ->assertSeeInOrder(['1930', nbsp('1 000,00'), nbsp('11 000,00'), '3001', nbsp('1 000,00'), nbsp('1 000,00')], false)
        ->assertDontSee(nbsp('11 500,00'), false)
        ->assertSee(e(route('accounting.reports', ['rapport' => 'huvudbok', 'ar' => $this->year->id, 'konto' => 1930])), false);
});

it('puts Kostnadsställe and Projekt after Konto in the editor, the voucher and the Verifikationslista', function () {
    CostCenter::create(['code' => '10', 'name' => 'Malmö']);
    Project::create(['code' => 'P1', 'name' => 'Kök']);
    $voucher = balanceVoucher($this->owner, $this->company, '2026-03-01', [
        ['account' => 6110, 'debit_ore' => 100_000, 'cost_center' => '10', 'project' => 'P1', 'description' => 'Pennor'],
        ['account' => 1930, 'credit_ore' => 100_000],
    ]);
    $order = ['Konto', 'Kostnadsställe', 'Projekt', 'Radtext', 'Debet', 'Kredit', 'Kontosaldo'];

    $editor = $this->get(route('accounting.vouchers.create'))->assertOk()->getContent();
    $head = mb_substr($editor, (int) mb_strpos($editor, '<thead class="hidden md:table-header-group">'));
    expect(inOrder($head, array_map(fn (string $text) => ">{$text}", $order)))->toBeTrue()
        // The fields follow the columns, so Tab and Enter go Konto, Kostnadsställe, Projekt, Radtext, Debet, Kredit.
        ->and(inOrder($editor, ["'row-account-'", "'row-cost-center-'", "'row-project-'", "'row-description-'", "'row-debit-'", "'row-credit-'", 'data-account-balance']))->toBeTrue();

    $show = $this->get(route('accounting.vouchers.show', $voucher))->assertOk()->getContent();
    expect(inOrder($show, array_map(fn (string $text) => ">{$text}</th>", $order)))->toBeTrue()
        ->and(inOrder($show, ['6110', '10 Malmö', 'P1 Kök', 'Pennor', nbsp('1 000,00')]))->toBeTrue();

    $list = $this->get(route('accounting.reports', ['rapport' => 'verifikationer']))->assertOk()->getContent();
    $list = mb_substr($list, (int) mb_strpos($list, '<thead class="sr-only">'));
    expect(inOrder($list, array_map(fn (string $text) => ">{$text}</th>", $order)))->toBeTrue()
        ->and(inOrder($list, ['6110', '10 Malmö', 'P1 Kök', 'Pennor', nbsp('1 000,00')]))->toBeTrue();
});

it('leaves out Kostnadsställe and Projekt when the company does not use them', function () {
    [$march] = bankYear($this->owner, $this->company, $this->year);

    $this->get(route('accounting.vouchers.show', $march))->assertOk()->assertDontSee('Kostnadsställe</th>', false)->assertDontSee('Projekt</th>', false);
    $this->get(route('accounting.vouchers.create'))->assertOk()->assertDontSee("'row-cost-center-'", false);
});

it('computes Kontosaldo for a voucher in one query, however many rows it has', function () {
    bankYear($this->owner, $this->company, $this->year);
    $small = balanceVoucher($this->owner, $this->company, '2026-06-01', [['account' => 6110, 'debit_ore' => 1_000], ['account' => 1930, 'credit_ore' => 1_000]]);
    $large = balanceVoucher($this->owner, $this->company, '2026-06-01', [
        ...array_map(fn (int $number) => ['account' => $number, 'debit_ore' => 1_000], [5010, 5410, 6110, 6211, 6230, 6570, 6991, 7690]),
        ['account' => 1930, 'credit_ore' => 8_000],
    ]);

    $queries = function (Voucher $voucher): array {
        DB::flushQueryLog();
        DB::enableQueryLog();
        $this->get(route('accounting.vouchers.show', $voucher))->assertOk()->assertSee('Kontosaldo');
        $log = array_column(DB::getQueryLog(), 'query');
        DB::disableQueryLog();

        return $log;
    };

    // Warm both: the first visit adds the voucher to the recently opened items.
    $queries($small);
    $queries($large);
    $smallLog = $queries($small);
    $largeLog = $queries($large);

    expect(count($largeLog))->toBe(count($smallLog))
        ->and(count(array_filter($largeLog, fn (string $sql) => str_contains($sql, 'OVER ('))))->toBe(1);
});

it('gives the editor the posted balances at the voucher date, and new ones when the date changes', function () {
    bankYear($this->owner, $this->company, $this->year);
    balanceVoucher($this->owner, $this->company, '2026-04-01', [['account' => 1930, 'debit_ore' => 999_900], ['account' => 3001, 'credit_ore' => 999_900]], post: false);

    $component = Livewire::test(VoucherEditor::class)->set('voucherDate', '2026-04-01');
    expect($component->get('balances')[1930])->toBe(1_100_000)
        ->and($component->get('balances')[3001])->toBe(-100_000);

    $component->set('voucherDate', '2026-05-01');
    expect($component->get('balances')[1930])->toBe(1_150_000);

    // Outside every fiscal year there is no balance to show.
    $component->set('voucherDate', '2031-01-01');
    expect($component->get('balances'))->toBeNull();
});

it('tells the editor which side each account is normally on', function () {
    $signs = Livewire::test(VoucherEditor::class)->viewData('accountSigns');

    expect([$signs[1930], $signs[2440], $signs[3001], $signs[6110]])->toBe([1, -1, -1, 1]);
});

it('links every account in the reports to its Huvudbok with the same year, period and cost centre', function (string $report, int $account) {
    CostCenter::create(['code' => '10', 'name' => 'Malmö']);
    balanceVoucher($this->owner, $this->company, '2026-03-01', [['account' => 6110, 'debit_ore' => 100_000, 'cost_center' => '10'], ['account' => 1930, 'credit_ore' => 100_000, 'cost_center' => '10']]);

    $filters = ['ar' => $this->year->id, 'fran' => '2026-02-01', 'till' => '2026-04-30', 'kst' => '10'];
    $link = e(route('accounting.reports', ['rapport' => 'huvudbok', ...array_map('strval', $filters), 'konto' => (string) $account]));

    $this->get(route('accounting.reports', ['rapport' => $report, ...$filters, 'jamfor' => '1']))
        ->assertOk()
        ->assertSee($link, false);
})->with([
    'saldobalans' => ['saldobalans', 6110],
    'resultat' => ['resultat', 6110],
    'balans' => ['balans', 1930],
    'huvudbok' => ['huvudbok', 6110],
    'verifikationer' => ['verifikationer', 6110],
]);

it('shows the Huvudbok of one account as Kontoanalys', function () {
    bankYear($this->owner, $this->company, $this->year);

    $this->get(route('accounting.reports', ['rapport' => 'huvudbok', 'konto' => '1930']))
        ->assertOk()
        ->assertSee('Kontoanalys för konto 1930')
        ->assertSee('Visa alla konton')
        ->assertSee('A1');
});
