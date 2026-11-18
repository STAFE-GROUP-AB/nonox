<?php

declare(strict_types=1);

namespace App\Domain\Approval;

use App\Models\ApprovalDecision;
use App\Models\ApprovalRequest;
use Illuminate\Support\Collection;

/**
 * Where a subject stands in its company's own approval flow, for one viewer.
 * `custom` false means the subject follows the default policy: today's
 * single attest, with no request rows at all.
 */
final readonly class ApprovalState
{
    /**
     * @param  Collection<int, ApprovalRequest>  $history  every request on the subject, oldest first, decisions loaded
     */
    public function __construct(
        public bool $custom,
        public ?ApprovalRequest $pending,
        public ?PolicySnapshot $snapshot,
        public bool $awaitingResubmission,
        public Eligibility $eligibility,
        public Collection $history,
    ) {}

    public static function default(): self
    {
        return new self(false, null, null, false, Eligibility::deny(Eligibility::CLOSED), collect());
    }

    public function currentStep(): int
    {
        return $this->pending !== null ? $this->pending->current_step : 1;
    }

    public function stepCount(): int
    {
        return $this->snapshot?->count() ?? 1;
    }

    public function canDecide(): bool
    {
        return $this->eligibility->allowed;
    }

    /** Approvals already given on the current step. */
    public function approvalsAtStep(): int
    {
        if ($this->pending === null) {
            return 0;
        }

        return $this->pending->decisions
            ->filter(fn (ApprovalDecision $decision): bool => $decision->step === $this->pending->current_step && $decision->decision === ApprovalDecisionKind::Approve)
            ->map(fn (ApprovalDecision $decision): int => $decision->principalId())
            ->unique()
            ->count();
    }

    public function requiredAtStep(): int
    {
        return $this->snapshot?->step($this->currentStep())->requiredCount ?? 1;
    }

    /** The latest rejection's reason, for the preparer. */
    public function rejectionReason(): ?string
    {
        $latest = $this->history->last();

        if (! $latest instanceof ApprovalRequest || $latest->status !== ApprovalRequestStatus::Rejected) {
            return null;
        }

        return $latest->decisions->last(fn (ApprovalDecision $decision): bool => $decision->decision === ApprovalDecisionKind::Reject)?->reason;
    }
}
