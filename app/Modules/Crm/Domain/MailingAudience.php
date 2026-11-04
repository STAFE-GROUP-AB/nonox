<?php

declare(strict_types=1);

namespace App\Modules\Crm\Domain;

use App\Modules\Crm\Models\Mailing;
use App\Modules\Crm\Models\Segment;

/**
 * Who a mailing would reach now (ADR-048): the segment's people, filtered
 * by MarketingEligibility, with a count per reason for the others. Two
 * queries however large the segment (the people and their segment accounts
 * as plain rows, and the suppression list once); no models are loaded.
 */
final class MailingAudience
{
    /** The largest segment a mailing is sent to. */
    public const int MAX_RECIPIENTS = 20_000;

    /**
     * @return array{recipients: list<array{person_id: int, account_id: int, email: string}>, excluded: array<string, int>, total: int}
     */
    public static function for(Mailing $mailing): array
    {
        $segment = $mailing->segment_id === null ? null : Segment::query()->find($mailing->segment_id);

        if (! $segment instanceof Segment) {
            return ['recipients' => [], 'excluded' => [], 'total' => 0];
        }

        return self::forFilter(SegmentFilter::from($segment->filters), $mailing->customer_exception);
    }

    /**
     * @return array{recipients: list<array{person_id: int, account_id: int, email: string}>, excluded: array<string, int>, total: int}
     */
    public static function forFilter(SegmentFilter $filter, bool $customerException): array
    {
        $candidates = MarketingCandidates::group($filter->rows()->get()->all());

        return [...MarketingEligibility::evaluate($candidates, $customerException), 'total' => count($candidates)];
    }
}
