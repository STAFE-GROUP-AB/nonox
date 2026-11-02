<?php

declare(strict_types=1);

use App\Domain\Calendar\SwedishHolidays;
use Carbon\CarbonImmutable;

/*
 * The shared holiday calendar (lagen (1989:253) om allmänna helgdagar) and
 * the eves agreements use. Expected dates are written out by hand from the
 * Easter Sundays 2024–2030, never computed with the class's own algorithm.
 */

dataset('years', [
    '2024' => [2024, ['2024-01-01', '2024-01-06', '2024-03-29', '2024-03-31', '2024-04-01', '2024-05-09', '2024-05-01', '2024-06-06', '2024-05-19', '2024-06-22', '2024-11-02', '2024-12-25', '2024-12-26'], ['2024-03-30', '2024-06-21', '2024-12-24', '2024-12-31']],
    '2025' => [2025, ['2025-01-01', '2025-01-06', '2025-04-18', '2025-04-20', '2025-04-21', '2025-05-29', '2025-05-01', '2025-06-06', '2025-06-08', '2025-06-21', '2025-11-01', '2025-12-25', '2025-12-26'], ['2025-04-19', '2025-06-20', '2025-12-24', '2025-12-31']],
    '2026' => [2026, ['2026-01-01', '2026-01-06', '2026-04-03', '2026-04-05', '2026-04-06', '2026-05-14', '2026-05-01', '2026-06-06', '2026-05-24', '2026-06-20', '2026-10-31', '2026-12-25', '2026-12-26'], ['2026-04-04', '2026-06-19', '2026-12-24', '2026-12-31']],
    '2027' => [2027, ['2027-01-01', '2027-01-06', '2027-03-26', '2027-03-28', '2027-03-29', '2027-05-06', '2027-05-01', '2027-06-06', '2027-05-16', '2027-06-26', '2027-11-06', '2027-12-25', '2027-12-26'], ['2027-03-27', '2027-06-25', '2027-12-24', '2027-12-31']],
    '2028' => [2028, ['2028-01-01', '2028-01-06', '2028-04-14', '2028-04-16', '2028-04-17', '2028-05-25', '2028-05-01', '2028-06-06', '2028-06-04', '2028-06-24', '2028-11-04', '2028-12-25', '2028-12-26'], ['2028-04-15', '2028-06-23', '2028-12-24', '2028-12-31']],
    '2029' => [2029, ['2029-01-01', '2029-01-06', '2029-03-30', '2029-04-01', '2029-04-02', '2029-05-10', '2029-05-01', '2029-06-06', '2029-05-20', '2029-06-23', '2029-11-03', '2029-12-25', '2029-12-26'], ['2029-03-31', '2029-06-22', '2029-12-24', '2029-12-31']],
    '2030' => [2030, ['2030-01-01', '2030-01-06', '2030-04-19', '2030-04-21', '2030-04-22', '2030-05-30', '2030-05-01', '2030-06-06', '2030-06-09', '2030-06-22', '2030-11-02', '2030-12-25', '2030-12-26'], ['2030-04-20', '2030-06-21', '2030-12-24', '2030-12-31']],
]);

it('lists the law\'s holidays of a year in the law\'s order', function (int $year, array $holidays) {
    expect(SwedishHolidays::forYear($year))->toBe($holidays);
})->with('years');

it('names every holiday and eve of a year', function (int $year, array $holidays, array $eves) {
    expect(SwedishHolidays::named($year))->toBe(array_combine([
        'NYARSDAGEN', 'TRETTONDEDAG_JUL', 'LANGFREDAGEN', 'PASKDAGEN', 'ANNANDAG_PASK', 'KRISTI_HIMMELSFARDSDAG',
        'FORSTA_MAJ', 'NATIONALDAGEN', 'PINGSTDAGEN', 'MIDSOMMARDAGEN', 'ALLA_HELGONS_DAG', 'JULDAGEN', 'ANNANDAG_JUL',
    ], $holidays))
        ->and(SwedishHolidays::eves($year))->toBe(array_combine(['PASKAFTON', 'MIDSOMMARAFTON', 'JULAFTON', 'NYARSAFTON'], $eves));
})->with('years');

it('tells holidays, public holidays and eves apart', function (int $year, array $holidays, array $eves) {
    foreach ($holidays as $date) {
        expect(SwedishHolidays::isHoliday(CarbonImmutable::parse($date)))->toBeTrue()
            ->and(SwedishHolidays::isPublicHoliday(CarbonImmutable::parse($date)))->toBeTrue()
            ->and(SwedishHolidays::isEve(CarbonImmutable::parse($date)))->toBeFalse();
    }

    foreach ($eves as $date) {
        $day = CarbonImmutable::parse($date);

        expect(SwedishHolidays::isEve($day))->toBeTrue()
            ->and(SwedishHolidays::isPublicHoliday($day))->toBeFalse()
            ->and(SwedishHolidays::isHoliday($day))->toBe($day->isSunday());
    }
})->with('years');

it('counts every Sunday as a holiday but not as a public holiday', function () {
    $sunday = CarbonImmutable::parse('2026-11-08');

    expect(SwedishHolidays::isHoliday($sunday))->toBeTrue()
        ->and(SwedishHolidays::isPublicHoliday($sunday))->toBeFalse()
        ->and(SwedishHolidays::nameOf($sunday))->toBeNull()
        ->and(SwedishHolidays::isHoliday(CarbonImmutable::parse('2026-11-09')))->toBeFalse()
        ->and(SwedishHolidays::isHoliday(CarbonImmutable::parse('2026-11-07')))->toBeFalse();
});

it('gives the Swedish name of a holiday or eve', function () {
    expect(SwedishHolidays::nameOf(CarbonImmutable::parse('2026-06-19')))->toBe('Midsommarafton')
        ->and(SwedishHolidays::nameOf(CarbonImmutable::parse('2026-05-14')))->toBe('Kristi himmelsfärdsdag')
        ->and(SwedishHolidays::nameOf(CarbonImmutable::parse('2026-06-06')))->toBe('Sveriges nationaldag')
        ->and(SwedishHolidays::nameOf(CarbonImmutable::parse('2026-12-31')))->toBe('Nyårsafton')
        ->and(SwedishHolidays::nameOf(CarbonImmutable::parse('2026-11-03')))->toBeNull();
});
