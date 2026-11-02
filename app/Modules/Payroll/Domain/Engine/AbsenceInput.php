<?php

declare(strict_types=1);

namespace App\Modules\Payroll\Domain\Engine;

use Carbon\CarbonImmutable;

/**
 * One day of approved absence: the type and the scheduled hours missed,
 * and the supplements (OB) the schedule would have paid that day, when a
 * module knows them (SickPayTerms).
 */
final readonly class AbsenceInput
{
    /**
     * @param  array<string, mixed>  $supplements  what lostSupplementsOre is made of (code => minutes and öre), for the explanation
     */
    public function __construct(
        public string $type,
        public CarbonImmutable $date,
        public string $hours,
        public int $lostSupplementsOre = 0,
        public array $supplements = [],
    ) {}

    /** @param  array<string, mixed>  $supplements */
    public function withSupplements(int $ore, array $supplements): self
    {
        return new self($this->type, $this->date, $this->hours, $ore, $supplements);
    }
}
