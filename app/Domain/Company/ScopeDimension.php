<?php

declare(strict_types=1);

namespace App\Domain\Company;

/**
 * The dimensions a membership can be limited to (ADR-081 gap 10). A unit is a
 * cost centre; there is no second unit concept.
 */
enum ScopeDimension: string
{
    case CostCenter = 'COST_CENTER';
    case Project = 'PROJECT';
}
