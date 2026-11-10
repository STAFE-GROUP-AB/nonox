<?php

declare(strict_types=1);

use App\Modules\Agreements\Domain\AgreementRules;
use App\Modules\Planning\Domain\ObSplitter;
use Carbon\CarbonImmutable;

/*
 * Splitting a worked interval into OB minutes per code from the real
 * agreement files. Every expected number is worked out by hand from the
 * windows in the data and written out.
 */

function obRules(string $path): AgreementRules
{
    $data = json_decode((string) file_get_contents(dirname(__DIR__, 3)."/app/Modules/Agreements/database/data/agreements/{$path}"), true);

    return new AgreementRules($data, $data['sources'] ?? []);
}

function obSplit(string $path, string $start, string $end, ?int $hourlyOre = null, ?int $monthlyOre = null): array
{
    $split = ObSplitter::split(obRules($path), CarbonImmutable::parse($start, 'Europe/Stockholm'), CarbonImmutable::parse($end, 'Europe/Stockholm'), $hourlyOre, $monthlyOre);

    return ['minutes' => $split->minutes, 'total' => $split->totalMinutes, 'notes' => $split->notes];
}

const GRONA_RIKS = 'visita-hrf-grona-riks/MEMBER-2025-04-01.json';
const DETALJHANDEL = 'svenskhandel-handels-detaljhandel/MEMBER-2025-04-01.json';
const TJANSTEMAN = 'svenskhandel-unionen-tjansteman/MEMBER-2025-05-01.json';
const BAGERI = 'livsmedelsforetagen-livs-bageri/MEMBER-2025-04-01.json';

it('splits Gröna Riks shifts', function (string $start, string $end, array $minutes, int $total) {
    expect(obSplit(GRONA_RIKS, $start, $end))->toBe(['minutes' => $minutes, 'total' => $total, 'notes' => []]);
})->with([
    // Tue: the weekday window starts 20:00.
    'weekday evening' => ['2026-11-03 17:00', '2026-11-03 23:00', ['GR_OB' => 180], 360],
    // Sat: the Saturday window starts 16:00.
    'saturday from 16' => ['2026-11-07 12:00', '2026-11-07 20:00', ['GR_OB' => 240], 480],
    // Fri 20:00→Sat 06:00 covers all of it; the night window Sat 01–03.
    'night' => ['2026-11-06 22:00', '2026-11-07 03:00', ['GR_OB' => 300, 'GR_OB_NATT' => 120], 300],
    // The Saturday window until Sun 06:00, then HELGDAG from 06:00: one code, 360; night 04–06.
    'sunday 06' => ['2026-11-08 04:00', '2026-11-08 10:00', ['GR_OB' => 360, 'GR_OB_NATT' => 120], 360],
    // Wed 20:00→Thu 06:00 (480) then Kristi himmelsfärd HELGDAG from 06:00 (120); night Thu 01–06.
    'public holiday on a weekday' => ['2026-05-13 22:00', '2026-05-14 08:00', ['GR_OB' => 600, 'GR_OB_NATT' => 300], 600],
    // Kristi himmelsfärd 06→06 covers the whole day; the weekday window from 20:00 is the same code.
    'public holiday daytime' => ['2026-05-14 10:00', '2026-05-14 22:00', ['GR_OB' => 720], 720],
    // Julafton on a Saturday: STORHELG 06→06 for all 720 minutes; GR_OB from 16:00 (Saturday and LORDAG_LIK windows).
    'storhelg saturday' => ['2022-12-24 10:00', '2022-12-24 22:00', ['GR_OB' => 360, 'GR_STORHELG' => 720], 720],
    // Julafton on a Sunday: STORHELG and HELGDAG (Sunday) from 06:00.
    'storhelg sunday' => ['2028-12-24 10:00', '2028-12-24 22:00', ['GR_OB' => 720, 'GR_STORHELG' => 720], 720],
    // Midsommarafton (Fri): LORDAG_LIK from 16:00; the weekday window 20–22 is the same code, counted once.
    'midsommarafton' => ['2026-06-19 12:00', '2026-06-19 22:00', ['GR_OB' => 360], 600],
    // Sunday HELGDAG 06→Mon 06 runs across midnight; night window Mon 01–02.
    'across midnight' => ['2026-11-08 18:00', '2026-11-09 02:00', ['GR_OB' => 480, 'GR_OB_NATT' => 60], 480],
    // Fri 20→Sat 06 (600), Sat 16→Sun 06 (840), Sun 06→Mon 06 (1440); nights Sat, Sun, Mon 01–06.
    'whole weekend' => ['2026-11-06 18:00', '2026-11-09 08:00', ['GR_OB' => 2880, 'GR_OB_NATT' => 900], 3720],
    // Spring forward (02:00→03:00): 22:00–06:00 is seven real hours; the night window 01–06 is four.
    'dst spring night' => ['2026-03-28 22:00', '2026-03-29 06:00', ['GR_OB' => 420, 'GR_OB_NATT' => 240], 420],
    // Fall back (03:00→02:00): nine real hours; the night window 01–06 is six.
    'dst autumn night' => ['2026-10-24 22:00', '2026-10-25 06:00', ['GR_OB' => 540, 'GR_OB_NATT' => 360], 540],
    // Spring-forward Sunday 00–08: seven real hours, HELGDAG starts at wall-clock 06:00, night window 01–06 is four hours.
    'dst spring sunday morning' => ['2026-03-29 00:00', '2026-03-29 08:00', ['GR_OB' => 420, 'GR_OB_NATT' => 240], 420],
]);

