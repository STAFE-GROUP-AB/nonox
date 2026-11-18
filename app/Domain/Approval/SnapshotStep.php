<?php

declare(strict_types=1);

namespace App\Domain\Approval;

use App\Models\ApprovalPolicyStep;

/** One step as a request froze it. */
final readonly class SnapshotStep
{
    /** @param  list<int>  $userIds */
    public function __construct(
        public ApproverKind $kind,
        public ?string $permission,
        public array $userIds,
        public ?ApprovalDimension $dimension,
        public int $requiredCount,
        public bool $excludePreparer,
        public StepConditions $conditions,
    ) {}

    public static function fromStep(ApprovalPolicyStep $step): self
    {
        return new self(
            kind: $step->approver_kind,
            permission: $step->approver_permission,
            userIds: array_map('intval', $step->approver_user_ids ?? []),
            dimension: $step->approver_dimension,
            requiredCount: $step->required_count,
            excludePreparer: $step->exclude_preparer,
            conditions: $step->stepConditions(),
        );
    }

    /** @param  array<string, mixed>  $data */
    public static function fromArray(array $data): self
    {
        $users = $data['approver_user_ids'] ?? [];
        $dimension = $data['approver_dimension'] ?? null;

        return new self(
            kind: ApproverKind::from((string) $data['approver_kind']),
            permission: isset($data['approver_permission']) ? (string) $data['approver_permission'] : null,
            userIds: is_array($users) ? array_values(array_map('intval', $users)) : [],
            dimension: is_string($dimension) ? ApprovalDimension::from($dimension) : null,
            requiredCount: max(1, (int) ($data['required_count'] ?? 1)),
            excludePreparer: (bool) ($data['exclude_preparer'] ?? true),
            conditions: StepConditions::fromArray(is_array($data['conditions'] ?? null) ? $data['conditions'] : []),
        );
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'approver_kind' => $this->kind->value,
            'approver_permission' => $this->permission,
            'approver_user_ids' => $this->userIds,
            'approver_dimension' => $this->dimension?->value,
            'required_count' => $this->requiredCount,
            'exclude_preparer' => $this->excludePreparer,
            'conditions' => $this->conditions->toArray(),
        ];
    }
}
