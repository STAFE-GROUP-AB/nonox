<?php

declare(strict_types=1);

use App\Modules\Saas\Domain\ContractStatus;
use App\Modules\Saas\Domain\ContractVersion;
use App\Modules\Saas\Domain\CustomerState;
use App\Modules\Saas\Domain\ExclusionReason;
use App\Modules\Saas\Domain\MonthResult;
use App\Modules\Saas\Domain\Movement;
use App\Modules\Saas\Domain\RecurringRevenue;
use App\Modules\Saas\Domain\RevenueLine;
use App\Modules\Saas\Domain\SourceType;

/*
 * The pure calculation of ADR-080 §4 against the rule sheet's fixtures
 * (docs/specs/rules/saas.md, F1–F5 and F7) and property tests. Every number
 * here is synthetic.
 */

/**
 * A version: customer, effective date, lines as [amount in kronor, months per period, currency].
 *
 * @param  list<array{0: int|float, 1?: int, 2?: string}>  $lines
 * @param  array<string, mixed>  $extra
 */
function saasVersion(int $id, string $contract, int $customer, string $from, array $lines, array $extra = []): ContractVersion
{
    $revenue = [];

    foreach ($lines as $index => $line) {
        $revenue[] = new RevenueLine("l{$index}", "Rad {$index}", $line[2] ?? 'SEK', (int) round($line[0] * 100), 'month', $line[1] ?? 1);
    }

    return new ContractVersion(
        $id,
        SourceType::RecurringInvoice,
        $contract,
        $customer,
        $from,
        $extra['status'] ?? ContractStatus::Active,
        $revenue,
        $extra['starts_on'] ?? null,
        $extra['cancel'] ?? null,
        $extra['excluded'] ?? null,
    );
}

function saasMonth(array $versions, string $month, array $rates = [], ?int $knownUntil = null): MonthResult
{
    $result = (new RecurringRevenue)->history($versions, $month, $rates, $knownUntil)->month($month);
    expect($result)->not->toBeNull();

    return $result;
}

it('normalises every interval to a month, per line and half up (F1)', function () {
    $versions = [
        saasVersion(1, 'a', 1, '2026-01-01', [[299, 1]]),
        saasVersion(2, 'b', 2, '2026-01-01', [[897, 3]]),
        saasVersion(3, 'c', 3, '2026-01-01', [[3000, 12]]),
        saasVersion(4, 'd', 4, '2026-01-01', [[1000, 12]]),
        saasVersion(5, 'e', 5, '2026-01-01', [[100, 3]]),
    ];

    $month = saasMonth($versions, '2026-01-01');

    expect(array_map(fn ($row) => $row->mrrOre, $month->customers))->toBe([1 => 29_900, 2 => 29_900, 3 => 25_000, 4 => 8_333, 5 => 3_333])
        ->and($month->mrrOre())->toBe(96_466)
        ->and($month->arrOre())->toBe(1_157_592);
});

it('normalises week, day and multi-year intervals and lines in a trial or with a time-limited discount', function () {
    expect((new RevenueLine('w', 'Vecka', 'SEK', 10_000, 'week'))->monthlyMinorAt('2026-01-31'))->toBe(43_333)
        ->and((new RevenueLine('d', 'Dag', 'SEK', 100, 'day'))->monthlyMinorAt('2026-01-31'))->toBe(3_042)
        ->and((new RevenueLine('y', 'Två år', 'SEK', 240_000, 'year', 2))->monthlyMinorAt('2026-01-31'))->toBe(10_000)
        ->and((new RevenueLine('t', 'Prova', 'SEK', 50_000, trialUntil: '2026-02-01'))->monthlyMinorAt('2026-01-31'))->toBe(0)
        ->and((new RevenueLine('t', 'Prova', 'SEK', 50_000, trialUntil: '2026-02-01'))->monthlyMinorAt('2026-02-28'))->toBe(50_000)
        ->and((new RevenueLine('r', 'Rabatt', 'SEK', 40_000, discountUntil: '2026-04-01', undiscountedMinor: 50_000))->monthlyMinorAt('2026-03-31'))->toBe(40_000)
        ->and((new RevenueLine('r', 'Rabatt', 'SEK', 40_000, discountUntil: '2026-04-01', undiscountedMinor: 50_000))->monthlyMinorAt('2026-04-30'))->toBe(50_000)
        ->and((new RevenueLine('u', 'Rörlig', 'SEK', 50_000, usage: true))->monthlyMinorAt('2026-04-30'))->toBe(0);
});