it('counts in Stockholm time whatever the input zone', function () {
    $split = ObSplitter::split(obRules(GRONA_RIKS), CarbonImmutable::parse('2026-11-03T16:00:00Z'), CarbonImmutable::parse('2026-11-03T22:00:00Z'));

    expect($split->minutes)->toBe(['GR_OB' => 180])
        ->and($split->minutesFor('GR_OB'))->toBe(180)
        ->and($split->minutesFor('GR_OB_NATT'))->toBe(0)
        ->and($split->totalMinutes)->toBe(360);
});

it('splits Detaljhandel shifts at 18:15, 20:00 and Saturday 12:00', function (string $start, string $end, array $minutes, int $total) {
    expect(obSplit(DETALJHANDEL, $start, $end))->toBe(['minutes' => $minutes, 'total' => $total, 'notes' => []]);
})->with([
    'weekday evening' => ['2026-11-03 17:00', '2026-11-03 21:00', ['DH_OB_50' => 105, 'DH_OB_70' => 60], 240],
    'weekday before 18:15' => ['2026-11-03 09:00', '2026-11-03 18:15', [], 555],
    'saturday' => ['2026-11-07 10:00', '2026-11-07 16:00', ['DH_OB_100' => 240], 360],
]);

it('runs an until NEXT_WEEKDAY day type to the next working day (Unionen tjänstemän)', function (string $start, string $end, array $minutes, int $total) {
    expect(obSplit(TJANSTEMAN, $start, $end))->toBe(['minutes' => $minutes, 'total' => $total, 'notes' => []]);
})->with([
    // Kristi himmelsfärd Thu: 00–07 window Thu 06–07 and Fri 00–02 (180), 18–24 window (360), HELG_300 Thu 07:00→Fri 00:00 (1020).
    'kristi himmelsfard' => ['2026-05-14 06:00', '2026-05-15 02:00', ['HT_FT_400' => 180, 'HT_FT_600' => 360, 'HT_FT_HELG_300' => 1020], 1200],
    // Skärtorsdagen 18:00 runs over långfredagen, the weekend and annandag påsk until Tue 00:00.
    'skartorsdagen to tuesday' => ['2026-04-06 10:00', '2026-04-06 12:00', ['HT_FT_150' => 120], 120],
]);

it('splits Bageri shifts', function (string $start, string $end, array $minutes, int $total) {
    expect(obSplit(BAGERI, $start, $end))->toBe(['minutes' => $minutes, 'total' => $total, 'notes' => []]);
})->with([
    'weekday 18–24' => ['2026-11-03 16:00', '2026-11-04 00:00', ['BG_OB_1' => 360], 480],
    // The spring-forward Sunday is 23 hours long.
    'dst sunday' => ['2026-03-29 00:00', '2026-03-30 00:00', ['BG_OB_3' => 1380], 1380],
]);

function obSyntheticRules(bool $allHighest): AgreementRules
{
    return new AgreementRules([
        'ob_windows' => [
            ['code' => 'A', 'days' => ['MON'], 'from' => '18:00', 'to' => '24:00', 'overlap' => 'HIGHEST_ONLY'],
            ['code' => 'B', 'days' => ['MON'], 'from' => '20:00', 'to' => '24:00', 'overlap' => 'HIGHEST_ONLY'],
            ['code' => 'C', 'days' => ['MON'], 'from' => '22:00', 'to' => '24:00'] + ($allHighest ? ['overlap' => 'HIGHEST_ONLY'] : []),
        ],
        'codes' => [
            'A' => ['rates' => [['valid_from' => '2025-01-01', 'rate' => ['type' => 'ORE_PER_HOUR', 'ore' => 3000]]]],
            'B' => ['rates' => [['valid_from' => '2025-01-01', 'rate' => ['type' => 'PERCENT_OF_HOURLY', 'bp' => 5000]]]],
            'C' => ['rates' => [['valid_from' => '2025-01-01', 'rate' => ['type' => 'DIVISOR_OF_MONTHLY', 'divisor' => '600']]]],
        ],
    ]);
}

