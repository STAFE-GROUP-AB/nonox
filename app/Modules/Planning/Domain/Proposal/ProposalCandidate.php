<?php

declare(strict_types=1);

namespace App\Modules\Planning\Domain\Proposal;

use Carbon\CarbonImmutable;

/**
 * An employee the engine may propose: skills, birth date (for the minors'
 * rules), contracted weekly hours (null or zero for hourly staff without
 * contracted hours), the employments as [first day, last day or null], and
 * the shifts they already have. No pay: the engine never sees a wage.
 */
final readonly class ProposalCandidate
{
    /**
     * @param  list<int>  $skillIds
     * @param  list<array{CarbonImmutable, ?CarbonImmutable}>  $employments
     * @param  list<CandidateShift>  $shifts
     */
    public function __construct(
        public int $employeeId,
        public array $skillIds = [],
        public ?CarbonImmutable $birthDate = null,
        public ?string $weeklyHours = null,
        public array $employments = [],
        public array $shifts = [],
    ) {}

    /** Contracted minutes per week, or null without contracted hours. */
    public function weeklyMinutes(): ?int
    {
        $minutes = is_numeric($this->weeklyHours) ? (int) round((float) $this->weeklyHours * 60) : 0;

        return $minutes > 0 ? $minutes : null;
    }

    public function isEmployedOn(CarbonImmutable $day): bool
    {
        $date = $day->toDateString();

        foreach ($this->employments as [$from, $to]) {
            if ($from->toDateString() <= $date && ($to === null || $to->toDateString() >= $date)) {
                return true;
            }
        }

        return false;
    }

    /** Days employed between two dates, both included. */
    public function employedDays(CarbonImmutable $from, CarbonImmutable $to): int
    {
        $days = 0;

        foreach ($this->employments as [$start, $end]) {
            $first = $start->greaterThan($from) ? $start : $from;
            $last = $end === null || $end->greaterThan($to) ? $to : $end;

            if ($last->greaterThanOrEqualTo($first)) {
                $days += (int) round(($last->startOfDay()->getTimestamp() - $first->startOfDay()->getTimestamp()) / 86400) + 1;
            }
        }

        return min($days, (int) round(($to->startOfDay()->getTimestamp() - $from->startOfDay()->getTimestamp()) / 86400) + 1);
    }
}
