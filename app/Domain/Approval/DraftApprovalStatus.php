<?php

declare(strict_types=1);

namespace App\Domain\Approval;

/** Where a draft's current version stands in the company's approval policy (ADR-081 E21). */
enum DraftApprovalStatus: string
{
    /** No policy of the company's own applies and the version has no request: posted or issued as before. */
    case NotRequired = 'NOT_REQUIRED';
    /** A policy applies and nobody has sent the version to approval yet. */
    case Required = 'REQUIRED';
    case Pending = 'PENDING';
    case Rejected = 'REJECTED';
    case Approved = 'APPROVED';

    /** Whether the version may be posted or issued now. */
    public function allowsPosting(): bool
    {
        return $this === self::NotRequired || $this === self::Approved;
    }
}
