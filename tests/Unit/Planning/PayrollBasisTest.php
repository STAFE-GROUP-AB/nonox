<?php

declare(strict_types=1);

use App\Modules\Agreements\Domain\AgreementRules;
use App\Modules\Planning\Domain\BasisTerms;
use App\Modules\Planning\Domain\DayComparison;
use App\Modules\Planning\Domain\Deviation;
use App\Modules\Planning\Domain\PayrollBasis;
use App\Modules\Planning\Domain\WorkInterval;
use Carbon\CarbonImmutable;

/*
 * PayrollBasis (PLANNING_MODULE.md §5, §8): minutes per pay code from an
 * attested day. Hourly staff get every minute as hourly pay, monthly staff
 * only what the salary does not cover; OB from the Gröna Riks windows in
 * the shipped data file. Expected minutes worked out by hand.
 */

function basisRules(): AgreementRules
{
    $data = json_decode((string) file_get_contents(base_path('app/Modules/Agreements/database/data/agreements/visita-hrf-grona-riks/MEMBER-2025-04-01.json')), true);

    return new AgreementRules($data, $data['sources'] ?? []);
}

function basisDay(string $start, ?string $end, int $break = 0, ?string $plannedStart = null, ?string $plannedEnd = null, int $plannedBreak = 0): DayComparison
{
    $at = fn (?string $time): ?CarbonImmutable => $time === null ? null : CarbonImmutable::parse($time, 'Europe/Stockholm');
    $actual = new WorkInterval($at($start), $at($end), $break);
    $planned = $plannedStart === null ? [$actual] : [new WorkInterval($at($plannedStart), $at($plannedEnd), $plannedBreak)];

    return new DayComparison($at($start)->startOfDay(), $planned, [$actual], DayComparison::SOURCE_LEDGER, []);
}

/** @return list<string> */
function basisCodes(array $deviations): array
{
    return array_map(fn (Deviation $deviation): string => $deviation->code, $deviations);
}

it('pays an hourly employee every minute as hourly pay, without OB when there is no agreement', function () {
    $basis = PayrollBasis::for(basisDay('2026-11-03 17:00', '2026-11-03 23:00', 30), new BasisTerms('HOURLY', hourlyRateOre: 15_000));

    expect($basis->minutes)->toBe(['TIMLON' => 330])
        ->and($basis->ob)->toBe([])
        ->and(basisCodes($basis->deviations))->toBe([Deviation::NO_OB]);
});

it('adds OB per code from the agreement for an hourly employee', function () {
    // Tue 17–23: OB from 20:00 = 180 min. Hourly pay 360 min.
    $basis = PayrollBasis::for(basisDay('2026-11-03 17:00', '2026-11-03 23:00'), new BasisTerms('HOURLY', hourlyRateOre: 15_000, agreement: basisRules()));

    expect($basis->minutes)->toBe(['GR_OB' => 180, 'TIMLON' => 360])
        ->and($basis->ob)->toBe(['GR_OB' => 180]);
});

it('says when OB is counted over a break whose place is unknown', function () {
    $basis = PayrollBasis::for(basisDay('2026-11-03 17:00', '2026-11-03 23:00', 30), new BasisTerms('HOURLY', hourlyRateOre: 15_000, agreement: basisRules()));

    expect($basis->minutes)->toBe(['GR_OB' => 180, 'TIMLON' => 330])
        ->and(basisCodes($basis->deviations))->toBe([Deviation::OB_WITH_BREAK])
        ->and($basis->deviations[0]->informational)->toBeTrue();
});

it('never pays a monthly employee\'s planned time again, only OB', function () {
    $basis = PayrollBasis::for(basisDay('2026-11-07 12:00', '2026-11-07 20:00'), new BasisTerms('MONTHLY', monthlySalaryOre: 3_200_000, agreement: basisRules()));

    // Saturday from 16:00 = 240 min of OB; no hourly pay.
    expect($basis->minutes)->toBe(['GR_OB' => 240]);
});

it('only notes a monthly employee\'s time beyond the day\'s plan: overtime is counted per week', function () {
    $basis = PayrollBasis::for(basisDay('2026-11-03 08:00', '2026-11-03 17:30', 0, '2026-11-03 08:00', '2026-11-03 16:00'), new BasisTerms('MONTHLY', monthlySalaryOre: 3_200_000));

    expect($basis->minutes)->toBe([])
        ->and(basisCodes($basis->deviations))->toBe([Deviation::EXTRA_TIME])
        ->and($basis->deviations[0]->informational)->toBeTrue();
});

it('counts a week\'s overtime and mertid beyond the contracted weekly time', function () {
    // Full time 40 h, worked 41 h 30: 90 min overtime.
    $full = PayrollBasis::week(2490, 2400, 2400, 'GR_OT_A', 'MERTID_X', false);
    // 30 h a week, worked 42 h: 600 min mertid up to 40 h, 120 min overtime beyond.
    $part = PayrollBasis::week(2520, 1800, 2400, 'GR_OT_A', 'MERTID_X', false);
    // No pay codes chosen: nothing sent, shown for the manager.
    $none = PayrollBasis::week(2490, 2400, 2400, null, null, false);
    // Under the contracted time: nothing, and nothing deducted.
    $short = PayrollBasis::week(2000, 2400, 2400, 'GR_OT_A', null, false);
    $included = PayrollBasis::week(2490, 2400, 2400, 'GR_OT_A', null, true);

    expect([$full->overtimeMinutes, $full->extraTimeMinutes, $full->minutes])->toBe([90, 0, ['GR_OT_A' => 90]])
        ->and([$part->overtimeMinutes, $part->extraTimeMinutes, $part->minutes])->toBe([120, 600, ['GR_OT_A' => 120, 'MERTID_X' => 600]])
        ->and($none->minutes)->toBe([])
        ->and(basisCodes($none->deviations))->toBe([Deviation::EXTRA_TIME])
        ->and($none->deviations[0]->informational)->toBeFalse()
        ->and($short->minutes)->toBe([])
        ->and($short->deviations)->toBe([])
        ->and($included->minutes)->toBe([])
        ->and($included->deviations[0]->informational)->toBeTrue();
});

