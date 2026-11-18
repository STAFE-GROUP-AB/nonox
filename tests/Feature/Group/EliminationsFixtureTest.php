<?php

declare(strict_types=1);

use App\Actions\Group\ConsolidateGroup;
use App\Actions\Group\RecordGroupEntry;
use App\Domain\Group\EliminationSuggestions;
use App\Domain\Group\GroupAccess;
use App\Models\GroupEntry;
use App\Models\IntercompanyRowTag;
use App\Models\Voucher;
use App\Support\Tenancy\CurrentCompany;
use Carbon\CarbonImmutable;
use Tests\Support\S2Fixture;

/*
 * F-S2-a (frozen 2026-11-07) against the eliminations of S2-1.5 (ADR-060
 * 3.2; ADR-081 E25): with the intercompany rows tagged as the fixture marks
 * them, every suggestion matches, accepting all of them nets to zero, and
 * the consolidated figures are the fixture's stage 1 group statements.
 */
beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2027-02-15 10:00:00'));
    $this->fixture = S2Fixture::load('f-s2-a');

    foreach ($this->fixture->companies as $key => $company) {
        e25FixtureTag($this->fixture, $key);
    }
});

/** The kind of an intra-group row by its account, as the fixture's account marks and its loan say. */
function e25FixtureKind(int $account): string
{
    return match ($account) {
        1320 => 'LOAN_RECEIVABLE',
        2360 => 'LOAN_PAYABLE',
        1560, 1660 => 'RECEIVABLE',
        2460, 2860 => 'PAYABLE',
        3011, 3021 => 'SALES',
        4011, 6591 => 'PURCHASES',
        8250 => 'INTEREST_INCOME',
        8410 => 'INTEREST_EXPENSE',
        8820 => 'GROUP_CONTRIBUTION_RECEIVED',
        8830 => 'GROUP_CONTRIBUTION_GIVEN',
    };
}

/** Tags every fixture row that names a counterparty, on the posted voucher in the same order. */
function e25FixtureTag(S2Fixture $fixture, string $key): void
{
    $company = $fixture->companies[$key];
    $source = json_decode((string) file_get_contents(base_path("tests/Fixtures/s2/f-s2-a/companies/{$key}/vouchers.json")), true, flags: JSON_THROW_ON_ERROR)['vouchers'];

    CurrentCompany::run($company, function () use ($fixture, $source): void {
        $vouchers = Voucher::query()->with(['rows' => fn ($query) => $query->orderBy('id')])->orderBy('id')->get()->values();

        foreach ($source as $index => $voucher) {
            foreach ($voucher['rows'] as $rowIndex => $row) {
                if (($row['counterparty'] ?? null) === null) {
                    continue;
                }

                IntercompanyRowTag::create([
                    'voucher_row_id' => $vouchers[$index]->rows[$rowIndex]->id,
                    'counterparty_org_number' => $fixture->companies[$row['counterparty']]->org_number,
                    'kind' => e25FixtureKind((int) $row['account']),
                    'source' => 'MANUAL',
                ]);
            }
        }
    });
}

/**
 * Accepts every matched suggestion and enters E10 (shares against equity) by hand: with two subsidiaries the
 * parent's one share balance cannot be split per member, so that suggestion needs an acquisition analysis.
 *
 * @return list<array<string, mixed>> the suggestions
 */
function e25FixtureEliminate(S2Fixture $fixture): array
{
    $suggestions = app(EliminationSuggestions::class)->compute(GroupAccess::for($fixture->admin->fresh()), $fixture->group->fresh(), CarbonImmutable::parse('2026-01-01'), CarbonImmutable::parse('2026-12-31'))['suggestions'];

    foreach ($suggestions as $suggestion) {
        if ($suggestion['status'] === EliminationSuggestions::MATCHED) {
            app(RecordGroupEntry::class)->accept($fixture->admin->fresh(), $fixture->group->id, [
                'from' => '2026-01-01', 'to' => '2026-12-31', 'key' => $suggestion['key'], 'fingerprint' => $suggestion['fingerprint'],
            ]);
        }
    }

    $e10 = $fixture->expected['eliminations']['2026-12-31'][9];
    app(RecordGroupEntry::class)->manual($fixture->admin->fresh(), $fixture->group->id, [
        'from' => '2026-01-01', 'to' => '2026-12-31', 'type' => 'SHARES_EQUITY', 'reason' => 'Kapitaleliminering vid bildandet',
        'rows' => array_map(fn (array $row): array => [
            'company' => $fixture->companies[$row['company']]->id,
            'account' => (string) $row['account'],
            'debit' => $row['debit_ore'] > 0 ? number_format($row['debit_ore'] / 100, 2, ',', '') : '',
            'credit' => $row['credit_ore'] > 0 ? number_format($row['credit_ore'] / 100, 2, ',', '') : '',
        ], $e10['rows']),
    ]);

    return $suggestions;
}

