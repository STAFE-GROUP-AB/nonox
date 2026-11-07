<?php

declare(strict_types=1);

use App\Domain\AnnualReport\K3CompanySize;
use App\Domain\AnnualReport\K3SizeClass;
use App\Domain\AnnualReport\K3SizeCriterion;
use App\Domain\AnnualReport\K3SizeFigures;
use App\Domain\Company\LegalForm;
use Carbon\CarbonImmutable;

/*
 * The size test (ADR-059, addendum 2026-11-07, S1-P2): ÅRL 1 kap. 3 §
 * första stycket 4–5 measured as BFNAR 2006:11 and BFN's vägledning
 * Gränsvärden (uppdaterad 2026-09-14) say, and the K2 1.1C exception from
 * the same figures. No database is needed.
 */

const MSEK = 100_000_000;

/** A calendar year's figures; employees as a decimal string ("50.4"). */
function sizeYear(int $year, ?string $employees, int $balanceOre, int $turnoverOre): K3SizeFigures
{
    return new K3SizeFigures(CarbonImmutable::create($year, 1, 1), CarbonImmutable::create($year, 12, 31), $employees, $balanceOre, $turnoverOre);
}

/** A year of $months months ending 2025-12-31, with only a net turnover. */
function turnoverYear(int $months, int $turnoverOre): K3SizeFigures
{
    return new K3SizeFigures(CarbonImmutable::parse('2026-01-01')->subMonths($months), CarbonImmutable::parse('2025-12-31'), '0', 41 * MSEK, $turnoverOre);
}

function classifyFor(int $reportingYear, K3SizeFigures ...$years): K3SizeClass
{
    return K3CompanySize::classify(array_values($years), CarbonImmutable::create($reportingYear, 1, 1))->class;
}

it('S1-R9.2 rounds the average number of employees to a whole number before the test: 50,4 is 50, 50,5 is 51', function () {
    $years = fn (string $employees): array => [sizeYear(2025, $employees, 41 * MSEK, 0), sizeYear(2024, $employees, 41 * MSEK, 0)];

    expect(classifyFor(2026, ...$years('50')))->toBe(K3SizeClass::Mindre)
        ->and(classifyFor(2026, ...$years('50.4')))->toBe(K3SizeClass::Mindre)
        ->and(classifyFor(2026, ...$years('50.5')))->toBe(K3SizeClass::Storre)
        ->and(sizeYear(2025, '50.4', 0, 0)->averageEmployeesRounded())->toBe(50)
        ->and(sizeYear(2025, '50.5', 0, 0)->averageEmployeesRounded())->toBe(51);
});

it('S1-R9.2 shows both the exact and the rounded number of employees in the reason', function () {
    $result = K3CompanySize::classify([sizeYear(2025, '50.5', 41 * MSEK, 0), sizeYear(2024, '50.5', 41 * MSEK, 0)], CarbonImmutable::parse('2026-01-01'));

    expect($result->reason)->toContain('50,50')->toContain('51');
});

it('S1-R9 counts a balance sheet total above 40 MSEK strictly: 40 MSEK is not more, 40 MSEK + 1 öre is', function () {
    $atLimit = [sizeYear(2025, '51', 40 * MSEK, 0), sizeYear(2024, '51', 40 * MSEK, 0)];
    $above = [sizeYear(2025, '51', 40 * MSEK + 1, 0), sizeYear(2024, '51', 40 * MSEK + 1, 0)];

    expect(classifyFor(2026, ...$atLimit))->toBe(K3SizeClass::Mindre)
        ->and(classifyFor(2026, ...$above))->toBe(K3SizeClass::Storre);
});

it('S1-R9 counts a net turnover above 80 MSEK strictly: 80 MSEK is not more, 80 MSEK + 1 öre is', function () {
    $atLimit = [sizeYear(2025, '51', 0, 80 * MSEK), sizeYear(2024, '51', 0, 80 * MSEK)];
    $above = [sizeYear(2025, '51', 0, 80 * MSEK + 1), sizeYear(2024, '51', 0, 80 * MSEK + 1)];

    expect(classifyFor(2026, ...$atLimit))->toBe(K3SizeClass::Mindre)
        ->and(classifyFor(2026, ...$above))->toBe(K3SizeClass::Storre);
});

