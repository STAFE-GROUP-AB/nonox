<?php

declare(strict_types=1);

namespace App\Domain\Analysis;

/**
 * The pivot of one analysis (ADR-081 gap 7, E17). A cell holds the period's
 * figure `v`, the comparison period's `p` (when compared) and whether each is
 * hidden (a small group of people, see PayrollDataset). A total that would
 * contain a hidden cell is hidden itself.
 *
 * @phpstan-type Cell array{v: int, p: int, hidden: bool, hiddenPrevious: bool}
 */
final class AnalysisResult
{
    /**
     * @param  list<array{key: string, label: string, parts: list<string>}>  $rows
     * @param  list<array{key: string, label: string}>  $columns  one empty column when the pivot has none
     * @param  array<string, array<string, array{v: int, p: int, hidden: bool, hiddenPrevious: bool}>>  $cells  by row key, then column key
     * @param  array<string, array{v: int, p: int, hidden: bool, hiddenPrevious: bool}>  $rowTotals
     * @param  array<string, array{v: int, p: int, hidden: bool, hiddenPrevious: bool}>  $columnTotals
     * @param  array{v: int, p: int, hidden: bool, hiddenPrevious: bool}  $grandTotal
     */
    public function __construct(
        public readonly array $rows,
        public readonly array $columns,
        public readonly array $cells,
        public readonly array $rowTotals,
        public readonly array $columnTotals,
        public readonly array $grandTotal,
        public readonly bool $tooLarge,
        public readonly bool $compared,
    ) {}

    public static function tooLarge(bool $compared): self
    {
        return new self([], [], [], [], [], ['v' => 0, 'p' => 0, 'hidden' => false, 'hiddenPrevious' => false], true, $compared);
    }
}
