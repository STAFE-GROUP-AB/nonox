<?php

declare(strict_types=1);

use App\Modules\Agreements\Domain\AgreementFileValidator;
use App\Modules\Agreements\Domain\AgreementRules;
use App\Modules\Planning\Domain\DayComparison;
use App\Modules\Planning\Domain\OvertimeKinds;
use App\Modules\Planning\Domain\OvertimeTerms;
use App\Modules\Planning\Domain\PayrollBasis;
use App\Modules\Planning\Domain\WeekBasis;
use App\Modules\Planning\Domain\WorkInterval;
use Carbon\CarbonImmutable;

/*
 * H.1: overtime and mertid split by the agreement's kinds per occasion
 * (Gröna Riks § 12 p. 1.2 and 2.2; Detaljhandeln § 9.1, 9.2, 9.5 and 9.6),
 * from the shipped data files. Every expected number is worked out by hand
 * in the comment above it.
 */

function kindsRules(string $file): AgreementRules
{
    $data = json_decode((string) file_get_contents(base_path("app/Modules/Agreements/database/data/agreements/{$file}")), true);

    return new AgreementRules($data, $data['sources'] ?? []);
}

/**
 * A day with planned and worked intervals, each "HH:MM-HH:MM" with an
 * optional "/break" in minutes.
 *
 * @param  list<string>  $planned
 * @param  list<string>  $actual
 */
function kindsDay(string $date, array $planned, array $actual): DayComparison
{
    $intervals = function (array $list) use ($date): array {
        $result = [];

        foreach ($list as $item) {
            [$span, $break] = array_pad(explode('/', $item), 2, '0');
            [$from, $to] = explode('-', $span);
            $start = CarbonImmutable::parse("{$date} {$from}", 'Europe/Stockholm');
            $end = CarbonImmutable::parse("{$date} {$to}", 'Europe/Stockholm');
            $result[] = new WorkInterval($start, $end->lessThanOrEqualTo($start) ? $end->addDay() : $end, (int) $break);
        }

        return $result;
    };

    return new DayComparison(CarbonImmutable::parse($date, 'Europe/Stockholm'), $intervals($planned), $intervals($actual), DayComparison::SOURCE_MANUAL, []);
}

/**
 * Monday 2026-11-09 to Sunday: Monday to Friday planned 08–17 with an
 * hour's break (8 h a day, 40 h), worked as planned unless given.
 *
 * @param  array<string, list<string>>  $worked  date => worked intervals
 * @param  array<string, list<string>>  $plans  date => planned intervals
 * @return list<DayComparison>
 */
function kindsWeek(array $worked = [], array $plans = [], string $monday = '2026-11-09'): array
{
    $days = [];

    for ($i = 0; $i < 7; $i++) {
        $date = CarbonImmutable::parse($monday)->addDays($i)->toDateString();
        $plan = $plans[$date] ?? ($i < 5 ? ['08:00-17:00/60'] : []);
        $days[] = kindsDay($date, $plan, $worked[$date] ?? $plan);
    }

    return $days;
}

/** @param  list<DayComparison>  $days */
function kindsPeriod(string $file, array $days, int $contracted = 2400, bool $hourly = false, ?string $overtimeCode = null, ?string $extraCode = null): WeekBasis
{
    $rules = kindsRules($file);
    $last = $days[array_key_last($days)]->date;
    $kinds = OvertimeKinds::for($rules, $last, $hourly, $contracted >= 2400, $overtimeCode, $extraCode);

    return PayrollBasis::period($days, [], OvertimeTerms::from($rules), $contracted, 2400, $overtimeCode, $extraCode, false, 1, $kinds);
}

