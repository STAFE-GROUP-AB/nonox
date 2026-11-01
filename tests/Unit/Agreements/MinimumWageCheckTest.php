<?php

declare(strict_types=1);

use App\Modules\Agreements\Domain\AgreementRules;
use App\Modules\Agreements\Domain\MinimumWageCheck;
use Carbon\CarbonImmutable;

/*
 * The minimum wage check (ADR-033, A4): deterministic, age from the date of
 * birth by the agreement's rule, experience from the recorded date, the
 * highest row the person qualifies for. Values from the shipped data files.
 */

function shippedRules(string $file): AgreementRules
{
    $data = json_decode((string) file_get_contents(dirname(__DIR__, 3)."/app/Modules/Agreements/database/data/agreements/{$file}.json"), true);
    $sources = $data['sources'];
    unset($data['sources']);

    return new AgreementRules($data, $sources);
}

function wageCheck(string $file, ?string $group, string $born, string $periodEnd, string $salaryForm, ?int $pay, ?string $experienceFrom = null, string $start = '2024-01-01'): array
{
    return MinimumWageCheck::check(shippedRules($file), $group, CarbonImmutable::parse($born), CarbonImmutable::parse($periodEnd), $experienceFrom === null ? null : CarbonImmutable::parse($experienceFrom), CarbonImmutable::parse($start), $salaryForm, $salaryForm === 'MONTHLY' ? $pay : null, $salaryForm === 'HOURLY' ? $pay : null);
}

it('counts a new age from the month after the birthday in Gröna Riks', function () {
    // Born 15 April 2008: 18 from May 2026, so April is still paid as 17.
    expect(wageCheck('visita-hrf-grona-riks/MEMBER-2025-04-01', '2U', '2008-04-15', '2026-04-30', 'HOURLY', 11000))
        ->status->toBe(MinimumWageCheck::BELOW)
        ->age->toBe(17)
        ->minimum_ore->toBe(11087)
        ->and(wageCheck('visita-hrf-grona-riks/MEMBER-2025-04-01', '2U', '2008-04-15', '2026-05-31', 'HOURLY', 11087))
        ->status->toBe(MinimumWageCheck::BELOW)
        ->age->toBe(18)
        ->minimum_ore->toBe(11556);
});

it('takes the highest row for experience and the step at the end of the period', function () {
    expect(wageCheck('visita-hrf-grona-riks/MEMBER-2025-04-01', '1', '1990-01-01', '2026-03-31', 'MONTHLY', 2_920_100, '2019-01-01'))
        ->status->toBe(MinimumWageCheck::OK)
        ->minimum_ore->toBe(2_920_100)
        ->and(wageCheck('visita-hrf-grona-riks/MEMBER-2025-04-01', '1', '1990-01-01', '2026-04-30', 'MONTHLY', 2_920_100, '2019-01-01'))
        ->status->toBe(MinimumWageCheck::BELOW)
        ->minimum_ore->toBe(3_042_800)
        ->step_from->toBe('2026-04-01');
});

it('counts branschvana in the shop agreement only from 18', function () {
    // Experience recorded from 16, but it counts from the 18th birthday (2024-06-01): 23 whole months by the end of April 2026.
    $check = wageCheck('svenskhandel-handels-detaljhandel/MEMBER-2025-04-01', 'ALLA', '2006-06-01', '2026-04-30', 'MONTHLY', 2_700_000, '2022-06-01');

    expect($check)->experience_months->toBe(23)
        ->minimum_ore->toBe(2_753_000)
        ->status->toBe(MinimumWageCheck::BELOW);
});

it('uses time in the company for the warehouse agreement\'s six months', function () {
    expect(wageCheck('svenskhandel-handels-lager-ehandel/MEMBER-2025-04-01', 'LAGER', '2000-01-01', '2026-04-30', 'MONTHLY', 2_918_900, start: '2026-01-01'))
        ->status->toBe(MinimumWageCheck::OK)
        ->and(wageCheck('svenskhandel-handels-lager-ehandel/MEMBER-2025-04-01', 'LAGER', '2000-01-01', '2026-07-31', 'MONTHLY', 2_918_900, start: '2026-01-01'))
        ->status->toBe(MinimumWageCheck::BELOW)
        ->minimum_ore->toBe(2_973_000);
});

it('says when it cannot check: no group, an unknown group, no row, no amount for the salary form', function () {
    expect(wageCheck('visita-hrf-grona-riks/MEMBER-2025-04-01', null, '1990-01-01', '2026-04-30', 'MONTHLY', 1)['status'])->toBe(MinimumWageCheck::NO_GROUP)
        ->and(wageCheck('visita-hrf-grona-riks/MEMBER-2025-04-01', 'X', '1990-01-01', '2026-04-30', 'MONTHLY', 1)['status'])->toBe(MinimumWageCheck::UNKNOWN_GROUP)
        ->and(wageCheck('visita-unionen/MEMBER-2025-06-01', 'ALLA', '2008-01-01', '2026-06-30', 'MONTHLY', 1)['status'])->toBe(MinimumWageCheck::NO_ROW)
        ->and(wageCheck('visita-unionen/MEMBER-2025-06-01', 'ALLA', '1990-01-01', '2026-06-30', 'HOURLY', 1)['status'])->toBe(MinimumWageCheck::NO_BASIS);
});

it('flags youth pay past the age it applies to', function () {
    expect(wageCheck('visita-hrf-grona-riks/MEMBER-2025-04-01', '2U', '2000-01-01', '2026-04-30', 'MONTHLY', 3_000_000)['youth_too_old'])->toBeTrue();
});
