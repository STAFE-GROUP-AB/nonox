<?php

declare(strict_types=1);

use App\Modules\Agreements\Domain\AgreementRules;
use App\Modules\Planning\Domain\DayComparison;
use App\Modules\Planning\Domain\OvertimeTerms;
use App\Modules\Planning\Domain\PayrollBasis;
use App\Modules\Planning\Domain\WorkInterval;
use App\Modules\Planning\PlanningSettings;
use Carbon\CarbonImmutable;

/*
 * Overtime and mertid per calculation period (A1, A2; enligt AI-utredning
 * 2026-11-02, juristgranskning återstår): rounding per day or occasion,
 * never on the period total; a daily method for Visita–Unionen and
 * Handelns tjänstemannaavtal; leave on scheduled time counts as worked
 * (arbetstidslagen 7 § andra stycket); at most four weeks without an
 * agreement (5 § andra stycket). Expected minutes worked out by hand.
 */

function overtimeRules(string $file): AgreementRules
{
    $data = json_decode((string) file_get_contents(base_path("app/Modules/Agreements/database/data/agreements/{$file}")), true);

    return new AgreementRules($data, $data['sources'] ?? []);
}

/** A day planned 08:00–17:00 with an hour's break (8 h), worked from 08:00 to $end with an hour's break. */
function overtimeDay(string $date, ?string $end, string $start = '08:00', bool $planned = true): DayComparison
{
    $at = fn (string $time): CarbonImmutable => CarbonImmutable::parse("{$date} {$time}", 'Europe/Stockholm');
    $plan = $planned ? [new WorkInterval($at('08:00'), $at('17:00'), 60)] : [];
    $actual = $end === null ? [] : [new WorkInterval($at($start), $at($end), 60)];

    return new DayComparison(CarbonImmutable::parse($date, 'Europe/Stockholm'), $plan, $actual, DayComparison::SOURCE_MANUAL, []);
}

/** @return list<DayComparison> a week Monday 2026-11-09 to Friday, every day as planned unless given */
function overtimeWeek(array $ends = []): array
{
    $days = [];

    for ($i = 0; $i < 5; $i++) {
        $date = CarbonImmutable::parse('2026-11-09')->addDays($i)->toDateString();
        $days[] = overtimeDay($date, array_key_exists($date, $ends) ? $ends[$date] : '17:00');
    }

    return $days;
}

it('rounds overtime per day under Visita–Unionen: three days of 20 minutes are 90 minutes, not 60', function () {
    $terms = OvertimeTerms::from(overtimeRules('visita-unionen/MEMBER-2025-06-01.json'));
    $days = overtimeWeek(['2026-11-09' => '17:20', '2026-11-10' => '17:20', '2026-11-11' => '17:20']);

    $week = PayrollBasis::period($days, [], $terms, 2400, 2400, 'VU_OT_94', null, false);

    expect($terms->method)->toBe(OvertimeTerms::DAILY)
        ->and($week->overtimeMinutes)->toBe(90)
        ->and($week->minutes)->toBe(['VU_OT_94' => 90]);
});

it('counts only whole half hours per day under Handelns tjänstemannaavtal', function () {
    $terms = OvertimeTerms::from(overtimeRules('svenskhandel-unionen-tjansteman/MEMBER-2025-05-01.json'));
    // 50 min Monday (30), 20 min Tuesday (0), 65 min Wednesday (60): 90, where the period total of 135 would give 120.
    $days = overtimeWeek(['2026-11-09' => '17:50', '2026-11-10' => '17:20', '2026-11-11' => '18:05']);

    expect(PayrollBasis::period($days, [], $terms, 2400, 2400, 'HT_OT_94', null, false)->overtimeMinutes)->toBe(90);
});

it('does not net a short day against a long one under a daily method, but does under a period method', function () {
    $days = overtimeWeek(['2026-11-09' => '18:00', '2026-11-10' => '16:00']);

    $daily = PayrollBasis::period($days, [], OvertimeTerms::from(overtimeRules('visita-unionen/MEMBER-2025-06-01.json')), 2400, 2400, 'VU_OT_94', null, false);
    $period = PayrollBasis::period($days, [], OvertimeTerms::from(null), 2400, 2400, 'OVERTID', null, false);

    expect($daily->overtimeMinutes)->toBe(60)
        ->and($period->overtimeMinutes)->toBe(0);
});