it('splits Gröna Riks overtime into the two first hours, the rest and time not adjoining', function () {
    // Monday 17:00–20:30 adjoins the shift: 2 h of a) and 1 h 30 min of b). Saturday 10–12 is a day off: 2 h of c).
    // 40 h + 3,5 h + 2 h = 45,5 h, 330 min over the contracted 40 h, all of it overtime.
    $week = kindsPeriod('visita-hrf-grona-riks/MEMBER-2025-04-01.json', kindsWeek([
        '2026-11-09' => ['08:00-20:30/60'],
        '2026-11-14' => ['10:00-12:00'],
    ]), overtimeCode: 'GR_OT_A');

    expect($week->overtimeMinutes)->toBe(330)
        ->and($week->minutes)->toBe(['GR_OT_A' => 120, 'GR_OT_B' => 90, 'GR_OT_C' => 120])
        ->and(collect($week->deviations)->pluck('message')->implode(' '))->toContain('Veckan: 90 min övertid i anslutning, därutöver enligt Gröna Riks (GR#§ 12 p. 1.2 s. 19; p. 2.2 s. 20) skickas med den här dagen (GR_OT_B).');
});

it('counts the two first hours per day before and after the shift together, and applies the ten-minute threshold per occasion', function () {
    // Tuesday 07:00–08:00 and 17:00–18:30: 60 + 90 min adjoining, so a) 120 and b) 30.
    // Wednesday 17:08 is an extension of 8 min, not overtime (GR § 12 p. 1.2 anm.).
    // Thursday 17:20: 20 min, a started half hour, so 30 min of a).
    $week = kindsPeriod('visita-hrf-grona-riks/MEMBER-2025-04-01.json', kindsWeek([
        '2026-11-10' => ['07:00-18:30/60'],
        '2026-11-11' => ['08:00-17:08/60'],
        '2026-11-12' => ['08:00-17:20/60'],
    ]));

    expect($week->minutes)->toBe(['GR_OT_A' => 150, 'GR_OT_B' => 30])
        ->and($week->overtimeMinutes)->toBe(180);
});

it('takes the highest kinds first when the period nets a short day against a long one', function () {
    // Monday to 20:00: a) 120 and b) 60. Tuesday ends at 16:00, an hour short.
    // The week is 120 min over 40 h; b) 60 is taken first, then a) 60.
    $week = kindsPeriod('visita-hrf-grona-riks/MEMBER-2025-04-01.json', kindsWeek([
        '2026-11-09' => ['08:00-20:00/60'],
        '2026-11-10' => ['08:00-16:00/60'],
    ]));

    expect($week->minutes)->toBe(['GR_OT_A' => 60, 'GR_OT_B' => 60])
        ->and(collect($week->deviations)->pluck('message')->implode(' '))->toContain('Veckan: 180 min utanför schemat, men 120 min övertid och mertid mot den avtalade tiden. Avtalet säger inte vilka timmar som då är övertid. NONOX har räknat de högst ersatta arterna först, vilket kan ge mer än avtalet kräver.');
});

it('splits mertid for part-time staff from 1 April 2026, and leaves it to the company code before that', function () {
    $plan = '08:00-14:00';
    $plans = fn (string $monday): array => collect(range(0, 4))->mapWithKeys(fn (int $i): array => [CarbonImmutable::parse($monday)->addDays($i)->toDateString() => [$plan]])->all();

    // 30 h a week. Monday 08–17: 3 h adjoining, a) 120 and b) 60; all of it mertid (under 40 h).
    $november = kindsPeriod('visita-hrf-grona-riks/MEMBER-2025-04-01.json', kindsWeek(['2026-11-09' => ['08:00-17:00']], $plans('2026-11-09')), 1800);
    // In March 2026 the mertid codes have no rate yet (GR § 12 p. 2.2 from 1 April 2026): the 180 min have no code.
    $march = kindsPeriod('visita-hrf-grona-riks/MEMBER-2025-04-01.json', kindsWeek(['2026-03-09' => ['08:00-17:00']], $plans('2026-03-09'), '2026-03-09'), 1800);
    // With a code of the company's own the mertid is sent on it, as before.
    $own = kindsPeriod('visita-hrf-grona-riks/MEMBER-2025-04-01.json', kindsWeek(['2026-11-09' => ['08:00-17:00']], $plans('2026-11-09')), 1800, extraCode: 'MERTID_X');

    expect([$november->extraTimeMinutes, $november->minutes])->toBe([180, ['GR_MT_A' => 120, 'GR_MT_B' => 60]])
        ->and($march->minutes)->toBe([])
        ->and(collect($march->deviations)->pluck('message')->implode(' '))->toContain('Veckan: 180 min mertid har ingen löneart.')
        ->and($own->minutes)->toBe(['MERTID_X' => 180]);
});

