<?php

declare(strict_types=1);

use App\Domain\Assets\ComponentSplit;
use App\Domain\Assets\ComponentSplitRefused;
use App\Domain\Assets\DepreciationBasis;
use App\Domain\Assets\DepreciationPlan;
use Carbon\CarbonImmutable;

/*
 * S1-P4 (ADR-059 A.2.4, A.5): the arithmetic of a component split and of the
 * carrying-amount plan, without the database.
 */

/** @return array{cost_ore: int, land: bool, accumulated_ore: ?int} */
function part(int $cost, bool $land = false, ?int $accumulated = null): array
{
    return ['cost_ore' => $cost, 'land' => $land, 'accumulated_ore' => $accumulated];
}

it('S1-R3 S1-R7.2 a allocates cost and accumulated depreciation exactly, by the largest remainder', function () {
    // 100 öre written off over three equal components: 33,33… each; the leftover öre goes to the first.
    $split = ComponentSplit::allocate(300, 100, [part(100), part(100), part(100)]);

    expect(array_column($split, 'accumulated_ore'))->toBe([34, 33, 33])
        ->and(array_sum(array_column($split, 'accumulated_ore')))->toBe(100)
        ->and(array_column($split, 'cost_ore'))->toBe([100, 100, 100]);

    // Unequal weights: 1 000 öre over 1:2:4 = 142,86 / 285,71 / 571,43 → the largest remainders win.
    expect(array_column(ComponentSplit::allocate(7_000, 1_000, [part(1_000), part(2_000), part(4_000)]), 'accumulated_ore'))->toBe([143, 286, 571]);
});

it('S1-R3.2 gives land no accumulated depreciation, and divides it among the depreciable components only', function () {
    // F-S1-a: 26 080 000 kr of which land 4 000 000; 4 440 000 kr written off on the building.
    $split = ComponentSplit::allocate(2_608_000_000, 444_000_000, [
        part(400_000_000, land: true),
        part(1_440_000_000),
        part(216_000_000),
        part(192_000_000),
        part(360_000_000),
    ]);

    expect($split[0])->toBe(['cost_ore' => 400_000_000, 'accumulated_ore' => 0])
        ->and(array_sum(array_column($split, 'accumulated_ore')))->toBe(444_000_000)
        ->and(array_sum(array_column($split, 'cost_ore')))->toBe(2_608_000_000);
});

it('takes the accumulated depreciation each component has, when the person enters it for every one', function () {
    $split = ComponentSplit::allocate(2_208_000_000, 444_000_000, [
        part(1_440_000_000, accumulated: 180_000_000),
        part(216_000_000, accumulated: 72_000_000),
        part(192_000_000, accumulated: 48_000_000),
        part(360_000_000, accumulated: 144_000_000),
    ]);

    expect(array_column($split, 'accumulated_ore'))->toBe([180_000_000, 72_000_000, 48_000_000, 144_000_000]);
});

it('refuses a split whose figures do not add up, or that has no real components', function (array $parts, int $cost, int $accumulated, string $message) {
    expect(fn () => ComponentSplit::allocate($cost, $accumulated, $parts))->toThrow(ComponentSplitRefused::class, $message);
})->with([
    'costs short' => [[part(100), part(150)], 300, 0, 'ska tillsammans vara'],
    'one component' => [[part(300)], 300, 0, 'minst två'],
    'two land' => [[part(100, true), part(200, true)], 300, 0, 'en markkomponent'],
    'land with depreciation' => [[part(100, true, 5), part(200)], 300, 5, 'Mark skrivs inte av'],
    'only land, depreciated asset' => [[part(100, true), part(200, true)], 300, 10, 'en markkomponent'],
    'some entered' => [[part(100, accumulated: 10), part(200)], 300, 30, 'för alla komponenter'],
    'entered do not add up' => [[part(100, accumulated: 10), part(200, accumulated: 10)], 300, 30, 'gjorda avskrivningar ska tillsammans'],
    'more than cost' => [[part(100, accumulated: 150), part(200, accumulated: 0)], 300, 150, 'större än dess anskaffningsvärde'],
    'zero cost' => [[part(0), part(300)], 300, 0, 'större än noll'],
]);

it('S1-R7.2 a writes off the carrying amount over the remaining life, with no catch-up of earlier months', function () {
    // Stomme in F-S1-a: 14 400 000 kr, 1 800 000 written off at the split on 2025-01-01, 840 months left.
    $plan = new DepreciationPlan(1_440_000_000, 0, 840, CarbonImmutable::parse('2025-01-01'), 180_000_000, DepreciationBasis::CarryingAmount);

    expect($plan->accumulatedBy('2024-12'))->toBe(180_000_000)
        ->and($plan->amountFor('2025-01', 180_000_000))->toBe(1_500_000)
        ->and($plan->amountFor('2026-12', 180_000_000 + 23 * 1_500_000))->toBe(1_500_000)
        ->and($plan->depreciableOre())->toBe(1_440_000_000)
        ->and(array_sum(array_column($plan->schedule(), 'amount')))->toBe(1_260_000_000)
        ->and($plan->schedule()[0])->toBe(['month' => '2025-01', 'amount' => 1_500_000, 'book_value' => 1_440_000_000 - 181_500_000]);

    // A month missed before the split is never caught up: whatever was written off is the starting point.
    $behind = new DepreciationPlan(1_200, 0, 12, CarbonImmutable::parse('2026-01-01'), 100, DepreciationBasis::CarryingAmount);

    expect($behind->amountFor('2026-01', 100))->toBe(92)
        ->and(array_sum(array_column($behind->schedule(), 'amount')))->toBe(1_100);
});

it('keeps the cost-basis plan exactly as before (regression)', function () {
    $plan = new DepreciationPlan(1_000_000, 0, 3, CarbonImmutable::parse('2026-01-01'));
    $withOpening = new DepreciationPlan(4_000_000, 0, 60, CarbonImmutable::parse('2024-01-01'), 1_600_000);

    expect(array_column($plan->schedule(), 'amount'))->toBe([333_333, 333_334, 333_333])
        ->and($plan->accumulatedBy('2025-12'))->toBe(0)
        ->and($plan->amountFor('2026-02', 0))->toBe(666_667)
        // The cost basis still catches up: 25 of 60 months by January 2026, 1 600 000 written off before.
        ->and($withOpening->amountFor('2026-01', 1_600_000))->toBe(66_667);
});

it('S1-R3.2 a plan without a life writes off nothing (land)', function () {
    $plan = new DepreciationPlan(400_000_000, 0, 0, CarbonImmutable::parse('2015-01-01'));

    expect($plan->depreciableOre())->toBe(0)
        ->and($plan->amountFor('2026-12', 0))->toBe(0)
        ->and($plan->schedule())->toBe([]);
});
