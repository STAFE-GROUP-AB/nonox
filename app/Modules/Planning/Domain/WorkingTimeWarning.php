<?php

declare(strict_types=1);

namespace App\Modules\Planning\Domain;

/**
 * A warning from the working-time checks: which shift, which rule, the
 * sentence to show and the provision the rule comes from. A warning never
 * blocks a plan.
 */
final readonly class WorkingTimeWarning
{
    public function __construct(
        public string $shiftId,
        public string $rule,
        public string $message,
        public string $source,
    ) {}

    /** @return array{shift_id: string, rule: string, message: string, source: string} */
    public function toArray(): array
    {
        return ['shift_id' => $this->shiftId, 'rule' => $this->rule, 'message' => $this->message, 'source' => $this->source];
    }
}
