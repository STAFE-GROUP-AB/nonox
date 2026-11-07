<?php

declare(strict_types=1);

use App\Domain\AnnualReport\K3CompanySize;
use App\Domain\AnnualReport\K3SizeClass;
use App\Domain\AnnualReport\K3SizeCriterion;
use App\Domain\AnnualReport\K3SizeFigures;
use Carbon\CarbonImmutable;

/*
 * The size test (ADR-059, addendum 2026-11-07, S1-P2): ÅRL 1 kap. 3 §
 * första stycket 4–5 measured as BFNAR 2006:11 says, and the K2 1.1C
 * exception from the same figures. No database is needed.
 */

const MSEK = 100_000_000;

/** A calendar year's figures: employees in hundredths of a full-time equivalent. */
function sizeYear(int $year, ?int $fte, int $balanceOre, int $turnoverOre): K3SizeFigures
{
    return new K3SizeFigures(CarbonImmutable::create($year, 1, 1), CarbonImmutable::create($year, 12, 31), $fte, $balanceOre, $turnoverOre);
}

function classifyFor(int $reportingYear, K3SizeFigures ...$years): K3SizeClass
{
    return K3CompanySize::classify(array_values($years), CarbonImmutable::create($reportingYear, 1, 1))->class;
}

it('S1-R9 counts more than 50 employees strictly: 50,00 is not more, 50,01 is', function () {
    $atLimit = [sizeYear(2025, 5000, 41 * MSEK, 0), sizeYear(2024, 5000, 41 * MSEK, 0)];
    $above = [sizeYear(2025, 5001, 41 * MSEK, 0), sizeYear(2024, 5001, 41 * MSEK, 0)];

    expect(classifyFor(2026, ...$atLimit))->toBe(K3SizeClass::Mindre)
        ->and(classifyFor(2026, ...$above))->toBe(K3SizeClass::Storre);
});

it('S1-R9 counts a balance sheet total above 40 MSEK strictly: 40 MSEK is not more, 40 MSEK + 1 öre is', function () {
    $atLimit = [sizeYear(2025, 5100, 40 * MSEK, 0), sizeYear(2024, 5100, 40 * MSEK, 0)];
    $above = [sizeYear(2025, 5100, 40 * MSEK + 1, 0), sizeYear(2024, 5100, 40 * MSEK + 1, 0)];

    expect(classifyFor(2026, ...$atLimit))->toBe(K3SizeClass::Mindre)
        ->and(classifyFor(2026, ...$above))->toBe(K3SizeClass::Storre);
});

it('S1-R9 counts a net turnover above 80 MSEK strictly: 80 MSEK is not more, 80 MSEK + 1 öre is', function () {
    $atLimit = [sizeYear(2025, 5100, 0, 80 * MSEK), sizeYear(2024, 5100, 0, 80 * MSEK)];
    $above = [sizeYear(2025, 5100, 0, 80 * MSEK + 1), sizeYear(2024, 5100, 0, 80 * MSEK + 1)];

    expect(classifyFor(2026, ...$atLimit))->toBe(K3SizeClass::Mindre)
        ->and(classifyFor(2026, ...$above))->toBe(K3SizeClass::Storre);
});

it('S1-R9 needs more than one criterion: one exceeded in both years is a mindre företag', function () {
    $result = K3CompanySize::classify([sizeYear(2025, 1000, 50 * MSEK, 10 * MSEK), sizeYear(2024, 1000, 50 * MSEK, 10 * MSEK)], CarbonImmutable::parse('2026-01-01'));

    expect($result->class)->toBe(K3SizeClass::Mindre)
        ->and($result->ruleId)->toBe('S1-R9')
        ->and($result->exceeded['2025'])->toBe([K3SizeCriterion::BalanceSheetTotal]);
});

it('S1-R9 a criterion exceeded in only one of the two years does not count', function () {
    $result = classifyFor(2026, sizeYear(2025, 6000, 50 * MSEK, 90 * MSEK), sizeYear(2024, 1000, 10 * MSEK, 10 * MSEK));

    expect($result)->toBe(K3SizeClass::Mindre);
});

it('S1-R9 returns undetermined, never a guess, when two criteria are exceeded each year but not the same two', function () {
    // 2025: employees and balance sheet total; 2024: balance sheet total and net turnover.
    $result = K3CompanySize::classify([sizeYear(2025, 6000, 50 * MSEK, 10 * MSEK), sizeYear(2024, 1000, 50 * MSEK, 90 * MSEK)], CarbonImmutable::parse('2026-01-01'));

    expect($result->class)->toBe(K3SizeClass::Undetermined)
        ->and($result->sameTwoReading)->toBe(K3SizeClass::Mindre)
        ->and($result->anyTwoReading)->toBe(K3SizeClass::Storre)
        ->and($result->reason)->toContain('samma två gränsvärden');
});

