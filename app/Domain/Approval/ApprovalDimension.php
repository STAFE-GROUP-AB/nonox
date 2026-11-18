<?php

declare(strict_types=1);

namespace App\Domain\Approval;

/** Whose owner decides a DIMENSION_OWNER step. */
enum ApprovalDimension: string
{
    case CostCenter = 'COST_CENTER';
    case Project = 'PROJECT';

    public function label(): string
    {
        return match ($this) {
            self::CostCenter => __('Kostnadsställe'),
            self::Project => __('Projekt'),
        };
    }
}
