<?php

declare(strict_types=1);

namespace App\Support\Tenancy;

/**
 * For queued jobs that touch company data (AGENTS.md §6.0). The job carries
 * the company id explicitly and runs inside it; the session is never used.
 *
 *     class ImportSieFile implements ShouldQueue
 *     {
 *         use RunsForCompany;
 *
 *         public function __construct(public int $companyId, ...) {}
 *     }
 *
 * @property int $companyId
 */
trait RunsForCompany
{
    /** @return list<object> */
    public function middleware(): array
    {
        return [new SetCompanyContext($this->companyId)];
    }
}
