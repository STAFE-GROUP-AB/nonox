<?php

declare(strict_types=1);

namespace App\Modules\Planning\Domain;

/**
 * The OB minutes of one worked interval, per code (ObSplitter).
 */
final readonly class ObSplit
{
    /**
     * @param  array<string, int>  $minutes  code => minutes, only codes with minutes, sorted by code
     * @param  list<string>  $notes
     * @param  int  $totalMinutes  real elapsed minutes of the interval
     */
    public function __construct(
        public array $minutes,
        public array $notes,
        public int $totalMinutes,
    ) {}

    public function minutesFor(string $code): int
    {
        return $this->minutes[$code] ?? 0;
    }
}
