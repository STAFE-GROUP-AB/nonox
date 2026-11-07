<?php

declare(strict_types=1);

namespace App\Domain\AnnualReport;

/**
 * The answer of the size test (S1-R9): the class, a Swedish reason (a
 * question when the class is undetermined), the rule id that decided it,
 * and the criteria exceeded per measured year, keyed by the year's end
 * date, newest first.
 */
final readonly class K3SizeClassification
{
    /**
     * @param  array<string, list<K3SizeCriterion>>  $exceeded  by end date (Y-m-d)
     * @param  list<K3SizeFigures>  $years  the measured years, newest first
     */
    public function __construct(
        public K3SizeClass $class,
        public string $reason,
        public string $ruleId,
        public array $exceeded = [],
        public array $years = [],
    ) {}
}