it('gives hourly-paid staff the supplement-only code of each kind', function () {
    $week = kindsPeriod('visita-hrf-grona-riks/MEMBER-2025-04-01.json', kindsWeek([
        '2026-11-09' => ['08:00-20:30/60'],
        '2026-11-14' => ['10:00-12:00'],
    ]), hourly: true);

    expect($week->minutes)->toBe(['GR_OT_A_TL' => 120, 'GR_OT_B_TL' => 90, 'GR_OT_C_TL' => 120]);
});

it('splits Detaljhandeln overtime by the first hours, the evening after 20.00, the weekend and a day off, with three hours on a day off', function () {
    // Monday is planned 10–19: 19:00–21:30 adjoins, 19–20 is of the first two hours (35 %), 20–21:30 is after 20.00 (70 %).
    // Saturday 10–13 is a day off on a Saturday (100 %, 180 min). Sunday 10–11 is a Sunday (100 %, 60 min),
    // and as a day off with overtime it gives at least three hours: 120 min more (D1 § 9.1).
    $week = kindsPeriod('svenskhandel-handels-detaljhandel/MEMBER-2025-04-01.json', kindsWeek([
        '2026-11-09' => ['10:00-21:30/60'],
        '2026-11-14' => ['10:00-13:00'],
        '2026-11-15' => ['10:00-11:00'],
    ], ['2026-11-09' => ['10:00-19:00/60']]));

    expect($week->overtimeMinutes)->toBe(390)
        // OB on the same minutes (D1 § 8.1 Anm.): Monday 19–20 is OB 50 % against overtime 35 %, so the 35 % supplement is taken
        // back (DH_OT_A_TL −60); 20–21:30 is OB 70 % against 70 %, Saturday 12–13 and Sunday 10–11 OB 100 % against 100 %, so the OB is.
        ->and($week->minutes)->toBe(['DH_OB_100' => -120, 'DH_OB_70' => -90, 'DH_OT_100' => 360, 'DH_OT_70' => 90, 'DH_OT_A' => 60, 'DH_OT_A_TL' => -60])
        ->and(collect($week->parts)->where('minimum', true)->values()->all())->toBe([['type' => 'OVERTIME', 'code' => 'DH_OT_100', 'date' => '2026-11-15', 'minutes' => 120, 'minimum' => true]])
        ->and(collect($week->deviations)->pluck('message')->implode(' '))->toContain('2026-11-15 är en fridag med 60 min övertid. Detaljhandeln ger ersättning för minst 3 h (D1#§ 9.1 s. 35), så 120 min läggs till på DH_OT_100 med timlön och tillägg.');
});

it('pays a weekday off at 70 % and gives no minimum to part-time staff', function () {
    // Part time 30 h (Monday to Friday 08–14). Wednesday 18–20 after the shift ended at 14:00 is not adjoining: 70 %.
    $plans = collect(range(0, 4))->mapWithKeys(fn (int $i): array => [CarbonImmutable::parse('2026-11-09')->addDays($i)->toDateString() => ['08:00-14:00']])->all();
    $week = kindsPeriod('svenskhandel-handels-detaljhandel/MEMBER-2025-04-01.json', kindsWeek(['2026-11-11' => ['08:00-14:00', '18:00-20:00'], '2026-11-15' => ['10:00-11:00']], $plans), 1800);

    // Sunday 60 min at 100 %; no top-up for part time. OB taken back where it is not higher: 18:15–20 (OB 50 % < 70 %) and Sunday (100 % = 100 %).
    expect($week->minutes)->toBe(['DH_MT_100' => 60, 'DH_MT_70' => 120, 'DH_OB_100' => -60, 'DH_OB_50' => -105]);
});

