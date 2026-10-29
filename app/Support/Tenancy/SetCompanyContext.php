<?php

declare(strict_types=1);

namespace App\Support\Tenancy;

use Closure;

/**
 * Job middleware: runs the job inside the given company.
 */
final readonly class SetCompanyContext
{
    public function __construct(private int $companyId) {}

    public function handle(object $job, Closure $next): mixed
    {
        return CurrentCompany::run($this->companyId, fn () => $next($job));
    }
}
