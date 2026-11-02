<?php

declare(strict_types=1);

use App\Modules\Payroll\Domain\Engine\AbsenceInput;
use App\Modules\Payroll\Domain\Engine\LongSickTerms;
use App\Modules\Payroll\Domain\Engine\PayrollContext;
use App\Modules\Payroll\Domain\Engine\PayslipDraft;
use App\Modules\Payroll\Domain\Engine\PayslipLineResult;
use App\Modules\Payroll\Domain\Engine\SickPayTerms;
use App\Modules\Payroll\Domain\Engine\Steps\SickPay;
use App\Modules\Payroll\Domain\Rules\RuleSet;
use App\Modules\Payroll\Models\PayrollRuleVersion;
use Carbon\CarbonImmutable;

/*
 * A8 in the payroll engine: the SickPay step with SickPayTerms from another
 * module. Lost OB on scheduled shifts is paid at the sick-pay percentage,
 * the karensavdrag's average week includes the average OB, irregular hours
 * use the schedule's average week, and an agreement's per-calendar-day
 * deduction replaces the hourly one after the employer's period. Without
 * terms the step is unchanged. Rule values are test data.
 */

beforeEach(function () {
    foreach ([
        'sick_pay.percentage' => ['bp' => 8000],
        'sick_pay.karensavdrag_percent_of_weekly' => ['bp' => 2000, 'max_occasions_per_12_months' => 10],
        'sick_pay.employer_period_days' => ['days' => 14],
        'sick_pay.reinfection_window_days' => ['days' => 5],
    ] as $key => $value) {
        PayrollRuleVersion::create(['key' => $key, 'valid_from' => '2026-01-01', 'value' => $value, 'source_url' => 'https://example.test'])
            ->forceFill(['verified_by' => 'Testperson', 'verified_at' => now()])->save();
    }
});

/**
 * @param  list<AbsenceInput>  $absences
 */
function sickContext(array $absences, ?SickPayTerms $terms = null, string $form = 'MONTHLY', ?string $weeklyHours = '40'): PayrollContext
{
    return new PayrollContext(
        birthDate: CarbonImmutable::parse('1990-06-15'),
        taxResidence: 'SE',
        taxTable: 33,
        taxColumn: 1,
        taxAdjustmentPercentBp: null,
        salaryForm: $form,
        monthlySalaryOre: $form === 'MONTHLY' ? 3_000_000 : null,
        hourlyRateOre: $form === 'HOURLY' ? 20_000 : null,
        employmentRateBp: 10000,
        employmentStart: CarbonImmutable::parse('2024-01-01'),
        employmentEnd: null,
        periodStart: CarbonImmutable::parse('2026-04-01'),
        periodEnd: CarbonImmutable::parse('2026-04-30'),
        paymentDate: CarbonImmutable::parse('2026-04-25'),
        inputs: [],
        accumulators: [],
        settings: [],
        rules: RuleSet::for(CarbonImmutable::parse('2026-04-25')),
        weeklyHours: $weeklyHours,
        absences: $absences,
        sickPayTerms: $terms,
    );
}

/** @return array<string, list<PayslipLineResult>> */
function sickLines(PayrollContext $context): array
{
    $draft = new PayslipDraft;
    (new SickPay)->apply($context, $draft);
    $lines = [];

    foreach ($draft->lines as $line) {
        $lines[$line->code][] = $line;
    }

    return $lines;
}

/** @return list<AbsenceInput> three sick weekdays, 8 h each */
function threeSickDays(int $supplementsOre = 0): array
{
    return array_map(fn (string $date): AbsenceInput => new AbsenceInput('SICK', CarbonImmutable::parse($date), '8', $supplementsOre, $supplementsOre > 0 ? ['GR_OB' => ['minutes' => 240, 'ore' => $supplementsOre]] : []), ['2026-04-07', '2026-04-08', '2026-04-09']);
}

it('is unchanged without terms', function () {
    $lines = sickLines(sickContext(threeSickDays()));

    // Hour value 30 000 × 12 / 52 / 40 = 173,08 kr. Sick pay 80 % of 24 h; karens 20 % of 80 % of a week of 6 923,08 kr.
    expect(array_keys($lines))->toBe(['SJUKAVDRAG', 'SJUKLON', 'KARENSAVDRAG'])
        ->and($lines['SJUKLON'][0]->amountOre)->toBe(332_308)
        ->and($lines['KARENSAVDRAG'][0]->amountOre)->toBe(-110_769);
});