it('says that no OB is counted without an agreement, on days in typical OB hours only', function () {
    $evening = PayrollBasis::for(basisDay('2026-11-03 17:00', '2026-11-03 23:00'), new BasisTerms('HOURLY', hourlyRateOre: 15_000));
    $saturday = PayrollBasis::for(basisDay('2026-11-07 10:00', '2026-11-07 14:00'), new BasisTerms('HOURLY', hourlyRateOre: 15_000));
    $daytime = PayrollBasis::for(basisDay('2026-11-03 08:00', '2026-11-03 16:00'), new BasisTerms('HOURLY', hourlyRateOre: 15_000));
    $included = PayrollBasis::for(basisDay('2026-11-03 17:00', '2026-11-03 23:00'), new BasisTerms('HOURLY', hourlyRateOre: 15_000, agreement: basisRules(), obIncluded: true));

    expect(basisCodes($evening->deviations))->toBe([Deviation::NO_OB])
        ->and($evening->deviations[0]->informational)->toBeTrue()
        ->and($evening->deviations[0]->message)->toBe('Ingen OB räknas: inget kollektivavtal i Avtal. Lägg in OB i lönekörningen om ni ger det.')
        ->and(basisCodes($saturday->deviations))->toBe([Deviation::NO_OB])
        ->and($daytime->deviations)->toBe([])
        ->and($included->deviations[0]->message)->toBe('Ingen OB räknas: OB ingår i lönen enligt anställningen.')
        ->and($included->minutes)->toBe(['TIMLON' => 360]);
});

it('does not deduct shorter time for a monthly employee and says so', function () {
    $basis = PayrollBasis::for(basisDay('2026-11-03 08:00', '2026-11-03 14:00', 0, '2026-11-03 08:00', '2026-11-03 16:00'), new BasisTerms('MONTHLY', monthlySalaryOre: 3_200_000));

    expect($basis->minutes)->toBe([])
        ->and(basisCodes($basis->deviations))->toBe([Deviation::SHORT_TIME]);

    $absent = PayrollBasis::for(basisDay('2026-11-03 08:00', '2026-11-03 14:00', 0, '2026-11-03 08:00', '2026-11-03 16:00'), new BasisTerms('MONTHLY', monthlySalaryOre: 3_200_000, absence: true));

    expect($absent->deviations)->toBe([]);
});

it('splits storhelg on julafton on a Saturday', function () {
    // 2022-12-24 is a Saturday: STORHELG 06→06 covers all 720 minutes; GR_OB from 16:00.
    $basis = PayrollBasis::for(basisDay('2022-12-24 10:00', '2022-12-24 22:00'), new BasisTerms('HOURLY', hourlyRateOre: 15_000, agreement: basisRules()));

    expect($basis->minutes)->toBe(['GR_OB' => 360, 'GR_STORHELG' => 720, 'TIMLON' => 720]);
});

it('counts real minutes on the night summer time starts', function () {
    $basis = PayrollBasis::for(basisDay('2026-03-28 22:00', '2026-03-29 06:00'), new BasisTerms('HOURLY', hourlyRateOre: 15_000, agreement: basisRules()));

    expect($basis->minutes)->toBe(['GR_OB' => 420, 'GR_OB_NATT' => 240, 'TIMLON' => 420]);
});

it('splits a shift over midnight into the windows on both days', function () {
    // Sunday HELGDAG 06→Mon 06 for 18:00–02:00; night window Mon 01–02.
    $basis = PayrollBasis::for(basisDay('2026-11-08 18:00', '2026-11-09 02:00'), new BasisTerms('HOURLY', hourlyRateOre: 15_000, agreement: basisRules()));

    expect($basis->minutes)->toBe(['GR_OB' => 480, 'GR_OB_NATT' => 60, 'TIMLON' => 480]);
});

it('pays nothing for a shift without check-out', function () {
    $basis = PayrollBasis::for(basisDay('2026-11-03 17:00', null), new BasisTerms('HOURLY', hourlyRateOre: 15_000, agreement: basisRules()));

    expect($basis->minutes)->toBe([]);
});

it('blocks without a checked agreement version or without an employment, and leaves OB out when it is in the salary', function () {
    $day = basisDay('2026-11-03 17:00', '2026-11-03 23:00');

    $unverified = PayrollBasis::for($day, new BasisTerms('HOURLY', hourlyRateOre: 15_000, agreementUnverified: true));
    $unemployed = PayrollBasis::for($day, new BasisTerms(null));
    $included = PayrollBasis::for($day, new BasisTerms('HOURLY', hourlyRateOre: 15_000, agreement: basisRules(), obIncluded: true));

    expect(basisCodes($unverified->deviations))->toBe([Deviation::AGREEMENT_UNVERIFIED])
        ->and($unverified->deviations[0]->blocking)->toBeTrue()
        ->and(basisCodes($unemployed->deviations))->toBe([Deviation::NO_EMPLOYMENT])
        ->and($unemployed->minutes)->toBe([])
        ->and($included->minutes)->toBe(['TIMLON' => 360])
        ->and(basisCodes($included->deviations))->toBe([Deviation::NO_OB]);
});
