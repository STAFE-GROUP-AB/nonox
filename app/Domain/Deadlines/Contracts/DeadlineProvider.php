<?php

declare(strict_types=1);

namespace App\Domain\Deadlines\Contracts;

use App\Domain\Deadlines\Deadline;
use App\Models\Company;
use Carbon\CarbonImmutable;

/**
 * Something that knows the company's dates in its part of the system, for
 * Mina datum (ADR-051, stage 2). Core rules and modules each have one; a
 * module's provider is only asked while the module is on. It returns the
 * deadlines dated from $from to $to inclusive, reads with a fixed number of
 * queries, and runs inside the company's tenancy scope.
 */
interface DeadlineProvider
{
    /** @return list<Deadline> */
    public function deadlines(Company $company, CarbonImmutable $from, CarbonImmutable $to): array;
}
