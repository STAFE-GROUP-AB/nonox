<?php

declare(strict_types=1);

namespace App\Domain\Analysis;

use Carbon\CarbonImmutable;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Runs an analysis (ADR-081 gap 7, E17): one grouped query for the whole
 * pivot, the period and its comparison together (conditional aggregates), at
 * most CELL_CAP cells and a statement timeout. Row, column and filter keys
 * come from the dataset's catalogue; their values are bound, never written
 * into the SQL. Reads only: nothing is posted or changed.
 */
final class AnalysisRunner
{
    public const int CELL_CAP = 10_000;

    public const int DRILL_LIMIT = 200;

    /** Milliseconds. */
    public const int TIMEOUT = 15_000;

    private const array KEY_ALIASES = ['dk0', 'dk1', 'dk2'];

    private const array LABEL_ALIASES = ['dl0', 'dl1', 'dl2'];

    /** The key and label columns of each group in the select list: grouped by position, as a bound id list must not be repeated in GROUP BY. */
    private const array GROUP_POSITIONS = ['1, 2', '3, 4', '5, 6'];

    public function run(Dataset $dataset, AnalysisDefinition $definition, AnalysisContext $context): AnalysisResult
    {
        $dimensions = $dataset->dimensions();
        $measure = $dataset->measures()[$definition->measure];
        $query = $this->narrowed($dataset, $definition, $context);

        $groups = $definition->rows;

        if ($definition->column !== null) {
            $groups[] = $definition->column;
        }

        foreach ($groups as $index => $key) {
            $sql = ($dimensions[$key]->sql)($context);
            $keySelect = "{$sql->key} AS ".self::KEY_ALIASES[$index];
            $labelSelect = "{$sql->label} AS ".self::LABEL_ALIASES[$index];

            $query->selectRaw($keySelect, $sql->bindingsFor($keySelect))
                ->selectRaw($labelSelect, $sql->bindingsFor($labelSelect))
                ->groupByRaw(self::GROUP_POSITIONS[$index]);
        }

        $filter = "{$dataset->dateExpression()} BETWEEN ? AND ?";
        $periods = [[$context->from, $context->to, 'v0', 's0']];

        if ($context->compare) {
            $periods[] = [$context->previousFrom(), $context->previousTo(), 'v1', 's1'];
        }

        foreach ($periods as [$from, $to, $valueAlias, $hiddenAlias]) {
            $value = ($measure->aggregate)($filter).' AS '.$valueAlias;
            $query->selectRaw($value, $this->period($value, $from, $to));

            if ($measure->suppressed !== null) {
                $hidden = ($measure->suppressed)($filter).' AS '.$hiddenAlias;
                $query->selectRaw($hidden, $this->period($hidden, $from, $to));
            }
        }

        $records = DB::transaction(function () use ($query) {
            DB::statement('SET LOCAL statement_timeout = '.self::TIMEOUT);

            return $query->limit(self::CELL_CAP + 1)->get();
        });

        if ($records->count() > self::CELL_CAP) {
            return AnalysisResult::tooLarge($context->compare);
        }

        return $this->pivot($records->values()->all(), $definition, $dimensions, $context->compare);
    }

    /**
     * The records behind one cell. Null when the dataset has none to open or
     * the keys do not fit the definition.
     *
     * @return array{columns: array<int, string>, rows: array<int, array{cells: array<int, string>, url: ?string}>}|null
     */
    public function drill(Dataset $dataset, AnalysisDefinition $definition, AnalysisContext $context, string $rowKey, string $columnKey): ?array
    {
        if (! $dataset->drillable()) {
            return null;
        }

        $dimensions = $dataset->dimensions();
        $parts = explode('|', $rowKey);

        if (count($parts) !== count($definition->rows)) {
            return null;
        }

        $query = $this->narrowed($dataset, $definition, $context, false);

        $cell = array_combine($definition->rows, $parts);

        if ($definition->column !== null) {
            $cell[$definition->column] = $columnKey;
        }

        foreach ($cell as $key => $value) {
            $sql = ($dimensions[$key]->sql)($context);
            $condition = "({$sql->key})::text = ?";
            $query->whereRaw($condition, [...$sql->bindingsFor($sql->key), $value]);
        }

        return DB::transaction(function () use ($dataset, $context, $query) {
            DB::statement('SET LOCAL statement_timeout = '.self::TIMEOUT);

            return $dataset->detail($context, $query);
        });
    }

