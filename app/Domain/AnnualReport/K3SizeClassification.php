<?php

declare(strict_types=1);

namespace App\Domain\AnnualReport;

/**
 * The answer of the size test (S1-R9): the class, a Swedish reason (a
 * question when the class is undetermined), the rule id that decided it,
 * what each reading of "mer än ett" gives (the same two criteria in both
 * years, or any two in each year; null when not measured), and the criteria
 * exceeded per measured year, newest first.
 */
final readonly class K3SizeClassification
{
    /**
     * @param  array<string, list<K3SizeCriterion>>  $exceeded  by year label
     * @param  list<K3SizeFigures>  $years  the measured years, newest first
     */
    public function __construct(
        public K3SizeClass $class,
        public string $reason,
        public string $ruleId,
        public ?K3SizeClass $sameTwoReading = null,
        public ?K3SizeClass $anyTwoReading = null,
        public array $exceeded = [],
        public array $years = [],
    ) {}
}
