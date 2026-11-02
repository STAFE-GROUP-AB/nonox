<?php

declare(strict_types=1);

namespace App\Modules\Planning\Domain;

/**
 * Overtime and mertid of one calculation period (a calendar week, or
 * several weeks) for a monthly-paid employee (PayrollBasis::week): the
 * minutes of each kind, what goes to payroll per pay code, and what the
 * manager should know. When comp time is possible, compTime holds what each
 * kind would earn as leave and choice says whether the period is paid
 * (PAY) or taken as comp time (COMP_TIME).
 */
final readonly class WeekBasis
{
    public const string PAY = 'PAY';

    public const string COMP_TIME = 'COMP_TIME';

    /**
     * @param  array<string, int>  $minutes  pay code => minutes to send, sorted by code
     * @param  list<Deviation>  $deviations
     * @param  list<array{kind: string, code: ?string, worked: int, factor: string, minutes: int, source: string}>  $compTime
     */
    public function __construct(
        public int $overtimeMinutes,
        public int $extraTimeMinutes,
        public array $minutes,
        public array $deviations,
        public array $compTime = [],
        public string $choice = self::PAY,
    ) {}

    /** @param  list<array{kind: string, code: ?string, worked: int, factor: string, minutes: int, source: string}>  $compTime */
    public function withCompTime(array $compTime, string $choice): self
    {
        return new self($this->overtimeMinutes, $this->extraTimeMinutes, $this->minutes, $this->deviations, $compTime, $choice);
    }

    public function hasExtraTime(): bool
    {
        return $this->overtimeMinutes + $this->extraTimeMinutes > 0;
    }

    public function canTakeCompTime(): bool
    {
        return $this->compTime !== [];
    }

    public function compTimeMinutes(): int
    {
        return array_sum(array_column($this->compTime, 'minutes'));
    }
}