it('S1-R9 needs more than one criterion: one exceeded in both years is a mindre företag', function () {
    $result = K3CompanySize::classify([sizeYear(2025, '10', 50 * MSEK, 10 * MSEK), sizeYear(2024, '10', 50 * MSEK, 10 * MSEK)], CarbonImmutable::parse('2026-01-01'));

    expect($result->class)->toBe(K3SizeClass::Mindre)
        ->and($result->ruleId)->toBe('S1-R9')
        ->and($result->exceeded['2025-12-31'])->toBe([K3SizeCriterion::BalanceSheetTotal]);
});

it('S1-R9 a criterion exceeded in only one of the two years does not count', function () {
    expect(classifyFor(2026, sizeYear(2025, '60', 50 * MSEK, 90 * MSEK), sizeYear(2024, '10', 10 * MSEK, 10 * MSEK)))->toBe(K3SizeClass::Mindre);
});

it('S1-R9 BFN example 1: employees and net turnover exceeded both years is a större företag', function () {
    expect(classifyFor(2026, sizeYear(2025, '57', 20 * MSEK, 90 * MSEK), sizeYear(2024, '55', 19 * MSEK, 85 * MSEK)))->toBe(K3SizeClass::Storre);
});

it('S1-R9 BFN example 2: two thresholds each year but not the same two is a mindre företag', function () {
    // År 1: 60 / 37 / 90, år 2: 45 / 42 / 92 – only the net turnover is exceeded in both years.
    $result = K3CompanySize::classify([sizeYear(2025, '45', 42 * MSEK, 92 * MSEK), sizeYear(2024, '60', 37 * MSEK, 90 * MSEK)], CarbonImmutable::parse('2026-01-01'));

    expect($result->class)->toBe(K3SizeClass::Mindre)
        ->and($result->exceeded['2025-12-31'])->toBe([K3SizeCriterion::BalanceSheetTotal, K3SizeCriterion::NetTurnover])
        ->and($result->exceeded['2024-12-31'])->toBe([K3SizeCriterion::Employees, K3SizeCriterion::NetTurnover]);
});

it('S1-R9.1 measures the two years immediately before the reporting year, not the reporting year itself', function () {
    $result = K3CompanySize::classify([sizeYear(2025, '10', 10 * MSEK, 10 * MSEK), sizeYear(2024, '10', 10 * MSEK, 10 * MSEK)], CarbonImmutable::parse('2026-01-01'));

    expect($result->class)->toBe(K3SizeClass::Mindre)
        ->and(array_keys($result->exceeded))->toBe(['2025-12-31', '2024-12-31']);
});

it('S1-R9.1 returns undetermined for an unlisted company with fewer than two prior years and no confirmation', function () {
    $none = K3CompanySize::classify([], CarbonImmutable::parse('2026-01-01'));
    $one = K3CompanySize::classify([sizeYear(2025, '90', 90 * MSEK, 90 * MSEK)], CarbonImmutable::parse('2026-01-01'));

    expect($none->class)->toBe(K3SizeClass::Undetermined)
        ->and($one->class)->toBe(K3SizeClass::Undetermined)
        ->and($one->ruleId)->toBe('S1-R9.1')
        ->and($one->reason)->toContain('2024-12-31');
});

it('S1-R9.1 a newly started company is a mindre företag its first two years once a person has confirmed its first fiscal year', function () {
    $firstYear = K3CompanySize::classify([], CarbonImmutable::parse('2026-01-01'), confirmedFirstYearStart: CarbonImmutable::parse('2026-01-01'));
    $secondYear = K3CompanySize::classify([sizeYear(2025, '90', 90 * MSEK, 90 * MSEK)], CarbonImmutable::parse('2026-01-01'), confirmedFirstYearStart: CarbonImmutable::parse('2025-01-01'));

    expect($firstYear->class)->toBe(K3SizeClass::Mindre)
        ->and($secondYear->class)->toBe(K3SizeClass::Mindre)
        ->and($secondYear->reason)->toContain('nystartat');
});

it('S1-R9.1 a confirmation that does not match the missing year leaves the test undetermined', function () {
    // The company says it started 2024, but nothing is known about 2024.
    $result = K3CompanySize::classify([sizeYear(2025, '90', 90 * MSEK, 90 * MSEK)], CarbonImmutable::parse('2026-01-01'), confirmedFirstYearStart: CarbonImmutable::parse('2024-01-01'));

    expect($result->class)->toBe(K3SizeClass::Undetermined);
});

