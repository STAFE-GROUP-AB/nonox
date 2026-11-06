<?php

declare(strict_types=1);

namespace App\Support\Ixbrl;

use LogicException;

/**
 * A fiscal year as an XBRL period: a fact of an instant concept is dated at
 * the year's last day (the balance date), a fact of a duration concept
 * covers the year. A year whose start is unknown (the comparison column
 * built from opening balances when the previous year is not in NONOX) can
 * only carry instant facts.
 */
final readonly class Period
{
    public function __construct(
        public ?string $start,
        public string $end,
    ) {}

    /** @return array{0: 'instant'|'duration', 1: ?string, 2: string} */
    public function for(string $periodType): array
    {
        if ($periodType === 'instant') {
            return ['instant', null, $this->end];
        }

        if ($this->start === null) {
            throw new LogicException("A duration fact needs a start date (period ending {$this->end}).");
        }

        return ['duration', $this->start, $this->end];
    }
}