it('leaves out an extension of at most ten minutes and rounds each occasion up under Gröna Riks', function () {
    $terms = OvertimeTerms::from(overtimeRules('visita-hrf-grona-riks/MEMBER-2025-04-01.json'));
    // 8 min Monday (not overtime), 20 min Tuesday (30), 40 min Wednesday (60).
    $days = overtimeWeek(['2026-11-09' => '17:08', '2026-11-10' => '17:20', '2026-11-11' => '17:40']);

    $week = PayrollBasis::period($days, [], $terms, 2400, 2400, 'GR_OT_A', null, false);

    expect($terms->per)->toBe(OvertimeTerms::PER_OCCASION)
        ->and($terms->thresholdMinutes)->toBe(10)
        ->and($week->overtimeMinutes)->toBe(90)
        ->and(collect($week->deviations)->pluck('message')->implode(' '))->toContain('8 min förlängning räknas inte som övertid: högst 10 min per tillfälle (GR#§ 12 p. 1.2 anm. s. 19).');
});

it('splits a day into occasions outside the plan and counts each one', function () {
    $at = fn (string $time): CarbonImmutable => CarbonImmutable::parse("2026-11-09 {$time}", 'Europe/Stockholm');
    // Planned 08–17 with an hour's break; worked 07:40–17:25 with the same break: 20 min before, 25 min after.
    $day = new DayComparison($at('00:00'), [new WorkInterval($at('08:00'), $at('17:00'), 60)], [new WorkInterval($at('07:40'), $at('17:25'), 60)], DayComparison::SOURCE_MANUAL, []);

    expect(OvertimeTerms::occasions($day))->toBe([20, 25])
        ->and(OvertimeTerms::from(overtimeRules('visita-hrf-grona-riks/MEMBER-2025-04-01.json'))->day($day))->toBe(['raw' => 45, 'counted' => 60, 'ignored' => 0])
        ->and(OvertimeTerms::from(overtimeRules('visita-unionen/MEMBER-2025-06-01.json'))->day($day))->toBe(['raw' => 45, 'counted' => 60, 'ignored' => 0]);
});

it('counts leave on scheduled time as worked when measuring overtime over a period', function () {
    // A vacation day on Monday and a two-hour longer Friday: 32 h worked, but 120 min overtime (7 § andra stycket).
    $days = overtimeWeek(['2026-11-09' => null, '2026-11-13' => '19:00']);

    $without = PayrollBasis::period($days, [], OvertimeTerms::from(null), 2400, 2400, 'OVERTID', null, false);
    $with = PayrollBasis::period($days, ['2026-11-09' => true], OvertimeTerms::from(null), 2400, 2400, 'OVERTID', null, false);

    expect($without->overtimeMinutes)->toBe(0)
        ->and($with->overtimeMinutes)->toBe(120)
        ->and(collect($with->deviations)->pluck('message')->implode(' '))->toContain('480 min ledighet på schemalagd tid räknas som arbetad tid');
});

it('caps the calculation period at four weeks when no agreement binds the employment', function () {
    $settings = PlanningSettings::resolve(['overtime_period_weeks' => 6]);

    expect(PlanningSettings::periodWeeks($settings, false))->toBe(4)
        ->and(PlanningSettings::periodWeeks($settings, true))->toBe(6)
        ->and(PlanningSettings::periodWeeks(PlanningSettings::resolve(['overtime_period_weeks' => 2]), false))->toBe(2);
});

it('reads the agreement\'s overtime facts: period limits by salary form, method and hourly supplements', function () {
    $gr = overtimeRules('visita-hrf-grona-riks/MEMBER-2025-04-01.json');
    $vu = overtimeRules('visita-unionen/MEMBER-2025-06-01.json');

    expect($gr->calculationPeriod('MONTHLY')['max_weeks'])->toBe(16)
        ->and($gr->calculationPeriod('HOURLY')['max_weeks'])->toBe(8)
        ->and($gr->overtimeMethod()['method'])->toBe('PERIOD')
        ->and($vu->overtimeMethod()['method'])->toBe('DAILY')
        ->and($gr->hourlySupplement('GR_OT_A')['code'] ?? null)->toBe('GR_OT_A_TL')
        ->and($gr->hasHourlySupplements())->toBeTrue()
        ->and($vu->hasHourlySupplements())->toBeFalse()
        ->and($vu->code('VU_OT_94')['vacation_basis'] ?? null)->toBeFalse()
        ->and($gr->code('GR_OT_B')['vacation_basis'] ?? null)->toBeTrue()
        ->and(overtimeRules('svenskhandel-handels-detaljhandel/MEMBER-2025-04-01.json')->code('DH_MT_A')['vacation_basis'] ?? null)->toBeTrue()
        ->and(overtimeRules('svenskhandel-unionen-tjansteman/MEMBER-2025-05-01.json')->code('HT_OT_72')['vacation_basis'] ?? null)->toBeFalse();
});