it('S1-R9.1 measures the two years immediately before the reporting year, not the reporting year itself', function () {
    // The reporting year 2026 would be large, but it is not measured.
    $result = K3CompanySize::classify([sizeYear(2025, 1000, 10 * MSEK, 10 * MSEK), sizeYear(2024, 1000, 10 * MSEK, 10 * MSEK)], CarbonImmutable::parse('2026-01-01'));

    expect($result->class)->toBe(K3SizeClass::Mindre)
        ->and(array_map(strval(...), array_keys($result->exceeded)))->toBe(['2025', '2024']);
});

it('S1-R9.1 returns undetermined for an unlisted company with fewer than two prior years', function () {
    $none = K3CompanySize::classify([], CarbonImmutable::parse('2026-01-01'));
    $one = K3CompanySize::classify([sizeYear(2025, 9000, 90 * MSEK, 90 * MSEK)], CarbonImmutable::parse('2026-01-01'));

    expect($none->class)->toBe(K3SizeClass::Undetermined)
        ->and($one->class)->toBe(K3SizeClass::Undetermined)
        ->and($one->ruleId)->toBe('S1-R9.1')
        ->and($one->reason)->toContain('två räkenskapsår');
});

it('S1-R9.1 refuses years that are not the two immediately preceding ones', function () {
    // 2024 is missing: 2025 and 2023 are not "the two years that come closest".
    $result = K3CompanySize::classify([sizeYear(2025, 9000, 90 * MSEK, 90 * MSEK), sizeYear(2023, 9000, 90 * MSEK, 90 * MSEK)], CarbonImmutable::parse('2026-01-01'));

    expect($result->class)->toBe(K3SizeClass::Undetermined);
});

it('S1-R9 a listed company is always a större företag', function () {
    $result = K3CompanySize::classify([], CarbonImmutable::parse('2026-01-01'), listed: true);

    expect($result->class)->toBe(K3SizeClass::Storre);
});

it('S1-R9.2 pro-rates the net turnover of a 6-month year to 12 months', function () {
    $short = new K3SizeFigures(CarbonImmutable::parse('2025-07-01'), CarbonImmutable::parse('2025-12-31'), 0, 0, 45 * MSEK);

    expect($short->months())->toBe(6)
        ->and($short->annualisedNetTurnoverOre())->toBe(90 * MSEK);
});

it('S1-R9.2 pro-rates the net turnover of an 18-month year to 12 months, rounding half up to the öre', function () {
    $long = new K3SizeFigures(CarbonImmutable::parse('2024-07-01'), CarbonImmutable::parse('2025-12-31'), 0, 0, 100_000_001);

    expect($long->months())->toBe(18)
        // 100 000 001 × 12 / 18 = 66 666 667,33… → 66 666 667
        ->and($long->annualisedNetTurnoverOre())->toBe(66_666_667)
        ->and((new K3SizeFigures(CarbonImmutable::parse('2024-07-01'), CarbonImmutable::parse('2025-12-31'), 0, 0, 1))->annualisedNetTurnoverOre())->toBe(1);
});

it('S1-R9.2 pro-rating decides the test: a 6-month year with 45 MSEK counts as 90 MSEK', function () {
    $short = new K3SizeFigures(CarbonImmutable::parse('2025-07-01'), CarbonImmutable::parse('2025-12-31'), 0, 41 * MSEK, 45 * MSEK);
    $previous = new K3SizeFigures(CarbonImmutable::parse('2024-07-01'), CarbonImmutable::parse('2025-06-30'), 0, 41 * MSEK, 81 * MSEK);

    expect(K3CompanySize::classify([$short, $previous], CarbonImmutable::parse('2026-01-01'))->class)->toBe(K3SizeClass::Storre);
});

it('S1-R9.2 does not pro-rate the balance sheet total of a short year', function () {
    $short = new K3SizeFigures(CarbonImmutable::parse('2025-07-01'), CarbonImmutable::parse('2025-12-31'), 0, 30 * MSEK, 0);
    $previous = new K3SizeFigures(CarbonImmutable::parse('2024-07-01'), CarbonImmutable::parse('2025-06-30'), 0, 30 * MSEK, 0);

    // Pro-rated, 30 MSEK over 6 months would be 60 MSEK and above the limit.
    expect(K3CompanySize::classify([$short, $previous], CarbonImmutable::parse('2026-01-01'), listed: false)->exceeded)
        ->toBe(['2025' => [], '2024/2025' => []]);
});

it('S1-R9.2 turns hours worked into full-time equivalents with 1 920 hours a year', function () {
    expect(K3CompanySize::fteFromHours(96_000))->toBe(5000)
        ->and(K3CompanySize::fteFromHours(96_001))->toBe(5001)
        // A 6-month year: normal working time is 960 hours.
        ->and(K3CompanySize::fteFromHours(48_000, 6))->toBe(5000)
        ->and(K3CompanySize::fteFromHours(0))->toBe(0);
});

