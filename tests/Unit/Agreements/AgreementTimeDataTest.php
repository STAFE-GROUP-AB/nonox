<?php

declare(strict_types=1);

use App\Modules\Agreements\Domain\AgreementFileValidator;
use App\Modules\Agreements\Domain\AgreementRules;
use App\Modules\Agreements\Domain\TimeRounding;

/*
 * The agreement data added for A5–A8 (docs/avtal/datamodell.md §7):
 * rounding, comp-time factors, the calculation period and sick pay, as the
 * importer checks them and the engine reads them.
 */

/** @return array<string, mixed> the shipped Visita–Unionen file */
function visitaUnionen(): array
{
    return json_decode((string) file_get_contents(base_path('app/Modules/Agreements/database/data/agreements/visita-unionen/MEMBER-2025-06-01.json')), true);
}

it('rounds up per started unit or down to whole units', function (int $minutes, string $method, int $expected) {
    expect(TimeRounding::round($minutes, ['unit_minutes' => 30, 'method' => $method, 'source' => 'test']))->toBe($expected);
})->with([
    'a started half hour' => [10, 'UP_PER_STARTED_UNIT', 30],
    'exactly a half hour' => [30, 'UP_PER_STARTED_UNIT', 30],
    'just over' => [31, 'UP_PER_STARTED_UNIT', 60],
    'only whole half hours' => [59, 'DOWN_TO_WHOLE_UNIT', 30],
    'less than one' => [29, 'DOWN_TO_WHOLE_UNIT', 0],
]);

it('leaves time as it is without a rule', function () {
    expect(TimeRounding::round(17, null))->toBe(17)
        ->and(TimeRounding::round(-5, null))->toBe(0);
});

it('reads rounding, comp time, the calculation period and sick pay from the data', function () {
    $file = visitaUnionen();
    $rules = new AgreementRules($file, $file['sources']);

    expect($rules->rounding('OB'))->toBe(['unit_minutes' => 30, 'method' => 'UP_PER_STARTED_UNIT', 'source' => 'VU#§ 21 s. 41'])
        ->and($rules->compTime()['hours_per_hour'])->toBe(['VU_OT_94' => '1.5', 'VU_OT_72' => '2'])
        ->and($rules->calculationPeriod())->toBe(['max_weeks' => 26, 'source' => 'VU#§ 10 s. 20'])
        ->and($rules->obInSickPay())->toBeTrue()
        ->and($rules->sickPay()['after_employer_period']['duration'][0])->toBe(['min_employment_months' => 12, 'or_direct_transfer' => true, 'to_day' => 90, 'max_days_12_months' => 105])
        ->and($rules->sickPay()['hour_value'])->toBe(['method' => 'MONTHLY_PER_WEEK', 'months_per_year' => '12', 'weeks_per_year' => '52', 'source' => 'VU#§ 23.3 s. 45–47'])
        ->and($rules->sickPay()['karens']['weekly_hours'])->toBe('AVERAGE');
});

it('refuses A5–A8 data in the wrong shape or without a source', function (array $change, string $problem) {
    $file = array_replace_recursive(visitaUnionen(), $change);

    expect(app(AgreementFileValidator::class)->validate($file, 'visita-unionen'))->toContain($problem);
})->with([
    'unknown rounding method' => [['rounding' => ['ob' => ['method' => 'NEAREST']]], 'Avrundningen ob har fel form (unit_minutes 2–60, method UP_PER_STARTED_UNIT eller DOWN_TO_WHOLE_UNIT, per SHIFT).'],
    'rounding without source' => [['rounding' => ['overtime' => ['source' => '']]], 'rounding.overtime saknar källa.'],
    'comp time for a code that does not exist' => [['overtime' => ['comp_time' => ['hours_per_hour' => ['VU_OT_99' => '1.5']]]], 'Komptiden för VU_OT_99 pekar på en kod som inte finns eller har en faktor i fel form.'],
    'comp time factor as a number' => [['overtime' => ['comp_time' => ['hours_per_hour' => ['VU_OT_94' => 1.5]]]], 'Komptiden för VU_OT_94 pekar på en kod som inte finns eller har en faktor i fel form.'],
    'calculation period of zero weeks' => [['working_time' => ['calculation_period' => ['max_weeks' => 0]]], 'Beräkningsperioden måste ha max_weeks som ett heltal 1–53.'],
    'long sickness from day 10' => [['sick_pay' => ['after_employer_period' => ['from_day' => 10]]], 'sick_pay.after_employer_period har fel form (from_day, deduction med per CALENDAR_DAY, days_per_year, below_bp, above_bp, threshold_prisbasbelopp, och duration med to_day).'],
    'sick pay with a source that is not listed' => [['sick_pay' => ['source' => 'XX#s. 1']], 'sick_pay hänvisar till en källa som inte finns i listan: XX#s. 1.'],
    'unknown hour value method' => [['sick_pay' => ['hour_value' => ['method' => 'GUESS']]], 'sick_pay.hour_value har fel form (method MONTHLY_PER_WEEK med months_per_year och weeks_per_year, MONTH_WORKING_HOURS, eller DAY_PERCENT med day_bp och hour_divisor).'],
    'hour value months as a number' => [['sick_pay' => ['hour_value' => ['months_per_year' => 12.2]]], 'sick_pay.hour_value har fel form (method MONTHLY_PER_WEEK med months_per_year och weeks_per_year, MONTH_WORKING_HOURS, eller DAY_PERCENT med day_bp och hour_divisor).'],
    'karens in days' => [['sick_pay' => ['karens' => ['unit' => 'DAYS']]], 'sick_pay.karens har fel form (unit HOURS, percent_of_weekly_hours_bp och weekly_hours AVERAGE eller AGREED).'],
    'karens without source' => [['sick_pay' => ['karens' => ['source' => '']]], 'sick_pay.karens saknar källa.'],
    'ceiling of zero days' => [['sick_pay' => ['after_employer_period' => ['duration' => [['max_days_12_months' => 0]]]]], 'sick_pay.after_employer_period har fel form (from_day, deduction med per CALENDAR_DAY, days_per_year, below_bp, above_bp, threshold_prisbasbelopp, och duration med to_day).'],
    'unknown part of the monthly salary' => [['sick_pay' => ['after_employer_period' => ['monthly_salary_includes' => ['TIPS']]]], 'sick_pay.after_employer_period.monthly_salary_includes innehåller okända värden.'],
    'unknown variable pay code' => [['sick_pay' => ['after_employer_period' => ['variable_pay_codes' => ['DRICKS']]]], 'sick_pay.after_employer_period.variable_pay_codes innehåller okända värden.'],
]);

it('accepts every shipped file with the new sections', function () {
    $validator = app(AgreementFileValidator::class);

    foreach (glob(base_path('app/Modules/Agreements/database/data/agreements/*/*.json')) ?: [] as $path) {
        expect($validator->validate(json_decode((string) file_get_contents($path), true), basename(dirname($path))))->toBe([], $path);
    }
});