it('S1-R9.1 refuses years that are not the two immediately preceding ones', function () {
    $result = K3CompanySize::classify([sizeYear(2025, '90', 90 * MSEK, 90 * MSEK), sizeYear(2023, '90', 90 * MSEK, 90 * MSEK)], CarbonImmutable::parse('2026-01-01'));

    expect($result->class)->toBe(K3SizeClass::Undetermined);
});

it('S1-R9 a listed company is always a större företag', function () {
    $result = K3CompanySize::classify([], CarbonImmutable::parse('2026-01-01'), listed: true);

    expect($result->class)->toBe(K3SizeClass::Storre);
});

it('S1-R9 leaves the test to a person for an ideell förening or a stiftelse, whose gifts and grants count as turnover', function (LegalForm $form) {
    $result = K3CompanySize::classify([sizeYear(2025, '1', 0, 0), sizeYear(2024, '1', 0, 0)], CarbonImmutable::parse('2026-01-01'), legalForm: $form);

    expect($result->class)->toBe(K3SizeClass::Undetermined)
        ->and($result->reason)->toContain('gåvor');
})->with([LegalForm::IdeellForening, LegalForm::Stiftelse]);

it('S1-R9 tests an aktiebolag and an ekonomisk förening from the figures as they are', function (LegalForm $form) {
    $result = K3CompanySize::classify([sizeYear(2025, '1', 0, 0), sizeYear(2024, '1', 0, 0)], CarbonImmutable::parse('2026-01-01'), legalForm: $form);

    expect($result->class)->toBe(K3SizeClass::Mindre);
})->with([LegalForm::Ab, LegalForm::EkonomiskForening]);

it('S1-R9.2 pro-rates the net turnover of a 6-month year to 12 months', function () {
    $short = new K3SizeFigures(CarbonImmutable::parse('2025-07-01'), CarbonImmutable::parse('2025-12-31'), '0', 0, 45 * MSEK);

    expect($short->months())->toBe(6)
        ->and($short->annualisedNetTurnoverOre())->toBe(90 * MSEK);
});

it('S1-R9.2 shows the pro-rated net turnover of an 18-month year rounded half up to the öre', function () {
    $long = new K3SizeFigures(CarbonImmutable::parse('2024-07-01'), CarbonImmutable::parse('2025-12-31'), '0', 0, 100_000_001);

    expect($long->months())->toBe(18)
        ->and($long->annualisedNetTurnoverOre())->toBe(66_666_667);
});

it('S1-R9.2 compares the pro-rated net turnover exactly, not the rounded figure', function (int $months, int $notAbove, int $above) {
    expect(turnoverYear($months, $notAbove)->exceedsNetTurnover(80 * MSEK))->toBeFalse()
        ->and(turnoverYear($months, $above)->exceedsNetTurnover(80 * MSEK))->toBeTrue();
})->with([
    // The limit is 80 MSEK × months / 12; the rounded figure of $above is exactly 80 MSEK.
    '10 months' => [10, 6_666_666_666, 6_666_666_667],
    '13 months' => [13, 8_666_666_666, 8_666_666_667],
    '16 months' => [16, 10_666_666_666, 10_666_666_667],
    '17 months' => [17, 11_333_333_333, 11_333_333_334],
]);

it('S1-R9.2 BFN: an 18-month year with 120 MSEK is not above 80 MSEK, with 120 MSEK + 1 öre it is', function () {
    expect(turnoverYear(18, 120 * MSEK)->exceedsNetTurnover(80 * MSEK))->toBeFalse()
        ->and(turnoverYear(18, 120 * MSEK + 1)->exceedsNetTurnover(80 * MSEK))->toBeTrue();
});

it('S1-R9.2 pro-rating decides the test: a 6-month year with 45 MSEK counts as 90 MSEK', function () {
    $short = new K3SizeFigures(CarbonImmutable::parse('2025-07-01'), CarbonImmutable::parse('2025-12-31'), '0', 41 * MSEK, 45 * MSEK);
    $previous = new K3SizeFigures(CarbonImmutable::parse('2024-07-01'), CarbonImmutable::parse('2025-06-30'), '0', 41 * MSEK, 81 * MSEK);

    expect(K3CompanySize::classify([$short, $previous], CarbonImmutable::parse('2026-01-01'))->class)->toBe(K3SizeClass::Storre);
});

