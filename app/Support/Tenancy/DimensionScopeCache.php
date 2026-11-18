<?php

declare(strict_types=1);

namespace App\Support\Tenancy;

/**
 * The dimension scopes read in this request or job, per user and company.
 * A scoped container binding, so nothing survives into the next request.
 */
final class DimensionScopeCache
{
    /** @var array<string, DimensionScope> */
    public array $scopes = [];
}
