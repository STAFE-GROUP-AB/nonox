<?php

declare(strict_types=1);

namespace App\Modules\Crm\Domain;

use Carbon\CarbonImmutable;

/**
 * The status rules (ADR-043). A prospect becomes an active customer with its
 * first invoice; an active customer becomes inactive after a number of
 * months without an invoice; lost is set by hand only. A status set by hand
 * is kept until the next ledger event (an invoice issued after it) changes
 * it. Credit invoices are not new business and do not count.
 */
final class StatusRules
{
    /**
     * @param  ?CarbonImmutable  $manualAt  when the status was last set by hand
     * @param  ?CarbonImmutable  $lastInvoiceDate  the latest invoice date of the customer's issued invoices
     * @param  ?CarbonImmutable  $lastIssuedAt  when the latest of those invoices was issued
     */
    public static function statusFor(
        AccountStatus $current,
        ?CarbonImmutable $manualAt,
        ?CarbonImmutable $lastInvoiceDate,
        ?CarbonImmutable $lastIssuedAt,
        CarbonImmutable $today,
        int $monthsToInactive,
    ): AccountStatus {
        if (self::keepsManualStatus($manualAt, $lastIssuedAt)) {
            return $current;
        }

        if ($lastInvoiceDate === null) {
            return $current === AccountStatus::Lost ? AccountStatus::Lost : AccountStatus::Prospect;
        }

        return $lastInvoiceDate->greaterThan($today->startOfDay()->subMonthsNoOverflow($monthsToInactive))
            ? AccountStatus::Active
            : AccountStatus::Inactive;
    }

    /** A status set by hand stands until an invoice is issued after it. */
    public static function keepsManualStatus(?CarbonImmutable $manualAt, ?CarbonImmutable $lastIssuedAt): bool
    {
        return $manualAt !== null && ($lastIssuedAt === null || $lastIssuedAt->lessThanOrEqualTo($manualAt));
    }
}
