<?php

declare(strict_types=1);

use App\Modules\Saas\Domain\ContractStatus;
use App\Modules\Saas\Domain\ContractVersion;
use App\Modules\Saas\Domain\CustomerState;
use App\Modules\Saas\Domain\Movement;
use App\Modules\Saas\Domain\RecurringRevenue;
use App\Modules\Saas\Domain\RevenueLine;
use App\Modules\Saas\Domain\SourceType;

/*
 * The cold review of ADR-080 step 1: the cases its repro scripts found, as
 * fixtures for the pure calculation. Synthetic numbers only.
 */

/**
 * @param  list<array{0: int, 1?: int, 2?: string}>  $lines  amount in öre per period, months, currency
 * @param  array<string, mixed>  $extra
 */
function reviewVersion(int $id, string $contract, int $customer, string $from, array $lines, array $extra = []): ContractVersion
{
    $revenue = [];

    foreach ($lines as $index => $line) {
        $revenue[] = new RevenueLine("l{$index}", '', $line[2] ?? 'SEK', $line[0], 'month', $line[1] ?? 1);
    }

    return new ContractVersion($id, SourceType::RecurringInvoice, $contract, $customer, $from, $extra['status'] ?? ContractStatus::Active, $revenue, null, $extra['cancel'] ?? null);
}

it('carries the last known rate into a month without one and flags it, with no fake churn (review 9)', function () {
    $rates = ['2026-01-01' => ['EUR' => 11_000_000], '2026-03-01' => ['EUR' => 11_000_000]];
    $history = (new RecurringRevenue)->history([reviewVersion(1, 'a', 1, '2026-01-01', [[10_000, 1, 'EUR']])], '2026-03-01', $rates);
    $february = $history->month('2026-02-01');

    expect($february->mrrOre())->toBe(110_000)
        ->and($february->missingRates)->toBe(['EUR'])
        ->and($february->rates)->toBe(['EUR' => 11_000_000])
        ->and($february->customers[1]->movement)->toBeNull()
        ->and($history->month('2026-03-01')->customers[1]->movement)->toBeNull()
        ->and($history->month('2026-03-01')->missingRates)->toBe([]);
});

it('counts a customer whose lines add up to less than zero as 0 and flags it, keeping the bridge (review 10)', function () {
    $history = (new RecurringRevenue)->history([reviewVersion(1, 'a', 1, '2026-01-01', [[100_000]]), reviewVersion(2, 'a', 1, '2026-02-01', [[-50_000]])], '2026-03-01');

    expect($history->month('2026-02-01')->mrrOre())->toBe(0)
        ->and($history->month('2026-02-01')->negativeCustomers)->toBe([1])
        ->and($history->month('2026-02-01')->customers[1]->movement)->toBe(Movement::Churn)
        ->and($history->month('2026-02-01')->bridgeHolds())->toBeTrue()
        ->and($history->month('2026-03-01')->bridgeHolds())->toBeTrue();
});

it('makes a cancelled contract churn even when another contract of the customer is paused (review 2, SA-R14)', function () {
    $history = (new RecurringRevenue)->history([
        reviewVersion(1, 'a', 1, '2026-01-01', [[100_000]]), reviewVersion(2, 'b', 1, '2026-01-01', [[300_000]]),
        reviewVersion(3, 'a', 1, '2026-02-01', [[100_000]], ['status' => ContractStatus::Paused]),
        reviewVersion(4, 'b', 1, '2026-03-01', [[300_000]], ['cancel' => '2026-03-15']),
    ], '2027-03-01');

    expect($history->month('2026-02-01')->customers[1]->movement)->toBe(Movement::Contraction)
        ->and($history->month('2026-03-01')->customers[1]->movement)->toBe(Movement::Churn)
        ->and($history->month('2026-03-01')->movementOre(Movement::Churn))->toBe(-300_000)
        ->and($history->month('2026-03-01')->movementOre(Movement::Pause))->toBe(0)
        ->and($history->month('2026-03-01')->churnedCustomers())->toBe(1);

    foreach ($history->months as $month) {
        expect($month->bridgeHolds())->toBeTrue($month->month);

        if ($month->month > '2026-03-01') {
            expect($month->movementOre(Movement::Churn))->toBe(0, $month->month);
        }
    }
});

it('churns a paused contract ended today: the paused amount moves from Paus to Churn (review 1a)', function () {
    $history = (new RecurringRevenue)->history([
        reviewVersion(1, 'a', 1, '2026-01-01', [[100_000]]),
        reviewVersion(2, 'a', 1, '2026-02-10', [[100_000]], ['status' => ContractStatus::Paused]),
        reviewVersion(3, 'a', 1, '2026-05-10', [[100_000]], ['status' => ContractStatus::Paused, 'cancel' => '2026-05-10']),
    ], '2027-06-01');

    expect($history->month('2026-02-01')->movementOre(Movement::Pause))->toBe(-100_000)
        ->and($history->month('2026-04-01')->customers[1]->state)->toBe(CustomerState::Paused)
        ->and($history->month('2026-05-01')->customers[1]->movement)->toBe(Movement::Churn)
        ->and($history->month('2026-05-01')->movementOre(Movement::Churn))->toBe(-100_000)
        ->and($history->month('2026-05-01')->movementOre(Movement::Pause))->toBe(100_000)
        ->and($history->month('2026-05-01')->customers[1]->state)->toBe(CustomerState::Churned);

    foreach ($history->months as $month) {
        expect($month->bridgeHolds())->toBeTrue($month->month);

        if ($month->month !== '2026-05-01') {
            expect($month->movementOre(Movement::Churn))->toBe(0, $month->month);
        }
    }
});

it('keeps a paused contract ended on a later date paused until then, without a fake resumption (review 1b)', function () {
    $history = (new RecurringRevenue)->history([
        reviewVersion(1, 'a', 1, '2026-01-01', [[100_000]]),
        reviewVersion(2, 'a', 1, '2026-02-10', [[100_000]], ['status' => ContractStatus::Paused]),
        reviewVersion(3, 'a', 1, '2026-05-10', [[100_000]], ['status' => ContractStatus::Paused, 'cancel' => '2026-06-15']),
    ], '2026-07-01');

    expect($history->month('2026-05-01')->customers[1]->state)->toBe(CustomerState::Paused)
        ->and($history->month('2026-05-01')->customers[1]->movement)->toBeNull()
        ->and($history->month('2026-06-01')->customers[1]->movement)->toBe(Movement::Churn)
        ->and($history->month('2026-06-01')->movementOre(Movement::Churn))->toBe(-100_000)
        ->and($history->month('2026-06-01')->movementOre(Movement::Resumed))->toBe(0)
        ->and($history->month('2026-06-01')->bridgeHolds())->toBeTrue();
});
