<?php

declare(strict_types=1);

namespace App\Domain\Approval;

enum ApprovalRequestStatus: string
{
    case Pending = 'PENDING';
    case Approved = 'APPROVED';
    case Rejected = 'REJECTED';
    case Withdrawn = 'WITHDRAWN';

    public function label(): string
    {
        return match ($this) {
            self::Pending => __('Pågår'),
            self::Approved => __('Godkänd'),
            self::Rejected => __('Avvisad'),
            self::Withdrawn => __('Återkallad'),
        };
    }
}