it('matches every intra-group item of the fixture and nets the eliminations to zero', function () {
    $suggestions = e25FixtureEliminate($this->fixture);

    // E1, E2 (one per subsidiary), E3, E4, E5, E6, E7 with E8 (both Produktion's debts to the parent) and E9 match;
    // E10 needs an acquisition analysis and is entered by hand.
    expect($suggestions)->toHaveCount(10)
        ->and(collect($suggestions)->countBy('status')->all())->toBe([EliminationSuggestions::MATCHED => 9, EliminationSuggestions::NEEDS_ACQUISITION_ANALYSIS => 1])
        ->and(collect($suggestions)->firstWhere('status', EliminationSuggestions::NEEDS_ACQUISITION_ANALYSIS)['difference'])->toBe(0);

    $entries = GroupEntry::query()->with('rows')->get();
    $eliminations = $this->fixture->expected['eliminations'];

    expect($entries->flatMap->rows->sum('debit_ore'))->toBe($entries->flatMap->rows->sum('credit_ore'))
        ->and($entries->flatMap->rows->sum('debit_ore'))->toBe($eliminations['total_eliminated_income_statement'] + $eliminations['total_eliminated_balance_sheet'])
        ->and($entries->flatMap->rows->filter(fn ($row): bool => $row->group_account_number >= 3000)->sum('debit_ore'))->toBe($eliminations['total_eliminated_income_statement']);
});

it('gives the fixture\'s stage 1 group statements once every suggestion is accepted', function () {
    e25FixtureEliminate($this->fixture);

    $run = app(ConsolidateGroup::class)($this->fixture->admin->fresh(), $this->fixture->group->id, ['from' => '2026-01-01', 'to' => '2026-12-31']);
    $stage1 = $this->fixture->expected['consolidated_stage1'];
    $income = $stage1['income_statement_2026'];
    $balance = $stage1['balance_sheet']['2026-12-31'];
    $totals = array_column($run->output['income']['groups'], 'total', 'key');
    $subtotals = array_column($run->output['income']['groups'], 'subtotal', 'key');
    $accounts = collect($run->output['balance']['assets']['groups'])->flatMap(fn (array $group): array => $group['rows'])->pluck('amount', 'number');
    // As in E24's fixture test: the internal report keeps Försäljning's debit tax account (2512) on its BAS side.
    $taxReceivable = $balance['tillgangar']['aktuella_skattefordringar'];

    expect($totals['revenue'])->toBe($income['nettoomsattning'])
        ->and($totals['goods'])->toBe($income['handelsvaror'] + $income['ravaror_och_fornodenheter'])
        ->and($totals['external'])->toBe($income['ovriga_externa_kostnader'])
        ->and($totals['personnel'])->toBe($income['personalkostnader'])
        ->and($subtotals['other_operating'])->toBe($income['rorelseresultat'])
        ->and($subtotals['financial'])->toBe($income['resultat_efter_finansiella_poster'])
        ->and($subtotals['appropriations'])->toBe($income['resultat_fore_skatt'])
        ->and($run->output['income']['result'])->toBe($income['arets_resultat'])
        ->and($accounts->only([1310, 1320, 1560, 1660])->filter()->all())->toBe([])
        ->and($run->output['balance']['assets']['total'])->toBe($balance['tillgangar']['summa_tillgangar'] - $taxReceivable)
        ->and($run->output['balance']['equity']['total'])->toBe($balance['eget_kapital_och_skulder']['summa_eget_kapital_och_skulder'] - $taxReceivable)
        ->and($run->output['result_outside_period'])->toBe(0)
        ->and($run->inputs['group_entries'])->toHaveCount(10);
});