it('reconciles the bridge to the öre with every category and the currency effect (F2)', function () {
    $rates = ['2026-01-01' => ['EUR' => 11_200_000], '2026-02-01' => ['EUR' => 11_323_400]];
    $versions = [
        saasVersion(1, 'a', 1, '2026-01-01', [[5000]]),
        saasVersion(2, 'b', 2, '2026-01-01', [[1260]]),
        saasVersion(3, 'c', 3, '2026-01-01', [[1000]]),
        saasVersion(4, 'd', 4, '2026-01-01', [[500]]),
        saasVersion(5, 'e', 5, '2026-01-01', [[100, 1, 'EUR']]),
        saasVersion(6, 'f', 6, '2026-01-01', [[1120]]),
        // February: a new customer, one up, one down, one gone.
        saasVersion(7, 'g', 7, '2026-02-01', [[1200]]),
        saasVersion(8, 'a', 1, '2026-02-01', [[5300]]),
        saasVersion(9, 'b', 2, '2026-02-01', [[1110]]),
        saasVersion(10, 'd', 4, '2026-01-15', [[500]], ['cancel' => '2026-02-01']),
    ];

    $january = saasMonth($versions, '2026-01-01', $rates, 6);
    $february = saasMonth($versions, '2026-02-01', $rates);

    expect($january->mrrOre())->toBe(1_000_000)
        ->and($february->openingOre())->toBe(1_000_000)
        ->and($february->movementOre(Movement::New))->toBe(120_000)
        ->and($february->movementOre(Movement::Expansion))->toBe(30_000)
        ->and($february->movementOre(Movement::Contraction))->toBe(-15_000)
        ->and($february->movementOre(Movement::Churn))->toBe(-50_000)
        ->and($february->fxOre())->toBe(1_234)
        ->and($february->mrrOre())->toBe(1_086_234)
        ->and($february->bridgeHolds())->toBeTrue()
        ->and($february->customers[5]->movement)->toBeNull();
});

it('churns an annual contract at the end of the paid period, not at the notice (F3)', function () {
    $versions = [
        saasVersion(1, 'a', 1, '2026-03-01', [[12000, 12]]),
        // Notice given 10 June; the paid year runs to 28 February 2027.
        saasVersion(2, 'a', 1, '2026-06-10', [[12000, 12]], ['cancel' => '2027-03-01']),
    ];
    $history = (new RecurringRevenue)->history($versions, '2027-03-01');

    expect($history->month('2026-05-01')->scheduledChurnOre())->toBe(0)
        ->and($history->month('2026-06-01')->scheduledChurnOre())->toBe(100_000)
        ->and($history->month('2026-06-01')->customers[1]->scheduledChurnMonth)->toBe('2027-03-01')
        ->and($history->month('2026-06-01')->movementOre(Movement::Churn))->toBe(0)
        ->and($history->month('2027-02-01')->mrrOre())->toBe(100_000)
        ->and($history->month('2027-03-01')->movementOre(Movement::Churn))->toBe(-100_000)
        ->and($history->month('2027-03-01')->churnedCustomers())->toBe(1);

    foreach ($history->months as $month) {
        if ($month->month < '2027-03-01') {
            expect($month->churnedCustomers())->toBe(0, $month->month);
        }
    }
});