it('reads Detaljhandeln comp-time factors per date and Gröna Riks factors for mertid', function () {
    $dh = kindsRules('svenskhandel-handels-detaljhandel/MEMBER-2025-04-01.json');
    $gr = kindsRules('visita-hrf-grona-riks/MEMBER-2025-04-01.json');

    // D1 § 9.6: 1 h 30 min for 50 % until 31 March 2026, 1 h 21 min (1,35 h) for 35 % from 1 April 2026.
    expect($dh->compTimeFactor('DH_OT_A', CarbonImmutable::parse('2026-03-31')))->toBe('1.5')
        ->and($dh->compTimeFactor('DH_OT_A', CarbonImmutable::parse('2026-04-01')))->toBe('1.35')
        ->and($dh->compTimeFactor('DH_OT_70', CarbonImmutable::parse('2026-04-01')))->toBe('1.75')
        ->and($dh->compTimeFactor('DH_MT_A', CarbonImmutable::parse('2026-03-01')))->toBeNull()
        ->and($gr->compTimeFactor('GR_MT_C', CarbonImmutable::parse('2026-11-01')))->toBe('2')
        ->and($gr->compTimeFactor('GR_KOST', CarbonImmutable::parse('2026-11-01')))->toBeNull();
});

it('refuses kinds and comp-time steps in the wrong form', function () {
    $data = json_decode((string) file_get_contents(base_path('app/Modules/Agreements/database/data/agreements/svenskhandel-handels-detaljhandel/MEMBER-2025-04-01.json')), true);
    $validator = new AgreementFileValidator;

    expect($validator->validate($data, 'svenskhandel-handels-detaljhandel'))->toBe([]);

    $data['overtime']['kinds']['codes']['OVERTIME']['HIGH'] = 'DH_NOPE';
    $data['overtime']['kinds']['windows'][0]['kind'] = 'NIGHT';
    $data['overtime']['comp_time']['hours_per_hour']['DH_OT_A'] = [['valid_from' => '2026-04-01', 'factor' => 'en och en halv']];

    expect(implode(' ', $validator->validate($data, 'svenskhandel-handels-detaljhandel')))
        ->toContain('overtime.kinds.codes.OVERTIME: HIGH pekar på en art eller kod som inte finns.')
        ->toContain('overtime.kinds.windows.0 har fel form')
        ->toContain('Komptiden för DH_OT_A pekar på en kod som inte finns eller har en faktor i fel form.');
});

it('prices each stretch on the day it was worked across the rate change on 1 April 2026', function () {
    // Week 30 March – 5 April 2026. Tuesday 31 March and Wednesday 1 April each to 18:00: one hour of the first two hours each.
    // Detaljhandeln § 9.2: 50 % for overtime performed until 31 March 2026, 35 % from 1 April. Comp time 1,5 and 1,35 (§ 9.6).
    $week = kindsPeriod('svenskhandel-handels-detaljhandel/MEMBER-2025-04-01.json', kindsWeek([
        '2026-03-31' => ['08:00-18:00/60'],
        '2026-04-01' => ['08:00-18:00/60'],
    ], [], '2026-03-30'));
    $gr = kindsPeriod('visita-hrf-grona-riks/MEMBER-2025-04-01.json', kindsWeek([
        '2026-03-31' => ['08:00-18:00/60'],
        '2026-04-01' => ['08:00-18:00/60'],
    ], [], '2026-03-30'));

    expect($week->minutes)->toBe(['DH_OT_A' => 120])
        ->and(collect($week->parts)->map(fn (array $part): string => "{$part['code']} {$part['date']} {$part['minutes']}")->all())->toBe(['DH_OT_A 2026-03-31 60', 'DH_OT_A 2026-04-01 60'])
        ->and(collect($gr->parts)->map(fn (array $part): string => "{$part['code']} {$part['date']} {$part['minutes']}")->all())->toBe(['GR_OT_A 2026-03-31 60', 'GR_OT_A 2026-04-01 60']);
});

