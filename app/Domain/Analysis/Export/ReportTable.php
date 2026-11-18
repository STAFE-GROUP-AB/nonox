<?php

declare(strict_types=1);

namespace App\Domain\Analysis\Export;

use App\Domain\Analysis\AnalysisDefinition;
use App\Domain\Analysis\AnalysisResult;
use App\Domain\Analysis\Dataset;
use App\Domain\Analysis\Measure;

/**
 * An analysis as a flat table for a file (ADR-081 gap 7, E18): one header row,
 * one row per pivot row and a total row. Built from the AnalysisResult the
 * viewer's own run produced, so a file holds what the screen shows and nothing
 * else: a cell the dataset hides (a small group of people) is written as the
 * text "Dold" and never as a number, and so is every total that contains one.
 * A figure is an integer in öre (money) or a count; text is only labels.
 */
final class ReportTable
{
    public const string HIDDEN = 'Dold';

    /**
     * @param  list<string>  $header
     * @param  list<list<int|string|null>>  $rows  a figure is an int, a label or the text "Dold" a string, an empty cell null
     */
    public function __construct(
        public readonly string $title,
        public readonly string $subtitle,
        public readonly array $header,
        public readonly array $rows,
        public readonly bool $money,
    ) {}

    public static function from(string $title, Dataset $dataset, AnalysisDefinition $definition, AnalysisResult $result): self
    {
        $measure = $dataset->measures()[$definition->measure];
        $dimensions = $dataset->dimensions();
        $money = $measure->kind === Measure::MONEY;
        $compared = $result->compared;
        $hidden = __(self::HIDDEN);

        $figure = static fn (array $cell, string $which): int|string => $which === 'p'
            ? ($cell['hiddenPrevious'] ? $hidden : $cell['p'])
            : ($cell['hidden'] ? $hidden : $cell['v']);

        $header = [collect($definition->rows)->map(fn (string $key): string => $dimensions[$key]->label)->implode(' / ')];

        foreach ($result->columns as $column) {
            $label = $column['label'] !== '' ? $column['label'] : $measure->label;
            $header[] = $label;

            if ($compared) {
                $header[] = __(':label, förra året', ['label' => $label]);
            }
        }

        if ($definition->column !== null) {
            $header[] = __('Summa');

            if ($compared) {
                $header[] = __('Summa, förra året');
            }
        }

        $line = static function (string $label, array $byColumn, array $total) use ($result, $compared, $figure, $definition): array {
            $row = [$label];

            foreach ($result->columns as $column) {
                $cell = $byColumn[$column['key']] ?? null;
                $row[] = $cell === null ? null : $figure($cell, 'v');

                if ($compared) {
                    $row[] = $cell === null ? null : $figure($cell, 'p');
                }
            }

            if ($definition->column !== null) {
                $row[] = $figure($total, 'v');

                if ($compared) {
                    $row[] = $figure($total, 'p');
                }
            }

            return $row;
        };

        $rows = [];

        foreach ($result->rows as $row) {
            $rows[] = $line($row['label'], $result->cells[$row['key']] ?? [], $result->rowTotals[$row['key']]);
        }

        $rows[] = $line(__('Summa'), $result->columnTotals, $result->grandTotal);

        $subtitle = __(':measure, :from till :to', ['measure' => $measure->label, 'from' => $definition->from->format('Y-m'), 'to' => $definition->to->format('Y-m')]);

        return new self($title, $subtitle, $header, $rows, $money);
    }

    /** Whether a cell is a figure, not a label or "Dold". */
    public static function isFigure(int|string|null $value): bool
    {
        return is_int($value);
    }
}
