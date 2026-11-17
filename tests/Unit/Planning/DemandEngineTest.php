<?php

declare(strict_types=1);

use App\Modules\Planning\Domain\Demand\DayBuckets;
use App\Modules\Planning\Domain\Demand\DayDemand;
use App\Modules\Planning\Domain\Demand\Forecast;
use App\Modules\Planning\Domain\Demand\Forecaster;
use App\Modules\Planning\Domain\Demand\HourShape;
use App\Modules\Planning\Domain\Demand\StaffingNeed;
use App\Modules\Planning\Models\StaffingRule;
use Carbon\CarbonImmutable;

/*
 * Bemanningsbehov (ADR-037 amendment, gap 1): the deterministic forecast,
 * the shape of the day, local-time hour buckets over summer time, and the
 * required staff per hour and per day. No database.
 */

function demandDay(string $date): CarbonImmutable
{
    return CarbonImmutable::parse($date, 'Europe/Stockholm');
}

/** @param  array<string, mixed>  $attributes */
function demandRule(array $attributes): StaffingRule
{
    static $id = 0;
    $rule = new StaffingRule;
    $rule->forceFill(['id' => ++$id, 'area_id' => null, 'weekday' => null, 'sales_per_staff_hour_ore' => null, 'min_staff' => null, 'valid_from' => '2026-01-01', ...$attributes]);

    return $rule;
}

/** @return array{CarbonImmutable, CarbonImmutable} */
function demandShift(string $from, string $to): array
{
    return [demandDay($from), demandDay($to)];
}

it('averages the same weekday over the last weeks, skipping holidays, eves and days without sales', function () {
    // Tuesday 17 November 2026; today is Wednesday 18 November.
    $sales = [
        '2026-11-10' => 1_000_000,
        '2026-11-03' => 1_200_000,
        // 27 October has no sales: skipped, not replaced.
        '2026-10-20' => 800_000,
        '2026-10-13' => 1_000_001,
        '2026-10-06' => 999_999,
        // Seven weeks back: outside six weeks.
        '2026-09-29' => 9_000_000,
    ];

    $forecast = Forecaster::forDay(demandDay('2026-11-17'), $sales, [], 6, demandDay('2026-11-18'));

    expect($forecast->kind)->toBe(Forecast::AVERAGE)
        ->and($forecast->basis)->toBe(['2026-11-10', '2026-11-03', '2026-10-20', '2026-10-13', '2026-10-06'])
        ->and($forecast->amountOre)->toBe(1_000_000);

    // Julafton and nyårsafton 2026 are Thursdays: nyårsafton is forecast as a holiday, and the Thursday after skips both eves.
    $thursdays = ['2026-12-24' => 5_000_000, '2026-12-17' => 300_000, '2026-12-10' => 100_000];
    $after = Forecaster::forDay(demandDay('2026-12-31'), $thursdays, [], 3, demandDay('2026-12-28'));
    // 31 December is nyårsafton itself: a holiday forecast, not an average.
    expect($after->kind)->toBe(Forecast::NONE)->and($after->holiday)->toBe('Nyårsafton');

    $plain = Forecaster::forDay(demandDay('2027-01-07'), [...$thursdays, '2026-12-31' => 9_000_000], [], 4, demandDay('2027-01-05'));
    expect($plain->basis)->toBe(['2026-12-17', '2026-12-10'])
        ->and($plain->amountOre)->toBe(200_000);
});

it('starts a day weeks ahead from the newest same weekday before today', function () {
    $sales = ['2026-11-10' => 600_000, '2026-11-03' => 400_000, '2026-11-17' => 9_999_999];

    // Today is Tuesday 17 November: today's sales are not complete and are not used.
    $forecast = Forecaster::forDay(demandDay('2026-12-08'), $sales, [], 2, demandDay('2026-11-17'));

    expect($forecast->basis)->toBe(['2026-11-10', '2026-11-03'])
        ->and($forecast->amountOre)->toBe(500_000)
        ->and(Forecaster::lookbackFrom(demandDay('2026-12-08'), demandDay('2026-11-17'), 2)->toDateString())->toBe('2026-10-27');
});

