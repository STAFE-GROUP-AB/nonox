<?php

declare(strict_types=1);

namespace App\Domain\Pricing;

/** Where the approval of a document's prices under the floor stands (ADR-082 §9). */
enum PriceFloorApprovalStatus: string
{
    case Pending = 'PENDING';
    case Approved = 'APPROVED';
    case Rejected = 'REJECTED';
    case Withdrawn = 'WITHDRAWN';
}