it('counts a returning customer as a reactivation in its first cohort, never as new (F4)', function () {
    $versions = [
        saasVersion(1, 'a', 1, '2026-01-01', [[400]], ['cancel' => '2026-04-01']),
        saasVersion(2, 'b', 1, '2026-07-01', [[450]]),
    ];
    $history = (new RecurringRevenue)->history($versions, '2026-07-01');

    expect($history->month('2026-01-01')->customers[1]->movement)->toBe(Movement::New)
        ->and($history->month('2026-04-01')->customers[1]->movement)->toBe(Movement::Churn)
        ->and($history->month('2026-05-01')->customers)->toBe([])
        ->and($history->month('2026-07-01')->customers[1]->movement)->toBe(Movement::Reactivation)
        ->and($history->month('2026-07-01')->customers[1]->cohortMonth)->toBe('2026-01-01')
        ->and($history->month('2026-07-01')->customersWith(Movement::New))->toBe(0)
        ->and(array_keys($history->cohorts('2026-07-01')))->toBe(['2026-01-01']);
});

it('applies a back-dated change only to what is known after it (F5)', function () {
    $versions = [
        saasVersion(1, 'a', 1, '2026-01-01', [[500]]),
        // Registered in April: a price cut effective 1 March.
        saasVersion(2, 'a', 1, '2026-03-01', [[400]]),
    ];

    expect(saasMonth($versions, '2026-03-01', [], 1)->mrrOre())->toBe(50_000)
        ->and(saasMonth($versions, '2026-03-01')->mrrOre())->toBe(40_000)
        ->and(saasMonth($versions, '2026-02-01')->mrrOre())->toBe(50_000)
        ->and(saasMonth($versions, '2026-03-01')->customers[1]->movement)->toBe(Movement::Contraction);
});

it('shows a change of rate as a currency effect and not as a contraction (F7)', function () {
    $rates = ['2026-01-01' => ['EUR' => 11_200_000], '2026-02-01' => ['EUR' => 11_100_000]];
    $versions = [saasVersion(1, 'a', 1, '2026-01-01', [[100, 1, 'EUR']])];

    $february = saasMonth($versions, '2026-02-01', $rates);

    expect(saasMonth($versions, '2026-01-01', $rates)->mrrOre())->toBe(112_000)
        ->and($february->mrrOre())->toBe(111_000)
        ->and($february->fxOre())->toBe(-1_000)
        ->and($february->movementOre(Movement::Contraction))->toBe(0)
        ->and($february->customers[1]->movement)->toBeNull()
        ->and($february->rates)->toBe(['EUR' => 11_100_000]);
});

it('leaves out a line whose currency has no rate and says which', function () {
    $month = saasMonth([saasVersion(1, 'a', 1, '2026-01-01', [[100, 1, 'USD'], [50]])], '2026-01-01');

    expect($month->mrrOre())->toBe(5_000)
        ->and($month->missingRates)->toBe(['USD']);
});

it('pauses with MRR 0, resumes as Återupptagen, and churns a pause at its 13th month-end', function () {
    $versions = [
        saasVersion(1, 'a', 1, '2026-01-01', [[300]]),
        saasVersion(2, 'a', 1, '2026-03-10', [[300]], ['status' => ContractStatus::Paused]),
        saasVersion(3, 'b', 2, '2026-01-01', [[200]]),
        saasVersion(4, 'b', 2, '2026-03-10', [[200]], ['status' => ContractStatus::Paused]),
        saasVersion(5, 'b', 2, '2026-05-02', [[200]]),
    ];
    $history = (new RecurringRevenue)->history($versions, '2027-04-01');

    expect($history->month('2026-03-01')->movementOre(Movement::Pause))->toBe(-50_000)
        ->and($history->month('2026-03-01')->churnedCustomers())->toBe(0)
        ->and($history->month('2026-05-01')->customers[2]->movement)->toBe(Movement::Resumed)
        ->and($history->month('2027-01-01')->customers[1]->state)->toBe(CustomerState::Paused)
        ->and($history->month('2027-02-01')->customers[1]->state)->toBe(CustomerState::Paused);

    $thirteenth = $history->month('2027-03-01');

    expect($thirteenth->customers[1]->movement)->toBe(Movement::Churn)
        ->and($thirteenth->customers[1]->state)->toBe(CustomerState::Churned)
        ->and($thirteenth->movementOre(Movement::Churn))->toBe(-30_000)
        ->and($thirteenth->movementOre(Movement::Pause))->toBe(30_000)
        ->and($thirteenth->bridgeHolds())->toBeTrue()
        // Not in the churn rates: the customer had no MRR at the month before.
        ->and($thirteenth->churnedCustomers())->toBe(0);
});

