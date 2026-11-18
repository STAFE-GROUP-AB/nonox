<?php

declare(strict_types=1);

use App\Actions\Group\ConsolidateGroup;
use App\Models\CorporateGroupRun;
use Carbon\CarbonImmutable;
use Tests\Support\S2Fixture;

/*
 * F-S2-a (frozen 2026-11-07) against the consolidation engine of S2-1.4
 * (ADR-060 3.2; ADR-081 E24): with no eliminations accepted yet, the
 * consolidated figures are the gross sums of the three companies' own
 * statements in expected.json. Eliminations (S2-1.5, E25) come next.
 */
beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2027-02-15 10:00:00'));
    $this->fixture = S2Fixture::load('f-s2-a');
    $this->run = app(ConsolidateGroup::class)($this->fixture->admin->fresh(), $this->fixture->group->id, ['from' => '2026-01-01', 'to' => '2026-12-31']);
});

/** The sum over the fixture's companies of one line of their own statements. */
function e24FixtureSum(S2Fixture $fixture, string $statement, string $line, ?string $date = null, ?string $side = null): int
{
    return (int) array_sum(array_map(function (array $company) use ($statement, $line, $date, $side): int {
        $values = $company[$statement];
        $values = $date === null ? $values : $values[$date][$side];

        return (int) $values[$line];
    }, $fixture->expected['companies']));
}

/** @return array<string, int> group key => total of the consolidated income statement */
function e24IncomeTotals(CorporateGroupRun $run): array
{
    return array_column($run->output['income']['groups'], 'total', 'key');
}

it('gives the gross sum of the companies\' income statements', function () {
    $totals = e24IncomeTotals($this->run);
    $sum = fn (string ...$lines): int => array_sum(array_map(fn (string $line): int => e24FixtureSum($this->fixture, 'income_statement_2026', $line), $lines));

    expect($totals['revenue'])->toBe($sum('nettoomsattning'))
        ->and($totals['revenue'])->toBe(6_240_000_000)
        ->and($totals['goods'])->toBe($sum('handelsvaror', 'ravaror_och_fornodenheter'))
        ->and($totals['external'])->toBe($sum('ovriga_externa_kostnader'))
        ->and($totals['personnel'])->toBe($sum('personalkostnader'))
        ->and($totals['depreciation'])->toBe($sum('avskrivningar_materiella_anlaggningstillgangar'))
        ->and(collect($this->run->output['income']['groups'])->firstWhere('key', 'other_operating')['subtotal'])->toBe($sum('rorelseresultat'))
        ->and(collect($this->run->output['income']['groups'])->firstWhere('key', 'financial')['subtotal'])->toBe($sum('resultat_efter_finansiella_poster'))
        ->and(collect($this->run->output['income']['groups'])->firstWhere('key', 'appropriations')['subtotal'])->toBe($sum('resultat_fore_skatt'))
        ->and($totals['tax'])->toBe($sum('skatt_pa_arets_resultat'))
        ->and($this->run->output['income']['result'])->toBe($sum('arets_resultat'))
        ->and($this->run->output['income']['result'])->toBe(364_659_876);
});

it('gives the gross sum of the companies\' balance sheets, which balances', function () {
    $assets = e24FixtureSum($this->fixture, 'balance_sheet', 'summa_tillgangar', '2026-12-31', 'tillgangar');
    $equity = e24FixtureSum($this->fixture, 'balance_sheet', 'summa_eget_kapital_och_skulder', '2026-12-31', 'eget_kapital_och_skulder');
    // The fixture presents Försäljning's tax account in debit (2512) as a receivable among the assets, as an annual
    // report does; the internal report keeps every account on its BAS side, as the company's own Balansrapport does,
    // so that amount reduces the liabilities instead. Both sides move by the same amount.
    $taxReceivable = e24FixtureSum($this->fixture, 'balance_sheet', 'aktuella_skattefordringar', '2026-12-31', 'tillgangar');

    expect($taxReceivable)->toBe(6_934_048)
        ->and($this->run->output['balance']['assets']['total'])->toBe($assets - $taxReceivable)
        ->and($this->run->output['balance']['equity']['total'])->toBe($equity - $taxReceivable)
        ->and($assets)->toBe(3_045_923_548)
        ->and($this->run->output['result_outside_period'])->toBe(0)
        ->and($this->run->output['companies'])->toBe(['included' => 3, 'with_balance' => 3, 'cut' => 0]);
});

it('reproduces the fixture run from the same inputs', function () {
    $again = app(ConsolidateGroup::class)($this->fixture->admin->fresh(), $this->fixture->group->id, ['from' => '2026-01-01', 'to' => '2026-12-31']);

    expect($again->inputs_hash)->toBe($this->run->inputs_hash)
        ->and($again->output_hash)->toBe($this->run->output_hash)
        ->and(collect($this->run->inputs['companies'])->sum('posted_vouchers'))->toBe(17 + 28 + 21);
});
