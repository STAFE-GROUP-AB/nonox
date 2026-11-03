<?php

declare(strict_types=1);

use App\Modules\Crm\Domain\SalesCycle;
use Carbon\CarbonImmutable;

/*
 * The sales cycle (ADR-043 stage 2): calendar days in Swedish time from
 * created to won, averaged to one decimal.
 */

it('counts calendar days in Swedish time', function () {
    $at = fn (string $time, string $zone = 'Europe/Stockholm') => CarbonImmutable::parse($time, $zone);

    expect(SalesCycle::days($at('2026-06-01 09:00'), $at('2026-06-01 17:00')))->toBe(0)
        ->and(SalesCycle::days($at('2026-06-01 23:59'), $at('2026-06-02 00:01')))->toBe(1)
        ->and(SalesCycle::days($at('2026-06-01 09:00'), $at('2026-07-01 08:00')))->toBe(30)
        // 22:30 UTC on 31 May is already 1 June in Stockholm.
        ->and(SalesCycle::days($at('2026-05-31 22:30', 'UTC'), $at('2026-06-01 10:00')))->toBe(0);
});

it('is not fooled by the clock changes in spring and autumn', function () {
    $at = fn (string $time) => CarbonImmutable::parse($time, 'Europe/Stockholm');

    expect(SalesCycle::days($at('2026-03-28 12:00'), $at('2026-03-30 12:00')))->toBe(2)
        ->and(SalesCycle::days($at('2026-10-24 00:30'), $at('2026-10-26 00:30')))->toBe(2);
});

it('averages to one decimal, counts a won date before the creation as the same day, and is null without deals', function () {
    $at = fn (string $date) => CarbonImmutable::parse($date.' 12:00', 'Europe/Stockholm');

    expect(SalesCycle::averageDays([[$at('2026-06-01'), $at('2026-06-11')], [$at('2026-06-01'), $at('2026-06-02')], [$at('2026-06-01'), $at('2026-06-01')]]))->toBe(3.7)
        ->and(SalesCycle::averageDays([[$at('2026-06-10'), $at('2026-06-01')]]))->toBe(0.0)
        ->and(SalesCycle::averageDays([]))->toBeNull();
});
