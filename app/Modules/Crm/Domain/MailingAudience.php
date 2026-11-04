<?php

declare(strict_types=1);

namespace App\Modules\Crm\Domain;

use App\Modules\Crm\Models\Mailing;
use App\Modules\Crm\Models\Person;
use App\Modules\Crm\Models\Segment;

/**
 * Who a mailing would reach now (ADR-048): the segment's people, filtered
 * by MarketingEligibility, with a count per reason for the others. Queries
 * for the current company: a fixed number however large the segment
 * (people with their segment accounts and customers, and the suppression
 * list once).
 */
final class MailingAudience
{
    /** The largest segment a mailing is sent to. */
    public const int MAX_RECIPIENTS = 20_000;

    /**
     * @return array{recipients: list<array{person: Person, email: string}>, excluded: array<string, int>, total: int}
     */
    public static function for(Mailing $mailing): array
    {
        $segment = $mailing->segment_id === null ? null : Segment::query()->find($mailing->segment_id);

        if (! $segment instanceof Segment) {
            return ['recipients' => [], 'excluded' => [], 'total' => 0];
        }

        $people = SegmentFilter::from($segment->filters)->people()->orderBy('id')->limit(self::MAX_RECIPIENTS + 1)->get();
        $result = MarketingEligibility::evaluate($people, $mailing->customer_exception);

        return [...$result, 'total' => $people->count()];
    }
}
