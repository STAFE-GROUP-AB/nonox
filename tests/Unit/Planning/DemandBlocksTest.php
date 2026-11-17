<?php

declare(strict_types=1);

use App\Modules\Planning\Domain\Budget\BudgetLine;
use App\Modules\Planning\Domain\Budget\BudgetWeek;
use App\Modules\Planning\Domain\Demand\DayBuckets;
use App\Modules\Planning\Domain\Demand\DayDemand;
use App\Modules\Planning\Domain\Demand\Forecast;
use App\Modules\Planning\Domain\Proposal\BudgetFit;
use App\Modules\Planning\Domain\Proposal\DemandBlock;
use App\Modules\Planning\Domain\Proposal\DemandBlocks;
use Carbon\CarbonImmutable;

/*
 * New shifts from the staffing gap (ADR-037 amendment, gap 3, v2/P8): the
 * allowed lengths, the earliest gap first, never over midnight or into the
 * past, the break on long shifts, summer time, and the budget that costs a
 * new shift the same whoever takes it. Synthetic, no database.
 */

/**
 * A day with the given need and planned staff per local hour.
 *
 * @param  array<int, int>  $need  hour => required staff
 * @param  array<int, float>  $planned  hour => planned staff
 */
function p8Day(string $date, array $need, array $planned = [], ?string $shape = 'PROFILE', ?int $needMinutes = null, int $plannedMinutes = 0): DayDemand
{
    $hours = [];

    foreach (DayBuckets::lengths(DayBuckets::of(CarbonImmutable::parse($date, 'Europe/Stockholm'))) as $hour => $minutes) {
        $hours[$hour] = ['hour' => $hour, 'minutes' => $minutes, 'sales' => null, 'need' => $need[$hour] ?? null, 'planned' => $planned[$hour] ?? 0.0, 'open' => 0.0, 'status' => DayDemand::NONE];
    }

    return new DayDemand($date, new Forecast($date, null, Forecast::NONE, []), $shape, $hours, $needMinutes ?? array_sum($need) * 60, $plannedMinutes, 0, DayDemand::UNDER, true);
}

/** @param  list<DemandBlock>  $blocks */
function p8Times(array $blocks): array
{
    return array_map(fn (DemandBlock $block): string => $block->start->format('H:i').'–'.$block->end->format('H:i').($block->breakMinutes > 0 ? " ({$block->breakMinutes})" : ''), $blocks);
}

function p8Past(): CarbonImmutable
{
    return CarbonImmutable::parse('2026-01-01 00:00', 'Europe/Stockholm');
}

it('covers a lunch peak with the length that fits best, one person at a time', function () {
    // 11–14 two people, 14–17 one person, half a person already planned at 11.
    $day = p8Day('2026-11-24', [11 => 2, 12 => 2, 13 => 2, 14 => 1, 15 => 1, 16 => 1], [11 => 0.5]);

    [$blocks, $left] = DemandBlocks::forDay($day, 7, [240, 360, 480], 30, 300, p8Past());

    // The first person 11–17 (six hours, all with a gap), the second 11–15 (three with a gap, one without): a six-hour shift gets the break.
    expect(p8Times($blocks))->toBe(['11:00–15:00', '11:00–17:00 (30)'])
        ->and($blocks[0]->areaId)->toBe(7)
        ->and(array_map(fn (DemandBlock $block): int => $block->coveredHours, $blocks))->toBe([3, 6])
        ->and($left)->toBe(0);
});

it('never runs past midnight or starts in the past, and leaves an hour no length fits', function () {
    $evening = p8Day('2026-11-24', [22 => 1, 23 => 1]);
    expect(p8Times(DemandBlocks::forDay($evening, null, [240], 0, null, p8Past())[0]))->toBe(['20:00–00:00']);

    // At 12:30 the hours from 12 have started; 13 and 14 can still be covered.
    $today = p8Day('2026-11-24', [11 => 1, 12 => 1, 13 => 1, 14 => 1]);
    $now = CarbonImmutable::parse('2026-11-24 12:30', 'Europe/Stockholm');
    expect(p8Times(DemandBlocks::forDay($today, null, [120], 0, null, $now)[0]))->toBe(['13:00–15:00']);

    // Only twelve-hour shifts and a need at 22: none fits after 12:30 without running past midnight or starting in the past.
    $late = p8Day('2026-11-24', [22 => 1]);
    expect(DemandBlocks::forDay($late, null, [720], 0, null, CarbonImmutable::parse('2026-11-24 12:30', 'Europe/Stockholm'))[0])->toBe([]);
});

it('counts real time on the days summer time starts and ends', function () {
    // 29 March 2026 has no 02 hour: 00–04 local is three real hours.
    $spring = p8Day('2026-03-29', [0 => 1, 1 => 1, 3 => 1]);
    $blocks = DemandBlocks::forDay($spring, null, [180], 0, null, p8Past())[0];
    expect(p8Times($blocks))->toBe(['00:00–04:00'])
        ->and($blocks[0]->workedMinutes())->toBe(180);

    // 25 October 2026 has two 02 hours.
    $autumn = p8Day('2026-10-25', [1 => 1, 2 => 1]);
    $blocks = DemandBlocks::forDay($autumn, null, [180], 0, null, p8Past())[0];
    expect($blocks)->toHaveCount(1)
        ->and($blocks[0]->workedMinutes())->toBe(180)
        ->and($blocks[0]->coveredHours)->toBe(2);
});

