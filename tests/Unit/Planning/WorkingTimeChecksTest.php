<?php

declare(strict_types=1);

use App\Modules\Planning\Domain\PlannedShift;
use App\Modules\Planning\Domain\WorkingTimeChecks;
use App\Modules\Planning\Domain\WorkingTimeWarning;
use App\Modules\Planning\PlanningSettings;
use Carbon\CarbonImmutable;

/*
 * WorkingTimeChecks (PLANNING_MODULE.md §5) with the default rule set from
 * PlanningSettings: warnings with the rule's source, never a block.
 */

function wtShift(string $id, string $start, string $end, int $break = 0): PlannedShift
{
    return new PlannedShift($id, CarbonImmutable::parse($start, 'Europe/Stockholm'), CarbonImmutable::parse($end, 'Europe/Stockholm'), $break);
}

/** @param list<PlannedShift> $shifts */
function wtRules(array $shifts, array $options = []): array
{
    $settings = PlanningSettings::resolve($options['settings'] ?? []);

    return array_map(fn (WorkingTimeWarning $warning): string => "{$warning->shiftId}:{$warning->rule}", WorkingTimeChecks::run(
        $shifts,
        $settings['working_time_rules'],
        $options['minors'] ?? false,
        $settings['minors_rules'],
        $options['birth'] ?? null,
        $options['weekly'] ?? null,
        $options['only'] ?? null,
    ));
}

it('passes an ordinary week', function () {
    $week = [];

    foreach (['02', '03', '04', '05', '06'] as $day) {
        $week[] = wtShift("d{$day}", "2026-11-{$day} 08:00", "2026-11-{$day} 17:00", 60);
    }

    expect(wtRules($week))->toBe([]);
});

it('warns when the daily rest is short, with the source', function () {
    $warnings = WorkingTimeChecks::run(
        [wtShift('late', '2026-11-02 16:00', '2026-11-02 23:00', 30), wtShift('early', '2026-11-03 07:00', '2026-11-03 12:00')],
        PlanningSettings::WORKING_TIME_RULES,
    );

    expect($warnings)->toHaveCount(1)
        ->and($warnings[0]->shiftId)->toBe('early')
        ->and($warnings[0]->rule)->toBe('daily_rest')
        ->and($warnings[0]->message)->toBe('Bara 8 h vila före passet. Dygnsvilan ska vara minst 11 h.')
        ->and($warnings[0]->source)->toBe('Arbetstidslagen (1982:673) 13 §');
});

it('counts real hours of rest across the summer time change', function () {
    // 2026-03-29 the clocks go forward: 22:00 to 09:00 is 10 real hours, not 11.
    expect(wtRules([wtShift('a', '2026-03-28 14:00', '2026-03-28 22:00', 30), wtShift('b', '2026-03-29 09:00', '2026-03-29 13:00')]))->toBe(['b:daily_rest']);
});

it('warns about overlapping shifts and long shifts without a break', function () {
    expect(wtRules([wtShift('a', '2026-11-02 08:00', '2026-11-02 14:00'), wtShift('b', '2026-11-02 13:00', '2026-11-02 15:00')]))
        ->toBe(['b:overlap', 'a:max_without_break']);
});

it('warns when the week has no 36 hours off', function () {
    $week = [];

    foreach (range(2, 8) as $day) {
        $date = sprintf('2026-11-%02d', $day);
        $week[] = wtShift("d{$day}", "{$date} 10:00", "{$date} 14:00");
    }

    expect(wtRules($week))->toBe(['d2:weekly_rest']);
});

it('warns over 40 and over 48 planned hours a week', function () {
    $week = fn (int $hours): array => array_map(fn (int $day): PlannedShift => wtShift("d{$day}", sprintf('2026-11-%02d 06:00', $day), sprintf('2026-11-%02d 06:00', $day).' +'.$hours.' hours', 30), range(2, 6));

    expect(wtRules([...array_map(fn (int $day): PlannedShift => wtShift("d{$day}", sprintf('2026-11-%02d 07:00', $day), sprintf('2026-11-%02d 16:30', $day), 30), range(2, 6))]))->toBe(['d6:ordinary_week'])
        ->and(wtRules([...array_map(fn (int $day): PlannedShift => wtShift("d{$day}", sprintf('2026-11-%02d 06:00', $day), sprintf('2026-11-%02d 16:30', $day), 30), range(2, 6))]))->toBe(['d6:total_week']);
});