it('S1-R9.2 rounds full-time equivalents up, so a figure just above a limit stays above it', function () {
    // 6 529 hours / 1 920 = 3,4005… FTE: more than 3, and stored as 3,41.
    expect(K3CompanySize::fteFromHours(6_529))->toBe(341)
        ->and(K3CompanySize::fteFromHours(5_761))->toBe(301);
});

it('S1-R9 returns undetermined when a missing employee figure would decide the test', function () {
    $result = K3CompanySize::classify([sizeYear(2025, null, 50 * MSEK, 10 * MSEK), sizeYear(2024, 6000, 50 * MSEK, 10 * MSEK)], CarbonImmutable::parse('2026-01-01'));

    expect($result->class)->toBe(K3SizeClass::Undetermined)
        ->and($result->reason)->toContain('medelantalet anställda');
});

it('S1-R9 decides without the employee figure when it cannot change the answer', function () {
    $result = K3CompanySize::classify([sizeYear(2025, null, 50 * MSEK, 90 * MSEK), sizeYear(2024, null, 50 * MSEK, 90 * MSEK)], CarbonImmutable::parse('2026-01-01'));

    expect($result->class)->toBe(K3SizeClass::Storre);
});

it('S1-R9 classifies F-S1-a as a större företag for 2025 and 2026 under both readings', function () {
    $fixture = json_decode((string) file_get_contents(base_path('tests/Fixtures/s1/f-s1-a/expected.json')), true, flags: JSON_THROW_ON_ERROR);
    $years = $fixture['size_classification']['years'];
    $figures = fn (int $year): K3SizeFigures => sizeYear($year, $years[(string) $year]['medelantal_anstallda'] * 100, $years[(string) $year]['balansomslutning'], $years[(string) $year]['nettoomsattning']);

    foreach ([2025, 2026] as $reportingYear) {
        $result = K3CompanySize::classify([$figures($reportingYear - 1), $figures($reportingYear - 2)], CarbonImmutable::create($reportingYear, 1, 1));

        expect($result->class)->toBe(K3SizeClass::Storre)
            ->and($result->sameTwoReading)->toBe(K3SizeClass::Storre)
            ->and($result->anyTwoReading)->toBe(K3SizeClass::Storre)
            ->and($result->exceeded[(string) ($reportingYear - 1)])->toBe([K3SizeCriterion::BalanceSheetTotal, K3SizeCriterion::NetTurnover])
            ->and($fixture['size_classification']['result'][(string) $reportingYear])->toStartWith('storre_foretag');
    }
});

it('S1-R1.3 keeps the K2 1.1C exception when at most one criterion is exceeded in both years', function () {
    $start = CarbonImmutable::parse('2027-01-01');
    $end = CarbonImmutable::parse('2027-12-31');

    $one = K3CompanySize::k2Exception([sizeYear(2026, 400, 2 * MSEK, MSEK), sizeYear(2025, 400, MSEK, MSEK)], $start, $end);
    $differentTwo = K3CompanySize::k2Exception([sizeYear(2026, 400, 2 * MSEK, MSEK), sizeYear(2025, 400, MSEK, 4 * MSEK)], $start, $end);

    expect($one->applies)->toBeTrue()
        ->and($one->ruleId)->toBe('S1-R1.3')
        // Employees in both years, the balance sheet total and turnover only in one each: one criterion met.
        ->and($differentTwo->applies)->toBeTrue();
});

it('S1-R1.3 loses the K2 1.1C exception when the same two criteria are exceeded in both years', function () {
    $result = K3CompanySize::k2Exception([sizeYear(2026, 400, 2 * MSEK, MSEK), sizeYear(2025, 301, 150_000_001, MSEK)], CarbonImmutable::parse('2027-01-01'), CarbonImmutable::parse('2027-12-31'));

    expect($result->applies)->toBeFalse()
        ->and($result->exceeded)->toBe([K3SizeCriterion::Employees, K3SizeCriterion::BalanceSheetTotal]);
});

it('S1-R1.3 counts the K2 1.1C thresholds strictly', function () {
    $atLimit = K3CompanySize::k2Exception([sizeYear(2026, 300, 150_000_000, 300_000_000), sizeYear(2025, 300, 150_000_000, 300_000_000)], CarbonImmutable::parse('2027-01-01'), CarbonImmutable::parse('2027-12-31'));

    expect($atLimit->applies)->toBeTrue();
});

it('S1-R1.3 does not judge K2 1.1C with fewer than two prior years or for a year begun before 2026', function () {
    $tooFew = K3CompanySize::k2Exception([sizeYear(2026, 0, 0, 0)], CarbonImmutable::parse('2027-01-01'), CarbonImmutable::parse('2027-12-31'));
    $tooEarly = K3CompanySize::k2Exception([sizeYear(2024, 0, 0, 0), sizeYear(2023, 0, 0, 0)], CarbonImmutable::parse('2025-01-01'), CarbonImmutable::parse('2025-12-31'));

    expect($tooFew->applies)->toBeNull()
        ->and($tooEarly->applies)->toBeNull();
});