it('places nothing for a need per day only, and returns what is left of it', function () {
    $day = p8Day('2026-11-24', [], [], null, 720, 240);

    [$blocks, $left] = DemandBlocks::forDay($day, null, [240], 0, null, p8Past());

    expect($blocks)->toBe([])->and($left)->toBe(480);
});

it('is deterministic', function () {
    $day = p8Day('2026-11-24', [8 => 3, 9 => 3, 10 => 2, 11 => 2, 12 => 4, 13 => 4, 17 => 2, 18 => 2, 19 => 1]);

    expect(p8Times(DemandBlocks::forDay($day, null, [240, 360, 480], 30, 300, p8Past())[0]))
        ->toBe(p8Times(DemandBlocks::forDay($day, null, [480, 240, 360], 30, 300, p8Past())[0]));
});

function p8Block(string $date, string $start, string $end, ?int $area = null): DemandBlock
{
    return new DemandBlock(CarbonImmutable::parse("{$date} {$start}", 'Europe/Stockholm'), CarbonImmutable::parse("{$date} {$end}", 'Europe/Stockholm'), 0, $area, $date, 3);
}

function p8Line(?int $area, ?int $budget, int $cost): BudgetLine
{
    return new BudgetLine($area, BudgetLine::AMOUNT, $budget, null, null, 0, $cost, false, 9500);
}

it('keeps new shifts within every budget line they count against, in time order', function () {
    $blocks = [p8Block('2026-11-24', '11:00', '15:00', 1), p8Block('2026-11-25', '11:00', '15:00', 1), p8Block('2026-11-25', '11:00', '15:00', 2)];
    // 200 kr an hour: a four-hour shift is 800 kr. The company has 1 800 kr left, area 1 has 1 000.
    $week = new BudgetWeek('2026-11-23', p8Line(null, 1_000_000, 820_000), [1 => p8Line(1, 300_000, 200_000)], 9500);

    $fit = BudgetFit::apply($blocks, ['2026-11-23' => $week], [], 20_000);

    expect(count($fit['kept']))->toBe(2)
        ->and($fit['over'])->toBe([$blocks[1]])
        ->and($fit['estimates'])->toBe(['2026-11-23' => 160_000])
        ->and($fit['unchecked'])->toBeFalse();

    // The open shifts the proposal fills take their room first.
    $reserved = BudgetFit::apply($blocks, ['2026-11-23' => $week], [], 20_000, [['area' => 2, 'date' => '2026-11-24', 'minutes' => 300]]);
    expect(count($reserved['kept']))->toBe(1);
});

it('has no limit without a budget, and says when it cannot check one', function () {
    $blocks = [p8Block('2026-11-24', '11:00', '15:00')];

    expect(BudgetFit::apply($blocks, [], [], null)['kept'])->toBe($blocks);

    $week = new BudgetWeek('2026-11-23', p8Line(null, 100, 0), [], 9500);
    $fit = BudgetFit::apply($blocks, ['2026-11-23' => $week], [], null);

    expect($fit['kept'])->toBe($blocks)->and($fit['unchecked'])->toBeTrue();
});

it('gives a break for each time a new shift would run past the limit without one', function () {
    $day = p8Day('2026-11-24', array_fill_keys(range(8, 17), 1));

    expect(p8Times(DemandBlocks::forDay($day, null, [600], 30, 300, p8Past())[0]))->toBe(['08:00–18:00 (30)'])
        ->and(p8Times(DemandBlocks::forDay($day, null, [600], 30, 240, p8Past())[0]))->toBe(['08:00–18:00 (60)'])
        ->and(p8Times(DemandBlocks::forDay(p8Day('2026-11-24', [8 => 1]), null, [300], 30, 300, p8Past())[0]))->toBe(['08:00–13:00']);
});

it('reports the gap left when the day reaches its most new shifts', function () {
    $day = p8Day('2026-11-24', [12 => DemandBlocks::MAX_PER_DAY + 3]);

    [$blocks, , $cut] = DemandBlocks::forDay($day, null, [120], 0, null, p8Past());

    expect($blocks)->toHaveCount(DemandBlocks::MAX_PER_DAY)->and($cut)->toBe(180);
});

it('counts the hours no allowed length fits around instead of dropping them', function () {
    // Two people at 22 after 12:30 with only twelve-hour shifts: neither fits without starting in the past.
    [$blocks, , $cut] = DemandBlocks::forDay(p8Day('2026-11-24', [22 => 2]), null, [720], 0, null, CarbonImmutable::parse('2026-11-24 12:30', 'Europe/Stockholm'));

    expect($blocks)->toBe([])->and($cut)->toBe(120);
});

it('gives the budget to the shifts covering the most hours first, whatever the day', function () {
    $monday = new DemandBlock(CarbonImmutable::parse('2026-11-23 11:00', 'Europe/Stockholm'), CarbonImmutable::parse('2026-11-23 15:00', 'Europe/Stockholm'), 0, null, '2026-11-23', 1);
    $saturday = new DemandBlock(CarbonImmutable::parse('2026-11-28 11:00', 'Europe/Stockholm'), CarbonImmutable::parse('2026-11-28 15:00', 'Europe/Stockholm'), 0, null, '2026-11-28', 4);
    $week = new BudgetWeek('2026-11-23', p8Line(null, 100_000, 0), [], 9500);

    $fit = BudgetFit::apply([$monday, $saturday], ['2026-11-23' => $week], [], 20_000);

    expect($fit['kept'])->toBe([$saturday])->and($fit['over'])->toBe([$monday]);
});