it('does not count excluded contracts or contracts without a customer', function () {
    $versions = [
        saasVersion(1, 'a', 1, '2026-01-01', [[300]], ['excluded' => ExclusionReason::LinkedRecurringInvoice]),
        new ContractVersion(2, SourceType::CrmAgreement, '7', null, '2026-01-01', ContractStatus::Active, [new RevenueLine('v', 'Värde', 'SEK', 10_000)]),
        saasVersion(3, 'b', 2, '2026-01-01', [[100]]),
    ];

    expect(saasMonth($versions, '2026-01-01')->mrrOre())->toBe(10_000);
});

it('does not count a contract before it starts', function () {
    $versions = [saasVersion(1, 'a', 1, '2026-01-01', [[300]], ['starts_on' => '2026-03-15'])];
    $history = (new RecurringRevenue)->history($versions, '2026-03-01');

    expect($history->month('2026-02-01'))->toBeNull()
        ->and($history->month('2026-03-01')->customers[1]->movement)->toBe(Movement::New);
});

it('measures NRR and GRR over twelve months at the month\'s rate', function () {
    $versions = [
        saasVersion(1, 'a', 1, '2026-01-01', [[1000]]),
        saasVersion(2, 'b', 2, '2026-01-01', [[500]], ['cancel' => '2026-06-01']),
        saasVersion(3, 'a', 1, '2026-09-01', [[1300]]),
        saasVersion(4, 'c', 3, '2026-05-01', [[700]]),
    ];
    $month = saasMonth($versions, '2027-01-01');

    // Base 1 500 kr; now 1 300 kr from the same customers; the new customer is outside.
    expect($month->nrrBaseOre)->toBe(150_000)
        ->and($month->nrrOre)->toBe(130_000)
        ->and($month->grrOre)->toBe(100_000);
});

it('keeps the bridge and the line sums for random histories, whatever the order of the versions', function () {
    mt_srand(80);

    for ($round = 0; $round < 25; $round++) {
        $versions = [];
        $id = 1;
        $rates = [];

        foreach (['2026-01-01', '2026-02-01', '2026-03-01', '2026-04-01', '2026-05-01', '2026-06-01', '2026-07-01', '2026-08-01'] as $month) {
            $rates[$month] = ['EUR' => mt_rand(10_500_000, 11_900_000)];
        }

        for ($change = 0; $change < 30; $change++) {
            $customer = mt_rand(1, 6);
            $contract = 'k'.mt_rand(1, 8);
            $from = sprintf('2026-%02d-%02d', mt_rand(1, 8), mt_rand(1, 28));
            $lines = [[mt_rand(1, 50_000) / 100, [1, 3, 6, 12][mt_rand(0, 3)], mt_rand(0, 4) === 0 ? 'EUR' : 'SEK']];
            $extra = match (mt_rand(0, 5)) {
                0 => ['status' => ContractStatus::Paused],
                1 => ['cancel' => sprintf('2026-%02d-01', mt_rand(2, 9))],
                default => [],
            };
            $versions[] = saasVersion($id++, $contract, $customer, $from, $lines, $extra);
        }

        $history = (new RecurringRevenue)->history($versions, '2026-08-01', $rates);
        $shuffled = $versions;
        shuffle($shuffled);
        $again = (new RecurringRevenue)->history($shuffled, '2026-08-01', $rates);

        $previous = null;

        foreach ($history->months as $month => $result) {
            expect($result->bridgeHolds())->toBeTrue("round {$round}, {$month}");

            foreach ($result->customers as $row) {
                expect($row->mrrOre)->toBe(array_sum(array_map(fn ($line) => $line->sekOre, $row->lines)))
                    ->and($row->mrrOre)->toBe($row->openingOre + $row->fxOre + $row->movementOre);
            }

            if ($previous !== null) {
                expect($result->openingOre())->toBe($previous->mrrOre());
            }

            expect($again->month($month)?->mrrOre())->toBe($result->mrrOre());
            $previous = $result;
        }
    }
});