it('keeps only the highest code where HIGHEST_ONLY windows overlap', function () {
    $monday = CarbonImmutable::parse('2026-11-02 17:00', 'Europe/Stockholm');
    $midnight = CarbonImmutable::parse('2026-11-03 00:00', 'Europe/Stockholm');

    // Hourly 200 kr: B is 100 kr/h, above A's 30 kr/h, from 20:00; C is cumulative.
    expect(ObSplitter::split(obSyntheticRules(false), $monday, $midnight, 20000)->minutes)->toBe(['A' => 120, 'B' => 240, 'C' => 120]);

    // Hourly 40 kr (B 20 kr/h), monthly 30 000 kr (C 50 kr/h): A wins 20–22, C wins 22–24.
    expect(ObSplitter::split(obSyntheticRules(true), $monday, $midnight, 4000, 3000000)->minutes)->toBe(['A' => 240, 'C' => 120]);
});

it('keeps every code and notes it when HIGHEST_ONLY codes cannot be compared', function () {
    $split = ObSplitter::split(obSyntheticRules(false), CarbonImmutable::parse('2026-11-02 17:00', 'Europe/Stockholm'), CarbonImmutable::parse('2026-11-03 00:00', 'Europe/Stockholm'));

    expect($split->minutes)->toBe(['A' => 360, 'B' => 240, 'C' => 120])
        ->and($split->notes)->toHaveCount(1);
});

it('returns nothing for an empty or reversed interval', function () {
    $at = CarbonImmutable::parse('2026-11-03 20:00', 'Europe/Stockholm');

    foreach ([[$at, $at], [$at, $at->subHour()]] as [$start, $end]) {
        $split = ObSplitter::split(obRules(GRONA_RIKS), $start, $end);

        expect($split->minutes)->toBe([])->and($split->notes)->toBe([])->and($split->totalMinutes)->toBe(0);
    }
});

// Träindustriavtalet 2023–2025 (TI23 § 5 mom 1 s. 41): a–e exclude each other, so the highest code wins where they overlap.
it('splits Träindustriavtalet shifts into OB a–e, the highest where they overlap', function (string $start, string $end, array $minutes, int $total) {
    expect(obSplit('tmf-gs-traindustri/MEMBER-2023-04-01.json', $start, $end))->toBe(['minutes' => $minutes, 'total' => $total, 'notes' => []]);
})->with([
    // Tue: a) from 17:00.
    'weekday evening a' => ['2024-11-05 15:00', '2024-11-05 23:00', ['TMF_OB_A' => 360], 480],
    // Wed: b) 00–05, a) 05–06.
    'night b then a' => ['2024-11-06 03:00', '2024-11-06 07:00', ['TMF_OB_A' => 60, 'TMF_OB_B' => 120], 240],
    // Mon 00–06 belongs to the weekend c), not to a) or b).
    'monday morning c' => ['2024-11-04 04:00', '2024-11-04 08:00', ['TMF_OB_C' => 120], 240],
    // Fri 17:00 starts the weekend c); a) is Monday–Thursday only.
    'friday evening c' => ['2024-11-08 15:00', '2024-11-08 19:00', ['TMF_OB_C' => 120], 240],
    // Thursday before midsommarafton: e) from 17:00 beats a).
    'midsommar from thursday 17' => ['2024-06-20 15:00', '2024-06-20 20:00', ['TMF_OB_E' => 180], 300],
    // Sunday after midsommardagen: e) until Monday 06:00, beating the weekend c).
    'midsommar until monday 06' => ['2024-06-23 22:00', '2024-06-24 07:00', ['TMF_OB_E' => 480], 540],
    // Friday before alla helgons dag: d) from 17:00 beats the weekend c).
    'alla helgons dag d' => ['2024-11-01 15:00', '2024-11-01 20:00', ['TMF_OB_D' => 180], 300],
    // Wednesday before Kristi himmelsfärd: d) beats a).
    'kristi himmelsfard d' => ['2024-05-08 16:00', '2024-05-08 22:00', ['TMF_OB_D' => 300], 360],
    // Julafton daytime is e).
    'julafton e' => ['2024-12-24 08:00', '2024-12-24 12:00', ['TMF_OB_E' => 240], 240],
    // 30 December from 17:00 is the day before the nyårshelg.
    'nyar from 30 december' => ['2024-12-30 16:00', '2024-12-30 18:00', ['TMF_OB_E' => 60], 120],
    // Annandag påsk night: e) until Tuesday 06:00, beating b) and a).
    'pask until tuesday 06' => ['2024-04-01 22:00', '2024-04-02 07:00', ['TMF_OB_E' => 480], 540],
]);
