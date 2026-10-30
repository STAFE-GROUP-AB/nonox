<?php

declare(strict_types=1);

use App\Modules\Payroll\Support\Calendar\SwedishHolidays;
use Carbon\CarbonImmutable;

it('knows the public holidays of 2026 under Lag (1989:253)', function () {
    expect(SwedishHolidays::forYear(2026))->toEqualCanonicalizing([
        '2026-01-01', '2026-01-06', '2026-04-03', '2026-04-05', '2026-04-06', '2026-05-01', '2026-05-14',
        '2026-05-24', '2026-06-06', '2026-06-20', '2026-10-31', '2026-12-25', '2026-12-26',
    ])
        ->and(SwedishHolidays::isHoliday(CarbonImmutable::parse('2026-04-12')))->toBeTrue()
        ->and(SwedishHolidays::isHoliday(CarbonImmutable::parse('2026-06-19')))->toBeFalse()
        ->and(SwedishHolidays::forYear(2027))->toContain('2027-03-26', '2027-03-28', '2027-05-06', '2027-06-26', '2027-11-06');
});
