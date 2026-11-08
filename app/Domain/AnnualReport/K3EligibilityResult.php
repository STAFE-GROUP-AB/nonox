<?php

declare(strict_types=1);

namespace App\Domain\AnnualReport;

/**
 * What Bokslut → Regelverk shows for one fiscal year (S1-R1): the status,
 * every condition of K2 1.1–1.1C with where its answer comes from, what is
 * still missing, and the facts behind the size test, the K2 1.1C exception
 * and the building test. Built by K3Eligibility; nothing in it is stored.
 */
final readonly class K3EligibilityResult
{
    /**
     * @param  list<array{key: string, rule: string, label: string, state: string, source: string, text: string, question: ?K3EligibilityQuestion}>  $items  state: applies|not|unknown|not_needed; source: data|answer|missing|not_needed
     * @param  list<string>  $reasons  why K3 is required
     * @param  list<string>  $missing  what must be answered or entered before NONOX can tell
     * @param  ?array{year: string, accounts: list<array{number: int, name: string, amount_ore: int, selected: bool}>, net_sales_ore: int, building_ore: int, threshold_bp: int, applies: ?bool, classified: bool}  $building
     * @param  ?array{start_date: string, end_date: string}  $missingSizeYear  the year before the first NONOX year whose figures a person can enter
     */
    public function __construct(
        public K3EligibilityStatus $status,
        public string $summary,
        public array $items,
        public array $reasons,
        public array $missing,
        public ?K3SizeClassification $size,
        public ?K3K2Exception $exception,
        public ?array $building,
        public string $deferredTaxInfo,
        public ?array $missingSizeYear,
        public ?string $firstYearStart,
    ) {}

    /**
     * The questions shown to the person, in the order of the rules.
     *
     * @return list<K3EligibilityQuestion>
     */
    public function questions(): array
    {
        return array_values(array_filter(array_map(fn (array $item): ?K3EligibilityQuestion => $item['question'], $this->items)));
    }

    public function asks(K3EligibilityQuestion $question): bool
    {
        return in_array($question, $this->questions(), true);
    }
}
