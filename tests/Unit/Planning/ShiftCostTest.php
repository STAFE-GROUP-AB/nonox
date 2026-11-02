<?php

declare(strict_types=1);

use App\Modules\Agreements\Domain\AgreementRules;
use App\Modules\Planning\Domain\ShiftCost;
use Carbon\CarbonImmutable;

/*
 * ShiftCost (PLANNING_MODULE.md §5): hours × hourly wage, OB at the
 * agreement's price, vacation pay and contributions only from verified
 * rules, and what is missing named instead of guessed. Gröna Riks from the
 * shipped data file.
 */

function costAgreement(string $file): AgreementRules
{
    $data = json_decode((string) file_get_contents(base_path("app/Modules/Agreements/database/data/agreements/{$file}")), true);

    return new AgreementRules($data, $data['sources'] ?? []);
}

function costAt(string $time): CarbonImmutable
{
    return CarbonImmutable::parse($time, 'Europe/Stockholm');
}

it('prices an hourly shift with OB, vacation pay and contributions', function () {
    // Tuesday 2026-11-03 16:00–22:00, 30 min break: 330 min at 150 kr/h = 825 kr.
    // OB 20–22 = 120 min at 27,59 kr/h (step from 2026-04-01) = 55,18 kr.
    // Vacation 12 % of 880,18 = 105,62; contributions 31,42 % of 985,80 = 309,74.
    $estimate = ShiftCost::estimate(costAt('2026-11-03 16:00'), costAt('2026-11-03 22:00'), 30, 'HOURLY', 15000, null, null,
        agreement: costAgreement('visita-hrf-grona-riks/MEMBER-2025-04-01.json'), contributionBp: 3142, vacationBp: 1200);

    expect($estimate->workedMinutes)->toBe(330)
        ->and($estimate->baseOre)->toBe(82500)
        ->and($estimate->obMinutes)->toBe(['GR_OB' => 120])
        ->and($estimate->obOre)->toBe(5518)
        ->and($estimate->vacationOre)->toBe(10562)
        ->and($estimate->contributionsOre)->toBe(30974)
        ->and($estimate->totalOre())->toBe(82500 + 5518 + 10562 + 30974)
        ->and($estimate->isComplete())->toBeTrue()
        ->and($estimate->notes)->toContain('OB räknas på hela passet; rastens placering är inte planerad.');
});

it('turns a monthly salary into an hourly wage by the weekly hours', function () {
    // 32 000 kr × 12 / (40 × 52) = 184,615… kr/h; 8 h = 1 476,92 kr.
    $estimate = ShiftCost::estimate(costAt('2026-11-02 08:00'), costAt('2026-11-02 16:00'), 0, 'MONTHLY', null, 3_200_000, '40', contributionBp: 3142);

    expect($estimate->hourlyOre)->toBe(18462)
        ->and($estimate->baseOre)->toBe(147692)
        ->and($estimate->vacationOre)->toBeNull()
        ->and($estimate->contributionsOre)->toBe(46405)
        ->and($estimate->notes)->toContain('Semestertillägg för månadslön ingår inte i uppskattningen.')
        ->and($estimate->isComplete())->toBeTrue();
});

it('says what is missing instead of guessing', function () {
    $noRules = ShiftCost::estimate(costAt('2026-11-03 08:00'), costAt('2026-11-03 12:00'), 0, 'HOURLY', 15000, null, null);

    expect($noRules->totalOre())->toBe(60000)
        ->and($noRules->missing)->toBe([
            'Semesterersättning: ingen kontrollerad regel för procentregeln.',
            'Arbetsgivaravgifter: ingen kontrollerad regel.',
        ]);

    $unverified = ShiftCost::estimate(costAt('2026-11-03 18:00'), costAt('2026-11-03 23:00'), 0, 'HOURLY', 15000, null, null, agreementUnverified: true, contributionBp: 3142, vacationBp: 1200);
    expect($unverified->obOre)->toBe(0)->and($unverified->missing)->toBe(['OB: avtalsversionen är inte kontrollerad.']);

    $noWage = ShiftCost::estimate(costAt('2026-11-03 08:00'), costAt('2026-11-03 12:00'), 0, 'MONTHLY', null, 3_200_000, null);
    expect($noWage->totalOre())->toBeNull()->and($noWage->missing)->toBe(['Månadslön eller veckoarbetstid saknas på anställningen.']);

    $noEmployment = ShiftCost::estimate(costAt('2026-11-03 08:00'), costAt('2026-11-03 12:00'), 0, null, null, null, null);
    expect($noEmployment->missing)->toBe(['Ingen pågående anställning med lön.']);
});

it('adds no OB when the employment has OB included in the salary', function () {
    $estimate = ShiftCost::estimate(costAt('2026-11-03 18:00'), costAt('2026-11-03 23:00'), 0, 'HOURLY', 15000, null, null,
        agreement: costAgreement('visita-hrf-grona-riks/MEMBER-2025-04-01.json'), obIncludedInSalary: true, contributionBp: 3142, vacationBp: 1200);

    expect($estimate->obOre)->toBe(0)->and($estimate->obMinutes)->toBe([]);
});

it('prices percentage OB from the hourly wage', function () {
    // Detaljhandel: Saturday 2026-11-07 12:00–16:00 is OB 100 %; 4 h × 160 kr = 640 kr OB.
    $estimate = ShiftCost::estimate(costAt('2026-11-07 10:00'), costAt('2026-11-07 16:00'), 0, 'HOURLY', 16000, null, null,
        agreement: costAgreement('svenskhandel-handels-detaljhandel/MEMBER-2025-04-01.json'), contributionBp: 3142, vacationBp: 1200);

    expect($estimate->obMinutes)->toBe(['DH_OB_100' => 240])
        ->and($estimate->obOre)->toBe(64000)
        ->and($estimate->baseOre)->toBe(96000);
});