it('forecasts a holiday from the same holiday last year, or not at all', function () {
    // Midsommarafton: 19 June 2026, 20 June 2025.
    $with = Forecaster::forDay(demandDay('2026-06-19'), ['2025-06-20' => 7_500_000, '2026-06-12' => 1_000_000], [], 6, demandDay('2026-06-01'));
    $without = Forecaster::forDay(demandDay('2026-06-19'), ['2026-06-12' => 1_000_000], [], 6, demandDay('2026-06-01'));

    expect($with->kind)->toBe(Forecast::HOLIDAY)
        ->and($with->amountOre)->toBe(7_500_000)
        ->and($with->basis)->toBe(['2025-06-20'])
        ->and($with->holiday)->toBe('Midsommarafton')
        ->and($without->kind)->toBe(Forecast::NONE)
        ->and($without->amountOre)->toBeNull()
        ->and(Forecaster::lastYearHolidays([demandDay('2026-06-19'), demandDay('2026-06-18')]))->toBe(['2025-06-20']);
});

it('lets an override replace the figure and keeps the computed one', function () {
    $forecast = Forecaster::forDay(demandDay('2026-11-20'), ['2026-11-13' => 2_000_000], ['2026-11-20' => ['amount' => 5_000_000, 'reason' => 'Konsert']], 6, demandDay('2026-11-18'));

    expect($forecast->isOverride())->toBeTrue()
        ->and($forecast->amountOre)->toBe(5_000_000)
        ->and($forecast->computedOre)->toBe(2_000_000)
        ->and($forecast->basis)->toBe(['2026-11-13'])
        ->and($forecast->reason)->toBe('Konsert');
});

it('buckets the summer-time days in local time', function () {
    $spring = DayBuckets::of(demandDay('2026-03-29'));
    $autumn = DayBuckets::of(demandDay('2026-10-25'));
    $normal = DayBuckets::of(demandDay('2026-11-17'));

    expect(count($spring))->toBe(23)
        ->and(array_key_exists(2, $spring))->toBeFalse()
        ->and(array_sum(DayBuckets::lengths($spring)))->toBe(23 * 60)
        ->and(DayBuckets::lengths($autumn)[2])->toBe(120)
        ->and(array_sum(DayBuckets::lengths($autumn)))->toBe(25 * 60)
        ->and(array_sum(DayBuckets::lengths($normal)))->toBe(24 * 60);

    // 01:30–03:30 on the autumn night covers 30 min of 01, both 02 hours and 30 min of 03.
    $from = CarbonImmutable::parse('2026-10-24 23:30:00', 'UTC');
    $to = CarbonImmutable::parse('2026-10-25 02:30:00', 'UTC');
    expect(DayBuckets::overlap($autumn, $from, $to))->toBe([1 => 30, 2 => 120, 3 => 30]);
});

it('splits a day over the hours by largest remainder', function () {
    $shape = HourShape::fromProfile([...array_fill(0, 11, 0), 3333, 3333, 3334, ...array_fill(0, 10, 0)]);

    $split = $shape?->split(100, range(0, 23));

    expect(array_sum((array) $split))->toBe(100)
        ->and($split[11] + $split[12] + $split[13])->toBe(100)
        ->and($split[10])->toBe(0)
        ->and(HourShape::fromProfile(array_fill(0, 24, 0)))->toBeNull()
        ->and(HourShape::fromTill(['2026-11-10' => [12 => -500]], ['2026-11-10']))->toBeNull();
});

