<?php

declare(strict_types=1);

use App\Modules\Planning\Domain\DayComparison;
use App\Modules\Planning\Domain\Deviation;
use App\Modules\Planning\Domain\PlanVsActual;
use App\Modules\Planning\Domain\WorkInterval;
use Carbon\CarbonImmutable;

/*
 * PlanVsActual (PLANNING_MODULE.md §5): staff-ledger shifts matched to the
 * plan per day and classified, with the attest tolerance and the rounding
 * of check-in times. Every expected time is written out by hand.
 */

function pvaAt(?string $time): ?CarbonImmutable
{
    return $time === null ? null : CarbonImmutable::parse($time, 'Europe/Stockholm');
}

function pvaInterval(?string $start, ?string $end, int $break = 0): WorkInterval
{
    return new WorkInterval(pvaAt($start), pvaAt($end), $break);
}

/** @return list<string> */
function pvaCodes(DayComparison $day): array
{
    return array_map(fn (Deviation $deviation): string => $deviation->code, $day->deviations);
}

/** @return list<string> */
function pvaTimes(DayComparison $day): array
{
    return array_map(fn (WorkInterval $interval): string => ($interval->start?->format('Y-m-d H:i') ?? '–').'/'.($interval->end?->format('Y-m-d H:i') ?? '–').'/'.$interval->breakMinutes, $day->actual);
}

it('pays the plan when the check-in is within the tolerance', function () {
    $days = PlanVsActual::compare(
        [pvaInterval('2026-11-03 16:00', '2026-11-03 23:00', 30)],
        [pvaInterval('2026-11-03 15:56', '2026-11-03 23:04')],
        toleranceMinutes: 5,
    );

    expect(array_keys($days))->toBe(['2026-11-03'])
        ->and(pvaCodes($days['2026-11-03']))->toBe([])
        ->and(pvaTimes($days['2026-11-03']))->toBe(['2026-11-03 16:00/2026-11-03 23:00/30'])
        ->and($days['2026-11-03']->plannedMinutes())->toBe(390)
        ->and($days['2026-11-03']->actualMinutes())->toBe(390)
        ->and($days['2026-11-03']->source)->toBe(DayComparison::SOURCE_LEDGER);
});

it('counts a difference of exactly the tolerance as a deviation and pays the checked-in time', function () {
    $days = PlanVsActual::compare(
        [pvaInterval('2026-11-03 08:00', '2026-11-03 16:00', 30)],
        [pvaInterval('2026-11-03 08:05', '2026-11-03 15:40')],
        toleranceMinutes: 5,
    );
    $day = $days['2026-11-03'];

    expect(pvaCodes($day))->toBe([Deviation::LATE_START, Deviation::EARLY_FINISH])
        ->and($day->deviations[0]->message)->toBe('Började 5 min sent (planerat 08:00, incheckad 08:05).')
        ->and($day->deviations[1]->message)->toBe('Slutade 20 min tidigt (planerat 16:00, utcheckad 15:40).')
        // 08:05–15:40 = 455 min less the planned 30 min break.
        ->and($day->actualMinutes())->toBe(425)
        ->and($day->differenceMinutes())->toBe(-25);
});

it('classifies early start and late finish', function () {
    $day = PlanVsActual::compare(
        [pvaInterval('2026-11-03 10:00', '2026-11-03 14:00')],
        [pvaInterval('2026-11-03 09:30', '2026-11-03 15:15')],
        toleranceMinutes: 5,
    )['2026-11-03'];

    expect(pvaCodes($day))->toBe([Deviation::EARLY_START, Deviation::LATE_FINISH])
        ->and($day->actualMinutes())->toBe(345);
});

it('rounds check-in times to the step before comparing', function () {
    // 07:53 rounds to 08:00 and 16:08 to 16:15 with a 15-minute step.
    $day = PlanVsActual::compare(
        [pvaInterval('2026-11-03 08:00', '2026-11-03 16:00')],
        [pvaInterval('2026-11-03 07:53', '2026-11-03 16:08')],
        toleranceMinutes: 0,
        roundingMinutes: 15,
    )['2026-11-03'];

    expect(pvaCodes($day))->toBe([Deviation::LATE_FINISH])
        ->and(pvaTimes($day))->toBe(['2026-11-03 08:00/2026-11-03 16:15/0']);
});

it('blocks a shift without check-out and pays nothing for it', function () {
    $day = PlanVsActual::compare(
        [pvaInterval('2026-11-03 16:00', '2026-11-03 23:00')],
        [pvaInterval('2026-11-03 16:02', null)],
        toleranceMinutes: 5,
    )['2026-11-03'];

    expect(pvaCodes($day))->toBe([Deviation::MISSING_CHECK_OUT])
        ->and($day->deviations[0]->blocking)->toBeTrue()
        ->and($day->actualMinutes())->toBe(0);
});

it('blocks a check-out without check-in', function () {
    $day = PlanVsActual::compare([], [pvaInterval(null, '2026-11-03 23:00')])['2026-11-03'];

    expect(pvaCodes($day))->toBe([Deviation::MISSING_CHECK_IN])
        ->and($day->deviations[0]->blocking)->toBeTrue();
});

