<?php

declare(strict_types=1);

namespace App\Domain\Approval;

use App\Models\ApprovalPolicy;
use App\Models\ApprovalPolicyStep;
use InvalidArgumentException;

/**
 * The copy of a policy a request follows: the policy's id, name and version
 * and only the steps that applied to the subject when the request started,
 * numbered 1, 2, … in order. The subject never changes after it is
 * registered, so the steps that applied then are the ones that apply.
 */
final readonly class PolicySnapshot
{
    /** @param  list<SnapshotStep>  $steps */
    public function __construct(
        public ?int $policyId,
        public string $name,
        public int $version,
        public array $steps,
    ) {}

    /** The policy's steps that apply to the subject, or null when none does. */
    public static function of(ApprovalPolicy $policy, SubjectFacts $facts): ?self
    {
        $steps = [];

        foreach ($policy->steps as $step) {
            /** @var ApprovalPolicyStep $step */
            if ($step->stepConditions()->matches($facts)) {
                $steps[] = SnapshotStep::fromStep($step);
            }
        }

        return $steps === [] ? null : new self($policy->id, $policy->name, $policy->version, $steps);
    }

    /** @param  array<string, mixed>  $data */
    public static function fromArray(array $data): self
    {
        $steps = $data['steps'] ?? null;

        if (! is_array($steps) || $steps === []) {
            throw new InvalidArgumentException('A policy snapshot needs at least one step.');
        }

        return new self(
            policyId: isset($data['policy_id']) ? (int) $data['policy_id'] : null,
            name: (string) ($data['name'] ?? ''),
            version: (int) ($data['version'] ?? 1),
            steps: array_values(array_map(fn (array $step): SnapshotStep => SnapshotStep::fromArray($step), $steps)),
        );
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'policy_id' => $this->policyId,
            'name' => $this->name,
            'version' => $this->version,
            'steps' => array_map(fn (SnapshotStep $step): array => $step->toArray(), $this->steps),
        ];
    }

    public function count(): int
    {
        return count($this->steps);
    }

    /** Step $number, counted from 1. */
    public function step(int $number): SnapshotStep
    {
        return $this->steps[$number - 1] ?? throw new InvalidArgumentException("No step {$number} in the policy snapshot.");
    }
}
