<?php

declare(strict_types=1);

namespace App\Modules\Planning\Domain;

/**
 * What ShiftCost estimated for one shift, in öre, and what it could not
 * price. A shift whose wage is missing has no total.
 */
final readonly class CostEstimate
{
    /**
     * @param  array<string, int>  $obMinutes  OB code => minutes
     * @param  list<string>  $missing  parts that are not in the total
     * @param  list<string>  $notes
     */
    public function __construct(
        public int $workedMinutes,
        public ?int $hourlyOre,
        public int $baseOre,
        public int $obOre,
        public ?int $vacationOre,
        public ?int $contributionsOre,
        public array $obMinutes,
        public array $missing,
        public array $notes,
    ) {}

    /** The estimate, or null when there is no wage to start from. */
    public function totalOre(): ?int
    {
        if ($this->hourlyOre === null) {
            return null;
        }

        return $this->baseOre + $this->obOre + ($this->vacationOre ?? 0) + ($this->contributionsOre ?? 0);
    }

    public function isComplete(): bool
    {
        return $this->missing === [];
    }

    /** @return array<string, mixed> for planning_shifts.cost_details */
    public function toArray(): array
    {
        return [
            'worked_minutes' => $this->workedMinutes,
            'hourly_ore' => $this->hourlyOre,
            'base_ore' => $this->baseOre,
            'ob_ore' => $this->obOre,
            'vacation_ore' => $this->vacationOre,
            'contributions_ore' => $this->contributionsOre,
            'ob_minutes' => $this->obMinutes,
            'missing' => $this->missing,
            'notes' => $this->notes,
        ];
    }
}
