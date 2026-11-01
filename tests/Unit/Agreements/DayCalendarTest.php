<?php

declare(strict_types=1);

use App\Modules\Agreements\Domain\DayCalendar;
use Carbon\CarbonImmutable;

/*
 * The agreement calendar (ADR-033, A2): named days computed from the year,
 * and an agreement's day types as spans with clock times.
 */

function groenaRiksDayTypes(): array
{
    $file = json_decode((string) file_get_contents(dirname(__DIR__, 3).'/app/Modules/Agreements/database/data/agreements/visita-hrf-grona-riks/MEMBER-2025-04-01.json'), true);

    return $file['day_types'];
}

it('computes the eves and holidays from Easter and the midsummer rule', function (int $year, array $expected) {
    expect(array_intersect_key(DayCalendar::namedDays($year), $expected))->toEqual($expected);
})->with([
    '2026' => [2026, ['SKARTORSDAGEN' => '2026-04-02', 'LANGFREDAGEN' => '2026-04-03', 'PASKAFTON' => '2026-04-04', 'PASKDAGEN' => '2026-04-05', 'DAGEN_FORE_KRISTI_HIMMELSFARD' => '2026-05-13', 'KRISTI_HIMMELSFARDSDAG' => '2026-05-14', 'PINGSTAFTON' => '2026-05-23', 'PINGSTDAGEN' => '2026-05-24', 'MIDSOMMARAFTON' => '2026-06-19', 'MIDSOMMARDAGEN' => '2026-06-20', 'ALLA_HELGONS_DAG' => '2026-10-31', 'TRETTONDEDAGSAFTON' => '2026-01-05', 'VALBORGSMASSOAFTON' => '2026-04-30']],
    '2027' => [2027, ['PASKAFTON' => '2027-03-27', 'MIDSOMMARAFTON' => '2027-06-25', 'ALLA_HELGONS_DAG' => '2027-11-06']],
]);

it('names a date with every name it has', function () {
    expect(DayCalendar::namesOf(CarbonImmutable::parse('2026-12-24')))->toBe(['JULAFTON'])
        ->and(DayCalendar::namesOf(CarbonImmutable::parse('2026-06-20')))->toBe(['MIDSOMMARDAGEN', 'SATURDAY', 'PUBLIC_HOLIDAY'])
        ->and(DayCalendar::namesOf(CarbonImmutable::parse('2026-04-12')))->toBe(['SUNDAY']);
});

it('makes Christmas Eve a storhelg in Gröna Riks only on a Saturday or Sunday, from 06 to 06', function () {
    $types = groenaRiksDayTypes();

    expect(DayCalendar::typesOn($types, CarbonImmutable::parse('2027-12-24')))->toBe(['LORDAG_LIK'])
        ->and(DayCalendar::typesOn($types, CarbonImmutable::parse('2028-12-24')))->toBe(['LORDAG_LIK', 'HELGDAG', 'STORHELG']);

    $storhelg = collect(DayCalendar::spansOn($types, CarbonImmutable::parse('2028-12-24')))->firstWhere('type', 'STORHELG');

    expect($storhelg['start']->format('Y-m-d H:i'))->toBe('2028-12-24 06:00')
        ->and($storhelg['end']->format('Y-m-d H:i'))->toBe('2028-12-25 06:00');
});

it('runs a span until the next weekday that is not a holiday', function () {
    $types = ['HELG' => ['name' => 'Helg', 'days' => [['day' => 'MIDSOMMARAFTON', 'from' => '07:00', 'until' => 'NEXT_WEEKDAY']]]];

    $span = DayCalendar::spansOn($types, CarbonImmutable::parse('2026-06-19'))[0];

    expect($span['start']->format('Y-m-d H:i'))->toBe('2026-06-19 07:00')
        ->and($span['end']->format('Y-m-d H:i'))->toBe('2026-06-22 00:00');
});

it('lists a year\'s calendar deterministically', function () {
    $first = DayCalendar::year(groenaRiksDayTypes(), 2026);

    expect($first)->toEqual(DayCalendar::year(groenaRiksDayTypes(), 2026))
        ->and(collect($first)->firstWhere('date', '2026-06-19')['names'])->toBe(['MIDSOMMARAFTON'])
        ->and(collect($first)->firstWhere('date', '2026-06-19')['spans'][0]['type'])->toBe('LORDAG_LIK');
});
