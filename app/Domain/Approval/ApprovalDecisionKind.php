<?php

declare(strict_types=1);

namespace App\Domain\Approval;

enum ApprovalDecisionKind: string
{
    case Approve = 'APPROVE';
    case Reject = 'REJECT';

    public function label(): string
    {
        return match ($this) {
            self::Approve => __('Godkände'),
            self::Reject => __('Avvisade'),
        };
    }
}