it('pays the lost OB on the schedule at the sick-pay percentage and counts the average OB in the karensavdrag', function () {
    $terms = new SickPayTerms(averageWeeklySupplementsOre: 20_000, explanation: ['agreement' => 'Gröna Riks']);
    $lines = sickLines(sickContext(threeSickDays(10_000), $terms));

    // 3 × 100 kr OB × 80 % = 240 kr. Karens: 20 % × 80 % × (6 923,08 + 200) kr = 1 139,69 kr.
    expect($lines['SJUKLON'])->toHaveCount(2)
        ->and($lines['SJUKLON'][1]->amountOre)->toBe(24_000)
        ->and($lines['SJUKLON'][1]->description)->toBe('Sjuklön 80 % av OB enligt schemat')
        ->and($lines['SJUKLON'][1]->explanation['days'])->toHaveKey('2026-04-07')
        ->and($lines['SJUKLON'][1]->explanation['agreement'])->toBe('Gröna Riks')
        ->and($lines['KARENSAVDRAG'][0]->amountOre)->toBe(-113_969)
        ->and($lines['KARENSAVDRAG'][0]->explanation['average_weekly_supplements_ore'])->toBe(20_000);
});

it('uses the schedule\'s average week for irregular hours without weekly hours', function () {
    $day = [new AbsenceInput('SICK', CarbonImmutable::parse('2026-04-07'), '8')];
    $lines = sickLines(sickContext($day, new SickPayTerms(averageWeeklyHours: '30'), 'HOURLY', null));

    // 8 h × 200 kr × 80 % = 1 280 kr; karens 20 % × 80 % × 30 h × 200 kr = 960 kr.
    expect($lines['SJUKLON'][0]->amountOre)->toBe(128_000)
        ->and($lines['KARENSAVDRAG'][0]->amountOre)->toBe(-96_000)
        ->and($lines['KARENSAVDRAG'][0]->explanation['average_weekly_hours'])->toBe('30');
});

it('deducts per calendar day from day 15 by the agreement instead of per hour', function () {
    $days = [];

    for ($day = CarbonImmutable::parse('2026-04-01'); $day->lessThanOrEqualTo(CarbonImmutable::parse('2026-04-20')); $day = $day->addDay()) {
        $days[] = new AbsenceInput('SICK', $day, $day->isWeekend() ? '0' : '8');
    }

    $terms = new SickPayTerms(longSick: new LongSickTerms(15, 90, 9000, 1000, 59_200_000, 'VU#§ 23 s. 46–48', 365));
    $lines = sickLines(sickContext($days, $terms));

    // 360 000 kr a year is under 10 prisbasbelopp (592 000 kr): 90 % × 360 000 / 365 = 887,67 kr per calendar day, days 15–20.
    $long = collect($lines['SJUKAVDRAG'])->firstWhere('description', 'Sjukavdrag dag 15–90 enligt avtal, 6 kalenderdagar');
    // The hourly deduction covers days 1–14 only: 10 weekdays × 8 h.
    $hourly = $lines['SJUKAVDRAG'][0];

    expect($long)->not->toBeNull()
        ->and($long->amountOre)->toBe(-6 * 88_767)
        ->and($hourly->explanation['hours'])->toBe('80')
        ->and($lines['SJUKLON'][0]->explanation['hours'])->toBe('80');
});

it('caps the calendar-day deduction at a day\'s salary and takes only the share above the threshold at the higher rate', function () {
    $days = [];

    for ($day = CarbonImmutable::parse('2026-04-01'); $day->lessThanOrEqualTo(CarbonImmutable::parse('2026-04-15')); $day = $day->addDay()) {
        $days[] = new AbsenceInput('SICK', $day, $day->isWeekend() ? '0' : '8');
    }

    // Threshold 240 000 kr: 90 % × 240 000 / 365 + 10 % × 120 000 / 365 = 591,78 + 32,88 = 624,66 kr.
    $lines = sickLines(sickContext($days, new SickPayTerms(longSick: new LongSickTerms(15, 90, 9000, 1000, 24_000_000, 'test', 365))));
    $long = collect($lines['SJUKAVDRAG'])->firstWhere('explanation.days', 1);

    expect($long->amountOre)->toBe(-62_466);
});