it('S1-R9.2 does not pro-rate the balance sheet total of a short year', function () {
    $short = new K3SizeFigures(CarbonImmutable::parse('2025-07-01'), CarbonImmutable::parse('2025-12-31'), '0', 30 * MSEK, 0);
    $previous = new K3SizeFigures(CarbonImmutable::parse('2024-07-01'), CarbonImmutable::parse('2025-06-30'), '0', 30 * MSEK, 0);

    expect(K3CompanySize::classify([$short, $previous], CarbonImmutable::parse('2026-01-01'))->exceeded)
        ->toBe(['2025-12-31' => [], '2025-06-30' => []]);
});

it('S1-R9 prints the dates of years whose labels would be the same', function () {
    // Two short years within 2025: both would be labelled "2025".
    $second = new K3SizeFigures(CarbonImmutable::parse('2025-07-01'), CarbonImmutable::parse('2025-12-31'), '0', 0, 0);
    $first = new K3SizeFigures(CarbonImmutable::parse('2025-01-01'), CarbonImmutable::parse('2025-06-30'), '0', 0, 0);

    expect(K3CompanySize::classify([$second, $first], CarbonImmutable::parse('2026-01-01'))->reason)
        ->toContain('2025-01-01–2025-06-30')
        ->toContain('2025-07-01–2025-12-31');
});

it('S1-R9.2 turns hours worked into full-time equivalents with 1 920 hours a year, exactly', function () {
    expect((string) K3CompanySize::fteFromHours(96_000)->toScale(2))->toBe('50.00')
        ->and((string) K3CompanySize::fteFromHours(96_960)->toScale(2))->toBe('50.50')
        // A 6-month year: normal working time is 960 hours.
        ->and((string) K3CompanySize::fteFromHours(48_000, 6)->toScale(2))->toBe('50.00')
        // 6 528 / 1 920 = 3,4 → 3; 6 720 / 1 920 = 3,5 → 4.
        ->and((new K3SizeFigures(CarbonImmutable::parse('2025-01-01'), CarbonImmutable::parse('2025-12-31'), K3CompanySize::fteFromHours(6_528), 0, 0))->averageEmployeesRounded())->toBe(3)
        ->and((new K3SizeFigures(CarbonImmutable::parse('2025-01-01'), CarbonImmutable::parse('2025-12-31'), K3CompanySize::fteFromHours(6_720), 0, 0))->averageEmployeesRounded())->toBe(4);
});

it('S1-R9 returns undetermined when a missing employee figure would decide the test', function () {
    $result = K3CompanySize::classify([sizeYear(2025, null, 50 * MSEK, 10 * MSEK), sizeYear(2024, '60', 50 * MSEK, 10 * MSEK)], CarbonImmutable::parse('2026-01-01'));

    expect($result->class)->toBe(K3SizeClass::Undetermined)
        ->and($result->reason)->toContain('medelantalet anställda');
});

it('S1-R9 decides without the employee figure when it cannot change the answer', function () {
    expect(classifyFor(2026, sizeYear(2025, null, 50 * MSEK, 90 * MSEK), sizeYear(2024, null, 50 * MSEK, 90 * MSEK)))->toBe(K3SizeClass::Storre);
});

it('S1-R9 classifies F-S1-a as a större företag for 2025 and 2026', function () {
    $fixture = json_decode((string) file_get_contents(base_path('tests/Fixtures/s1/f-s1-a/expected.json')), true, flags: JSON_THROW_ON_ERROR);
    $years = $fixture['size_classification']['years'];
    $figures = fn (int $year): K3SizeFigures => sizeYear($year, (string) $years[(string) $year]['medelantal_anstallda'], $years[(string) $year]['balansomslutning'], $years[(string) $year]['nettoomsattning']);

    foreach ([2025, 2026] as $reportingYear) {
        $result = K3CompanySize::classify([$figures($reportingYear - 1), $figures($reportingYear - 2)], CarbonImmutable::create($reportingYear, 1, 1));

        expect($result->class)->toBe(K3SizeClass::Storre)
            ->and($result->exceeded[($reportingYear - 1).'-12-31'])->toBe([K3SizeCriterion::BalanceSheetTotal, K3SizeCriterion::NetTurnover])
            ->and($fixture['size_classification']['result'][(string) $reportingYear])->toStartWith('storre_foretag');
    }
});

