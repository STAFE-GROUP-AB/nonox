<?php

declare(strict_types=1);

namespace App\Domain\AnnualReport;

/**
 * The answer of the first paragraph of K2 1.1C (S1-R1.3): whether the
 * exception from K2 1.1B applies (null when NONOX cannot tell), a Swedish
 * reason, and the criteria exceeded in each of the two preceding years.
 */
final readonly class K3K2Exception
{
    /** @param list<K3SizeCriterion> $exceeded */
    public function __construct(
        public ?bool $applies,
        public string $reason,
        public array $exceeded = [],
        public string $ruleId = 'S1-R1.3',
    ) {}
}
