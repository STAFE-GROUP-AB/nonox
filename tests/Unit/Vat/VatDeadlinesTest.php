<?php

declare(strict_types=1);

use App\Domain\Company\VatPeriod;
use App\Domain\Vat\VatDeadlines;
use Carbon\CarbonImmutable;

it('gives Skatteverket\'s last day for a VAT period', function (VatPeriod $period, string $periodEnd, bool $large, ?string $expected) {
    expect(VatDeadlines::for($period, CarbonImmutable::parse($periodEnd), $large)?->toDateString())->toBe($expected);
})->with([
    'monthly, small: 12th of the second month' => [VatPeriod::Monthly, '2026-01-31', false, '2026-03-12'],
    'monthly, small: 17th in August' => [VatPeriod::Monthly, '2026-06-30', false, '2026-08-17'],
    'monthly, small: 17th in January, Saturday moves to Monday' => [VatPeriod::Monthly, '2025-11-30', false, '2026-01-19'],
    'monthly, large: 26th of the next month' => [VatPeriod::Monthly, '2026-04-30', true, '2026-05-26'],
    'monthly, large: 27th in December, Sunday moves to Monday' => [VatPeriod::Monthly, '2026-11-30', true, '2026-12-28'],
    'quarterly: 12th of the second month' => [VatPeriod::Quarterly, '2026-03-31', false, '2026-05-12'],
    'quarterly: 17th in August' => [VatPeriod::Quarterly, '2026-06-30', false, '2026-08-17'],
    'yearly: not given (legal-verify)' => [VatPeriod::Yearly, '2026-12-31', false, null],
]);
