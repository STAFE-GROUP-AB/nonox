<?php

declare(strict_types=1);

namespace App\Domain\Analysis;

use App\Models\Company;
use App\Models\User;
use App\Support\Tenancy\DimensionScope;
use Carbon\CarbonImmutable;

/**
 * Who runs an analysis, for which company and over which period. The scope is
 * the member's own (ADR-081 gap 10): a dataset reads it to leave out what the
 * member may not see, before anything is grouped.
 */
final class AnalysisContext
{
    public function __construct(
        public readonly Company $company,
        public readonly User $user,
        public readonly DimensionScope $scope,
        public readonly CarbonImmutable $from,
        public readonly CarbonImmutable $to,
        public readonly bool $compare = false,
    ) {}

    /** The comparison period: the same months one year earlier. */
    public function previousFrom(): CarbonImmutable
    {
        return $this->from->subYearNoOverflow()->startOfMonth();
    }

    public function previousTo(): CarbonImmutable
    {
        return $this->to->subYearNoOverflow()->endOfMonth();
    }

    /** The earliest date the query has to read. */
    public function readFrom(): CarbonImmutable
    {
        return $this->compare ? $this->previousFrom() : $this->from;
    }

    /** The latest date the query has to read. */
    public function readTo(): CarbonImmutable
    {
        return $this->to;
    }
}
