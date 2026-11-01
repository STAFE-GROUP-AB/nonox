<?php

declare(strict_types=1);

namespace App\Support\Tenancy;

/**
 * Per-request (and per-queued-job) state behind CurrentCompany. Bound as a
 * scoped singleton, so Laravel discards it between requests and jobs.
 *
 * @internal Use CurrentCompany instead.
 */
final class CompanyContext
{
    public bool $resolved = false;

    /** Set explicitly with set() or run(): never re-resolved from the user. */
    public bool $explicit = false;

    /** The user the cached company was resolved for. */
    public int|string|null $resolvedForUserId = null;

    public ?int $companyId = null;

    public int $bypassDepth = 0;

    /** @var array<int, bool> whether a company is archived, by id (ArchivedCompany) */
    public array $archived = [];

    public int $archiveReadingDepth = 0;

    public int $archiveMaintenanceDepth = 0;
}