it('shows unplanned work and no-shows, and absence registered in payroll instead of a no-show', function () {
    $days = PlanVsActual::compare(
        [pvaInterval('2026-11-04 08:00', '2026-11-04 12:00'), pvaInterval('2026-11-05 08:00', '2026-11-05 12:00')],
        [pvaInterval('2026-11-06 18:00', '2026-11-06 22:30')],
        absenceDays: ['2026-11-05' => true],
    );

    expect(array_keys($days))->toBe(['2026-11-04', '2026-11-05', '2026-11-06'])
        ->and(pvaCodes($days['2026-11-04']))->toBe([Deviation::NO_SHOW])
        ->and($days['2026-11-04']->deviations[0]->message)->toBe('Planerat 08:00–12:00 men ingen arbetad tid.')
        ->and(pvaCodes($days['2026-11-05']))->toBe([Deviation::ABSENCE])
        ->and($days['2026-11-05']->deviations[0]->informational)->toBeTrue()
        ->and(pvaCodes($days['2026-11-06']))->toBe([Deviation::UNPLANNED])
        ->and($days['2026-11-06']->actualMinutes())->toBe(270);
});

it('keeps a shift over midnight on the day it starts', function () {
    $days = PlanVsActual::compare(
        [pvaInterval('2026-11-06 22:00', '2026-11-07 03:00')],
        [pvaInterval('2026-11-06 21:58', '2026-11-07 03:03')],
        toleranceMinutes: 5,
    );

    expect(array_keys($days))->toBe(['2026-11-06'])
        ->and(pvaCodes($days['2026-11-06']))->toBe([])
        ->and($days['2026-11-06']->actualMinutes())->toBe(300);
});

it('counts real minutes on the nights summer time starts and ends', function () {
    // 2026-03-29 02:00 → 03:00: 22:00–06:00 is seven hours. 2026-10-25 03:00 → 02:00: nine hours.
    $spring = PlanVsActual::compare([pvaInterval('2026-03-28 22:00', '2026-03-29 06:00')], [pvaInterval('2026-03-28 22:00', '2026-03-29 06:00')])['2026-03-28'];
    $autumn = PlanVsActual::compare([pvaInterval('2026-10-24 22:00', '2026-10-25 06:00', 30)], [pvaInterval('2026-10-24 22:01', '2026-10-25 06:02')], toleranceMinutes: 5)['2026-10-24'];

    expect($spring->actualMinutes())->toBe(420)
        ->and($spring->plannedMinutes())->toBe(420)
        ->and($autumn->actualMinutes())->toBe(510)
        ->and(pvaCodes($autumn))->toBe([]);
});

it('matches each part of a split shift to its own check-in', function () {
    $day = PlanVsActual::compare(
        [pvaInterval('2026-11-03 10:00', '2026-11-03 14:00'), pvaInterval('2026-11-03 17:00', '2026-11-03 22:00')],
        [pvaInterval('2026-11-03 17:20', '2026-11-03 22:00'), pvaInterval('2026-11-03 10:00', '2026-11-03 14:00')],
        toleranceMinutes: 5,
    )['2026-11-03'];

    expect(pvaCodes($day))->toBe([Deviation::LATE_START])
        ->and(pvaTimes($day))->toBe(['2026-11-03 10:00/2026-11-03 14:00/0', '2026-11-03 17:20/2026-11-03 22:00/0']);
});

it('uses the plan as actual time without the ledger', function () {
    $day = PlanVsActual::compare([pvaInterval('2026-11-03 08:00', '2026-11-03 16:00', 45)], null)['2026-11-03'];

    expect($day->source)->toBe(DayComparison::SOURCE_PLAN)
        ->and($day->deviations)->toBe([])
        ->and($day->actualMinutes())->toBe(435);
});

it('lets the manager\'s own time replace the ledger for that day only', function () {
    $days = PlanVsActual::compare(
        [pvaInterval('2026-11-03 16:00', '2026-11-03 23:00', 30), pvaInterval('2026-11-04 16:00', '2026-11-04 23:00', 30)],
        [pvaInterval('2026-11-03 16:00', null), pvaInterval('2026-11-04 16:00', null)],
        manual: ['2026-11-03' => [pvaInterval('2026-11-03 16:00', '2026-11-03 23:30', 30)]],
        toleranceMinutes: 5,
    );

    expect($days['2026-11-03']->source)->toBe(DayComparison::SOURCE_MANUAL)
        ->and(pvaCodes($days['2026-11-03']))->toBe([Deviation::LATE_FINISH, Deviation::MANUAL])
        ->and($days['2026-11-03']->actualMinutes())->toBe(420)
        ->and(pvaCodes($days['2026-11-04']))->toBe([Deviation::MISSING_CHECK_OUT]);

    // No work at all, said by the manager: a no-show without blocking.
    $none = PlanVsActual::compare([pvaInterval('2026-11-03 16:00', '2026-11-03 23:00')], null, manual: ['2026-11-03' => []])['2026-11-03'];

    expect(pvaCodes($none))->toBe([Deviation::NO_SHOW, Deviation::MANUAL])
        ->and($none->actualMinutes())->toBe(0);
});