it('keeps OB where it is higher and takes back OB where overtime is, on a weekday evening and a Saturday afternoon', function () {
    // Detaljhandeln, full time. Monday planned 08–17, worked to 20:00: 17:00–19:00 are the first two hours (35 %), 19–20 is other overtime (70 %).
    // OB 50 % 18:15–20:00: 18:15–19:00 (45 min) has OB 50 % > 35 %, so the 35 % supplement goes back; 19:00–20:00 (60 min) has 70 % ≥ 50 %, so the OB goes back.
    // Saturday a day off, 13:00–15:00: 100 % overtime and OB 100 % after 12.00, so the OB goes back (120 min); the day is 2 h, topped up to 3 h.
    $week = kindsPeriod('svenskhandel-handels-detaljhandel/MEMBER-2025-04-01.json', kindsWeek([
        '2026-11-09' => ['08:00-20:00/60'],
        '2026-11-14' => ['13:00-15:00'],
    ]));

    expect($week->minutes)->toBe(['DH_OB_100' => -120, 'DH_OB_50' => -60, 'DH_OT_100' => 180, 'DH_OT_70' => 60, 'DH_OT_A' => 120, 'DH_OT_A_TL' => -45])
        ->and(collect($week->deviations)->pluck('message')->implode(' '))->toContain('2026-11-09: 45 min övertid eller mertid låg på OB-tid. Detaljhandeln ger bara den procentuellt högsta ersättningen (D1#§ 8.1 Anm. s. 33; § 9.1 Anm. s. 35; § 9.3 Anm. s. 36; § 9.5 Anm. 2 s. 37), så 45 min dras tillbaka på DH_OT_A_TL och DH_OB_50 gäller.');
});

it('takes nothing back when OB is included in the salary', function () {
    $rules = kindsRules('svenskhandel-handels-detaljhandel/MEMBER-2025-04-01.json');
    $days = kindsWeek(['2026-11-09' => ['08:00-20:00/60']]);
    $kinds = OvertimeKinds::for($rules, $days[6]->date, false, true, null, null, obPaid: false);

    expect(PayrollBasis::period($days, [], OvertimeTerms::from($rules), 2400, 2400, null, null, false, 1, $kinds)->minutes)->toBe(['DH_OT_70' => 60, 'DH_OT_A' => 120]);
});

it('does not compare OB for a stretch that is only partly overtime', function () {
    // Detaljhandeln. Monday to 20:00: 17–19 the first two hours, 19–20 other overtime. Tuesday ends 16:00, an hour short: 120 min overtime.
    // 19–20 (70 %) is taken whole and its OB 50 % taken back (−60). Of 17–19 only 60 min are overtime; which ones is not known, so no take-back.
    $week = kindsPeriod('svenskhandel-handels-detaljhandel/MEMBER-2025-04-01.json', kindsWeek([
        '2026-11-09' => ['08:00-20:00/60'],
        '2026-11-10' => ['08:00-16:00/60'],
    ]));

    expect($week->minutes)->toBe(['DH_OB_50' => -60, 'DH_OT_70' => 60, 'DH_OT_A' => 60])
        ->and(collect($week->deviations)->pluck('message')->implode(' '))->toContain('Veckan: 60 min övertid är bara en del av ett tillfälle utanför schemat');
});