    /** The dataset's query with the period and the filters. */
    private function narrowed(Dataset $dataset, AnalysisDefinition $definition, AnalysisContext $context, bool $withComparison = true): Builder
    {
        $dimensions = $dataset->dimensions();
        $from = $withComparison ? $context->readFrom() : $context->from;
        $query = $dataset->query($context)->whereRaw("{$dataset->dateExpression()} BETWEEN ? AND ?", [$from->toDateString(), $context->readTo()->toDateString()]);

        foreach ($definition->filters as $key => $values) {
            $sql = ($dimensions[$key]->sql)($context);
            $condition = "({$sql->key})::text IN (SELECT jsonb_array_elements_text(CAST(? AS jsonb)))";
            $query->whereRaw($condition, [...$sql->bindingsFor($sql->key), json_encode($values, JSON_THROW_ON_ERROR)]);
        }

        return $query;
    }

    /**
     * The bound dates for every period condition (`BETWEEN ? AND ?`) in the SQL.
     *
     * @return list<string>
     */
    private function period(string $sql, CarbonImmutable $from, CarbonImmutable $to): array
    {
        $bindings = [];

        for ($count = intdiv(substr_count($sql, '?'), 2); $count > 0; $count--) {
            array_push($bindings, $from->toDateString(), $to->toDateString());
        }

        return $bindings;
    }

    /**
     * @param  array<int, object>  $records
     * @param  array<string, Dimension>  $dimensions
     */
    private function pivot(array $records, AnalysisDefinition $definition, array $dimensions, bool $compared): AnalysisResult
    {
        $rowCount = count($definition->rows);
        $rows = [];
        $columns = [];
        $cells = [];

        $label = fn (string $dimension, string $text): string => $dimensions[$dimension]->translate ? __($text) : $text;

        foreach ($records as $record) {
            $keys = [];
            $parts = [];

            foreach ($definition->rows as $index => $dimension) {
                $keys[] = (string) $record->{"dk{$index}"};
                $parts[] = $label($dimension, (string) $record->{"dl{$index}"});
            }

            $rowKey = implode('|', $keys);
            $columnKey = '';
            $columnLabel = '';

            if ($definition->column !== null) {
                $columnKey = (string) $record->{"dk{$rowCount}"};
                $columnLabel = $label($definition->column, (string) $record->{"dl{$rowCount}"});
            }

            $rows[$rowKey] ??= ['key' => $rowKey, 'label' => implode(' / ', $parts), 'parts' => $parts];
            $columns[$columnKey] ??= ['key' => $columnKey, 'label' => $columnLabel];
            $cells[$rowKey][$columnKey] = [
                'v' => (int) ($record->v0 ?? 0),
                'p' => (int) ($record->v1 ?? 0),
                'hidden' => (bool) ($record->s0 ?? false),
                'hiddenPrevious' => (bool) ($record->s1 ?? false),
            ];
        }

        $empty = ['v' => 0, 'p' => 0, 'hidden' => false, 'hiddenPrevious' => false];
        $add = static fn (array $total, array $cell): array => [
            'v' => $total['v'] + $cell['v'],
            'p' => $total['p'] + $cell['p'],
            'hidden' => $total['hidden'] || $cell['hidden'],
            'hiddenPrevious' => $total['hiddenPrevious'] || $cell['hiddenPrevious'],
        ];

        $rowTotals = [];
        $columnTotals = [];
        $grand = $empty;

        foreach ($cells as $rowKey => $byColumn) {
            foreach ($byColumn as $columnKey => $cell) {
                $rowTotals[$rowKey] = $add($rowTotals[$rowKey] ?? $empty, $cell);
                $columnTotals[$columnKey] = $add($columnTotals[$columnKey] ?? $empty, $cell);
                $grand = $add($grand, $cell);
            }
        }

        $rows = array_values($rows);
        $columns = array_values($columns);

        if (count($rows) * max(1, count($columns)) > self::CELL_CAP) {
            return AnalysisResult::tooLarge($compared);
        }

        usort($columns, fn (array $a, array $b): int => strnatcasecmp($a['label'], $b['label']));
        usort($rows, $definition->sort === 'total'
            ? fn (array $a, array $b): int => [$rowTotals[$a['key']]['hidden'], -$rowTotals[$a['key']]['v'], $a['label']] <=> [$rowTotals[$b['key']]['hidden'], -$rowTotals[$b['key']]['v'], $b['label']]
            : fn (array $a, array $b): int => strnatcasecmp($a['label'], $b['label']));

        return new AnalysisResult($rows, $columns, $cells, $rowTotals, $columnTotals, $grand, false, $compared);
    }
}