it('requires max(minimum staff, ceil(hour sales ÷ target)) per hour and warns under and over', function () {
    // 30 000 kr over 11–14 in equal thirds: 10 000 kr per hour at 3 000 kr per staffed hour = 4 people, at least 2.
    $shape = HourShape::fromProfile([...array_fill(0, 11, 0), 3333, 3333, 3334, ...array_fill(0, 10, 0)]);
    $forecast = new Forecast('2026-11-17', 3_000_000, Forecast::AVERAGE, ['2026-11-10']);
    $rules = [demandRule(['start_minute' => 660, 'end_minute' => 840, 'sales_per_staff_hour_ore' => 300_000, 'min_staff' => 2])];
    $staffed = [
        demandShift('2026-11-17 10:00', '2026-11-17 15:00'),
        demandShift('2026-11-17 10:00', '2026-11-17 15:00'),
        demandShift('2026-11-17 11:00', '2026-11-17 14:00'),
        demandShift('2026-11-17 12:30', '2026-11-17 14:00'),
    ];

    $day = StaffingNeed::day(demandDay('2026-11-17'), $forecast, $shape, $rules, $staffed, [demandShift('2026-11-17 13:00', '2026-11-17 14:00')]);

    expect($day->shape)->toBe(HourShape::PROFILE)
        ->and($day->hours[11])->toMatchArray(['sales' => 999_900, 'need' => 4, 'planned' => 3.0, 'status' => DayDemand::UNDER])
        ->and($day->hours[12])->toMatchArray(['need' => 4, 'planned' => 3.5, 'status' => DayDemand::UNDER])
        ->and($day->hours[13])->toMatchArray(['need' => 4, 'planned' => 4.0, 'open' => 1.0, 'status' => DayDemand::OK])
        ->and($day->hours[10])->toMatchArray(['need' => null, 'planned' => 2.0, 'status' => DayDemand::NONE])
        ->and($day->needMinutes)->toBe(12 * 60)
        ->and($day->status)->toBe(DayDemand::UNDER)
        ->and(DayDemand::ranges($day->underHours()))->toBe(['11–13']);

    // Six people at 11–14 where the sales need four: over-staffed.
    $many = array_fill(0, 6, demandShift('2026-11-17 11:00', '2026-11-17 14:00'));
    $over = StaffingNeed::day(demandDay('2026-11-17'), $forecast, $shape, $rules, $many, []);

    expect($over->status)->toBe(DayDemand::OVER)
        ->and($over->overHours())->toBe([11, 12, 13]);

    // A minimum staff alone is a floor, never a ceiling.
    $floor = [demandRule(['start_minute' => 660, 'end_minute' => 840, 'min_staff' => 2])];
    $floorOnly = StaffingNeed::day(demandDay('2026-11-17'), $forecast, $shape, $floor, $many, []);
    expect($floorOnly->status)->toBe(DayDemand::OK)->and($floorOnly->overHours())->toBe([]);
});

it('judges the day by the targeted hours only, not by time outside every target', function () {
    // Lunch 11–14 and dinner 17–22, at least two and 1 000 kr per staffed hour; 8 000 kr spread evenly over those 8 hours.
    $rules = [
        demandRule(['start_minute' => 660, 'end_minute' => 840, 'sales_per_staff_hour_ore' => 100_000, 'min_staff' => 2]),
        demandRule(['start_minute' => 1020, 'end_minute' => 1320, 'sales_per_staff_hour_ore' => 100_000, 'min_staff' => 2]),
    ];
    $profile = array_fill(0, 24, 0);
    foreach ([11, 12, 13, 17, 18, 19, 20, 21] as $hour) {
        $profile[$hour] = 1250;
    }
    $forecast = new Forecast('2026-11-17', 800_000, Forecast::AVERAGE, ['2026-11-10']);
    // Two people 10–23: thirteen hours each, exactly two in every targeted hour.
    $two = array_fill(0, 2, demandShift('2026-11-17 10:00', '2026-11-17 23:00'));

    $hourly = StaffingNeed::day(demandDay('2026-11-17'), $forecast, HourShape::fromProfile($profile), $rules, $two, []);
    $daily = StaffingNeed::day(demandDay('2026-11-17'), $forecast, null, $rules, $two, []);

    expect($hourly->status)->toBe(DayDemand::OK)
        ->and($hourly->needMinutes)->toBe(960)
        ->and($hourly->plannedMinutes)->toBe(960)
        ->and($hourly->overHours())->toBe([])
        ->and($hourly->hours[10]['status'])->toBe(DayDemand::NONE)
        ->and($daily->status)->toBe(DayDemand::OK)
        ->and($daily->needMinutes)->toBe(960)
        ->and($daily->plannedMinutes)->toBe(960);
});