it('adds up overtime over four weeks', function () {
    $shifts = [];

    // Four weeks of 5 × 12,5 h (12 h worked): 60 h a week, 20 h over the ordinary 40, 80 h in four weeks.
    foreach ([0, 7, 14, 21] as $offset) {
        foreach (range(0, 4) as $day) {
            $date = CarbonImmutable::parse('2026-10-05', 'Europe/Stockholm')->addDays($offset + $day)->toDateString();
            $shifts[] = wtShift("{$date}", "{$date} 06:00", "{$date} 18:30", 30);
        }
    }

    $rules = wtRules($shifts, ['only' => ['2026-10-30']]);

    expect($rules)->toContain('2026-10-30:overtime_four_weeks')->toContain('2026-10-30:total_week');
});

it('notes extra time over a part-time week', function () {
    $shifts = array_map(fn (int $day): PlannedShift => wtShift("d{$day}", sprintf('2026-11-%02d 09:00', $day), sprintf('2026-11-%02d 15:00', $day), 30), range(2, 6));

    expect(wtRules($shifts, ['weekly' => '20']))->toBe(['d6:extra_time_week']);
});

it('checks minors only when turned on, by age on the day', function () {
    $evening = [wtShift('evening', '2026-11-03 17:00', '2026-11-03 22:30', 30)];
    $fifteen = CarbonImmutable::parse('2011-06-01');
    $seventeen = CarbonImmutable::parse('2009-06-01');

    expect(wtRules($evening, ['birth' => $fifteen]))->toBe([])
        ->and(wtRules($evening, ['minors' => true, 'birth' => $fifteen]))->toBe(['evening:under_16_night'])
        ->and(wtRules($evening, ['minors' => true, 'birth' => $seventeen]))->toBe(['evening:under_18_night'])
        ->and(wtRules($evening, ['minors' => true, 'birth' => CarbonImmutable::parse('1990-01-01')]))->toBe([])
        ->and(wtRules([wtShift('long', '2026-11-03 08:00', '2026-11-03 17:30', 30)], ['minors' => true, 'birth' => $seventeen]))->toBe(['long:under_18_max_day']);

    // A minor's daily rest is 12 h in the default data.
    expect(wtRules([wtShift('a', '2026-11-02 12:00', '2026-11-02 21:00', 60), wtShift('b', '2026-11-03 08:30', '2026-11-03 12:00')], ['minors' => true, 'birth' => $seventeen]))
        ->toBe(['b:under_18_daily_rest']);
});

it('skips a rule the company has turned off', function () {
    $settings = ['working_time_rules' => ['daily_rest' => ['enabled' => false]]];

    expect(wtRules([wtShift('late', '2026-11-02 16:00', '2026-11-02 23:00', 30), wtShift('early', '2026-11-03 07:00', '2026-11-03 12:00')], ['settings' => $settings]))->toBe([]);
});

it('formats hours in Swedish', function () {
    expect(WorkingTimeChecks::hours(660))->toBe('11 h')
        ->and(WorkingTimeChecks::hours(630))->toBe('10,5 h')
        ->and(WorkingTimeChecks::hours(20))->toBe('0,33 h');
});

it('keeps the weekly extra-time warning when the yearly cap is turned off', function () {
    $shifts = array_map(fn (int $day): PlannedShift => wtShift("d{$day}", sprintf('2026-11-%02d 09:00', $day), sprintf('2026-11-%02d 15:00', $day), 30), range(2, 6));

    expect(wtRules($shifts, ['weekly' => '20', 'settings' => ['working_time_rules' => ['extra_time_year' => ['enabled' => false], 'overtime_year' => ['enabled' => false]]]]))->toBe(['d6:extra_time_week'])
        ->and(wtRules($shifts, ['weekly' => '20', 'settings' => ['working_time_rules' => ['extra_time_week' => ['enabled' => false]]]]))->toBe([])
        ->and(PlanningSettings::WORKING_TIME_RULES['extra_time_year']['checked'] ?? true)->toBeFalse()
        ->and(PlanningSettings::WORKING_TIME_RULES['overtime_year']['checked'] ?? true)->toBeFalse();
});
