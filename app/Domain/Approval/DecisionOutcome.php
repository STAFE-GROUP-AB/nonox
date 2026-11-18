<?php

declare(strict_types=1);

namespace App\Domain\Approval;

/** What a decision led to. */
enum DecisionOutcome: string
{
    /** No policy of the company's own applies: the caller runs today's single attest. */
    case Default = 'DEFAULT';
    /** Saved; the current step needs more approvals. */
    case Recorded = 'RECORDED';
    /** Saved; the request moved on to its next step. */
    case Advanced = 'ADVANCED';
    /** Saved; that was the last approval of the last step. The caller applies the subject's own effect. */
    case Completed = 'COMPLETED';
    /** Saved; the request is rejected and the subject goes back to its preparer. */
    case Rejected = 'REJECTED';
}
