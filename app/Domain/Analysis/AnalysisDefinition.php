<?php

declare(strict_types=1);

namespace App\Domain\Analysis;

use Carbon\CarbonImmutable;

/**
 * What the user chose in the analysis view (ADR-081 gap 7, E17), as keys only:
 * the dataset, up to two row dimensions, at most one column dimension, one
 * measure, filters, a period of whole months and an optional comparison with
 * the same months a year earlier. A definition holds no SQL and no column
 * name: normalise() keeps only what the dataset's catalogue names and falls
 * back to a sensible default for anything else, so nothing typed into the URL
 * reaches a query.
 */
final class AnalysisDefinition
{
    public const int MAX_MONTHS = 60;

    public const int MAX_FILTERS = 2;

    public const int MAX_FILTER_VALUES = 50;

    public const array SORTS = ['label', 'total'];

    /**
     * @param  list<string>  $rows
     * @param  array<string, list<string>>  $filters  dimension key => values
     */
    private function __construct(
        public readonly string $dataset,
        public readonly array $rows,
        public readonly ?string $column,
        public readonly string $measure,
        public readonly CarbonImmutable $from,
        public readonly CarbonImmutable $to,
        public readonly bool $compare,
        public readonly array $filters,
        public readonly string $sort,
    ) {}

    /**
     * @param  array<string, mixed>  $input  rows, column, measure, from, to (YYYY-MM), compare, filters, sort
     */
    public static function normalise(Dataset $dataset, array $input, ?CarbonImmutable $today = null): self
    {
        $dimensions = $dataset->dimensions();
        $measures = $dataset->measures();
        $today ??= CarbonImmutable::now();

        $rows = [];

        foreach (self::strings($input['rows'] ?? []) as $key) {
            if (isset($dimensions[$key]) && ! in_array($key, $rows, true) && count($rows) < 2) {
                $rows[] = $key;
            }
        }

        $column = is_string($input['column'] ?? null) && isset($dimensions[$input['column']]) && ! in_array($input['column'], $rows, true) ? $input['column'] : null;
        $rows = $rows === [] ? array_values(array_filter($dataset->defaultRows(), fn (string $key): bool => $key !== $column && isset($dimensions[$key]))) : $rows;
        $measure = is_string($input['measure'] ?? null) && isset($measures[$input['measure']]) ? $input['measure'] : array_key_first($measures);

        $to = self::month($input['to'] ?? null) ?? $today->startOfMonth();
        $from = self::month($input['from'] ?? null) ?? $to->subMonths(11);

        if ($from->greaterThan($to)) {
            $from = $to;
        }

        if ($from->diffInMonths($to) >= self::MAX_MONTHS) {
            $from = $to->subMonths(self::MAX_MONTHS - 1);
        }

        $filters = [];

        foreach (is_array($input['filters'] ?? null) ? $input['filters'] : [] as $key => $values) {
            if (count($filters) >= self::MAX_FILTERS || ! is_string($key) || ! isset($dimensions[$key]) || $key === 'period') {
                continue;
            }

            $values = array_slice(self::strings($values), 0, self::MAX_FILTER_VALUES);

            if ($values !== []) {
                $filters[$key] = $values;
            }
        }

        $sort = is_string($input['sort'] ?? null) && in_array($input['sort'], self::SORTS, true) ? $input['sort'] : 'label';

        return new self($dataset->key(), $rows, $column, (string) $measure, $from->startOfMonth(), $to->endOfMonth()->startOfMonth(), (bool) ($input['compare'] ?? false) && ! in_array('period', array_merge($rows, $column === null ? [] : [$column]), true), $filters, $sort);
    }

    /** The last day of the last month: the period is read up to it. */
    public function periodEnd(): CarbonImmutable
    {
        return $this->to->endOfMonth();
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'dataset' => $this->dataset,
            'rows' => $this->rows,
            'column' => $this->column,
            'measure' => $this->measure,
            'from' => $this->from->format('Y-m'),
            'to' => $this->to->format('Y-m'),
            'compare' => $this->compare,
            'filters' => $this->filters,
            'sort' => $this->sort,
        ];
    }

    /** @return list<string> */
    private static function strings(mixed $values): array
    {
        if (! is_array($values)) {
            return [];
        }

        $strings = [];

        foreach ($values as $value) {
            if ((is_string($value) || is_int($value)) && $value !== '' && strlen((string) $value) <= 60) {
                $strings[] = (string) $value;
            }
        }

        return array_values(array_unique($strings));
    }

    private static function month(mixed $value): ?CarbonImmutable
    {
        if (! is_string($value) || preg_match('/^(\d{4})-(0[1-9]|1[0-2])$/', $value, $match) !== 1 || (int) $match[1] < 1990 || (int) $match[1] > 2200) {
            return null;
        }

        return CarbonImmutable::createStrict((int) $match[1], (int) $match[2], 1)->startOfDay();
    }
}