it('shows no hourly figure per day only when a target has no minimum staff', function () {
    $rules = [demandRule(['start_minute' => 660, 'end_minute' => 840, 'sales_per_staff_hour_ore' => 100_000])];
    $forecast = new Forecast('2026-11-17', 600_000, Forecast::AVERAGE, ['2026-11-10']);
    $two = array_fill(0, 2, demandShift('2026-11-17 11:00', '2026-11-17 14:00'));

    $day = StaffingNeed::day(demandDay('2026-11-17'), $forecast, null, $rules, $two, []);

    // 6 000 kr ÷ 1 000 kr = 6 staff-hours against 6 planned in the window: staffed as needed, no "2/0".
    expect($day->hours[12])->toMatchArray(['need' => null, 'status' => DayDemand::NONE])
        ->and($day->overHours())->toBe([])
        ->and($day->needMinutes)->toBe(360)
        ->and($day->plannedMinutes)->toBe(360)
        ->and($day->status)->toBe(DayDemand::OK);

    // A third person for the whole window is a staff-hour more than needed: over-staffed by the day's target.
    $three = StaffingNeed::day(demandDay('2026-11-17'), $forecast, null, $rules, [...$two, demandShift('2026-11-17 11:00', '2026-11-17 14:00')], []);
    expect($three->status)->toBe(DayDemand::OVER)->and($three->overHours())->toBe([]);
});

it('falls back to staff-hours per day when the sales have no shape', function () {
    $forecast = new Forecast('2026-11-17', 1_000_000, Forecast::AVERAGE, ['2026-11-10']);
    $rules = [
        demandRule(['start_minute' => 600, 'end_minute' => 1200, 'sales_per_staff_hour_ore' => 100_000, 'min_staff' => 1]),
    ];

    $day = StaffingNeed::day(demandDay('2026-11-17'), $forecast, null, $rules, [demandShift('2026-11-17 10:00', '2026-11-17 18:00')], []);

    // ceil(10 000 kr ÷ 1 000 kr) = 10 staff-hours, the same as the floor's 10 hours × 1.
    expect($day->shape)->toBeNull()
        ->and($day->hours[12]['sales'])->toBeNull()
        ->and($day->hours[12]['need'])->toBe(1)
        ->and($day->needMinutes)->toBe(600)
        ->and($day->plannedMinutes)->toBe(480)
        ->and($day->status)->toBe(DayDemand::UNDER);

    // 3 000 kr needs 3 staff-hours by the target; the floor keeps the day at 10.
    $small = StaffingNeed::day(demandDay('2026-11-17'), new Forecast('2026-11-17', 300_000, Forecast::AVERAGE, []), null, $rules, [], []);
    expect($small->needMinutes)->toBe(600);
});

it('uses the newest rule for an hour, a weekday rule before an every-day rule, and none before valid-from', function () {
    $rules = [
        demandRule(['start_minute' => 0, 'end_minute' => 1440, 'min_staff' => 1, 'valid_from' => '2026-01-01']),
        demandRule(['weekday' => 2, 'start_minute' => 0, 'end_minute' => 1440, 'min_staff' => 3, 'valid_from' => '2026-01-01']),
        demandRule(['start_minute' => 720, 'end_minute' => 780, 'min_staff' => 5, 'valid_from' => '2026-11-01']),
        demandRule(['start_minute' => 0, 'end_minute' => 1440, 'min_staff' => 9, 'valid_from' => '2027-01-01']),
    ];
    $forecast = new Forecast('2026-11-17', null, Forecast::NONE, []);

    $tuesday = StaffingNeed::day(demandDay('2026-11-17'), $forecast, null, $rules, [], []);
    $wednesday = StaffingNeed::day(demandDay('2026-11-18'), $forecast, null, $rules, [], []);

    expect($tuesday->hours[8]['need'])->toBe(3)
        ->and($tuesday->hours[12]['need'])->toBe(5)
        ->and($wednesday->hours[8]['need'])->toBe(1);
});