it('S1-R1.3 keeps the K2 1.1C exception when at most one criterion is exceeded in both years', function () {
    $start = CarbonImmutable::parse('2027-01-01');
    $end = CarbonImmutable::parse('2027-12-31');

    $one = K3CompanySize::k2Exception([sizeYear(2026, '4', 2 * MSEK, MSEK), sizeYear(2025, '4', MSEK, MSEK)], $start, $end);
    $differentTwo = K3CompanySize::k2Exception([sizeYear(2026, '4', 2 * MSEK, MSEK), sizeYear(2025, '4', MSEK, 4 * MSEK)], $start, $end);

    expect($one->applies)->toBeTrue()
        ->and($one->ruleId)->toBe('S1-R1.3')
        ->and($differentTwo->applies)->toBeTrue();
});

it('S1-R1.3 loses the K2 1.1C exception when the same two criteria are exceeded in both years', function () {
    $result = K3CompanySize::k2Exception([sizeYear(2026, '4', 2 * MSEK, MSEK), sizeYear(2025, '3.5', 150_000_001, MSEK)], CarbonImmutable::parse('2027-01-01'), CarbonImmutable::parse('2027-12-31'));

    expect($result->applies)->toBeFalse()
        ->and($result->exceeded)->toBe([K3SizeCriterion::Employees, K3SizeCriterion::BalanceSheetTotal]);
});

it('S1-R1.3 counts the K2 1.1C thresholds strictly, with employees rounded: 3,4 is 3', function () {
    $atLimit = K3CompanySize::k2Exception([sizeYear(2026, '3.4', 150_000_000, 300_000_000), sizeYear(2025, '3.4', 150_000_000, 300_000_000)], CarbonImmutable::parse('2027-01-01'), CarbonImmutable::parse('2027-12-31'));

    expect($atLimit->applies)->toBeTrue();
});

it('S1-R1.3 does not judge K2 1.1C with fewer than two prior years without a confirmation, or for a year begun before 2026', function () {
    $tooFew = K3CompanySize::k2Exception([sizeYear(2026, '0', 0, 0)], CarbonImmutable::parse('2027-01-01'), CarbonImmutable::parse('2027-12-31'));
    $tooEarly = K3CompanySize::k2Exception([sizeYear(2024, '0', 0, 0), sizeYear(2023, '0', 0, 0)], CarbonImmutable::parse('2025-01-01'), CarbonImmutable::parse('2025-12-31'));

    expect($tooFew->applies)->toBeNull()
        ->and($tooEarly->applies)->toBeNull();
});

it('S1-R1.3 a confirmed newly started company keeps the K2 1.1C exception its first two years', function () {
    $result = K3CompanySize::k2Exception([sizeYear(2026, '9', 9 * MSEK, 9 * MSEK)], CarbonImmutable::parse('2027-01-01'), CarbonImmutable::parse('2027-12-31'), CarbonImmutable::parse('2026-01-01'));

    expect($result->applies)->toBeTrue()
        ->and($result->reason)->toContain('1.1A');
});

it('S1-R1.4 the extended first year follows the confirmation of the first fiscal year, not missing data', function () {
    $start = CarbonImmutable::parse('2025-08-01');
    $end = CarbonImmutable::parse('2026-12-31');

    expect(K3CompanySize::k2Exception([], $start, $end)->applies)->toBeNull()
        ->and(K3CompanySize::k2Exception([], $start, $end, $start)->applies)->toBeTrue();
});

it('S1-R9.1 a confirmed newly started stiftelse is mindre its first two years too', function () {
    $result = K3CompanySize::classify([sizeYear(2025, '1', 0, 0)], CarbonImmutable::parse('2026-01-01'), confirmedFirstYearStart: CarbonImmutable::parse('2025-01-01'), legalForm: LegalForm::Stiftelse);

    expect($result->class)->toBe(K3SizeClass::Mindre);
});

it('S1-R1.3 leaves K2 1.1C to a person for an ideell förening or a stiftelse, as the size test does', function (LegalForm $form) {
    $result = K3CompanySize::k2Exception([sizeYear(2026, '1', 0, 0), sizeYear(2025, '1', 0, 0)], CarbonImmutable::parse('2027-01-01'), CarbonImmutable::parse('2027-12-31'), legalForm: $form);

    expect($result->applies)->toBeNull()
        ->and($result->reason)->toContain('gåvor');
})->with([LegalForm::IdeellForening, LegalForm::Stiftelse]);
