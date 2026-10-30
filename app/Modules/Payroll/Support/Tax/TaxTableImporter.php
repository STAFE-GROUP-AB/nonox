<?php

declare(strict_types=1);

namespace App\Modules\Payroll\Support\Tax;

use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Imports a year's tax table file after checking it: every table must start
 * at 1 kr, its intervals must follow each other without gaps or overlaps,
 * and the last interval must be open. An imported year is never replaced;
 * Skatteverket publishes corrections as a new year file only after review.
 */
final class TaxTableImporter
{
    /**
     * @return array{rows: int, tables: int}
     */
    public function import(int $year, string $fileName, string $contents): array
    {
        $rows = TaxTableFile::parse($contents);

        if ($rows === []) {
            throw new InvalidArgumentException('The file holds no tax tables.');
        }

        $periods = array_values(array_unique(array_column($rows, 'period_type')));

        if (count($periods) !== 1) {
            throw new InvalidArgumentException('The file mixes monthly and two-week tables.');
        }

        $this->check($rows);
        $period = $periods[0];

        if (DB::table('tax_table_imports')->where('year', $year)->where('period_type', $period)->exists()) {
            throw new InvalidArgumentException("Tax tables for {$year} ({$period}) are already imported.");
        }

        $tables = count(array_unique(array_column($rows, 'table_no')));

        DB::transaction(function () use ($year, $fileName, $contents, $rows, $period, $tables): void {
            $importId = DB::table('tax_table_imports')->insertGetId([
                'year' => $year,
                'period_type' => $period,
                'file_name' => $fileName,
                'file_sha256' => hash('sha256', $contents),
                'row_count' => count($rows),
                'table_count' => $tables,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            foreach (array_chunk($rows, 2000) as $chunk) {
                DB::table('tax_tables')->insert(array_map(fn (array $row): array => [...$row, 'tax_table_import_id' => $importId, 'year' => $year], $chunk));
            }
        });

        return ['rows' => count($rows), 'tables' => $tables];
    }

    /**
     * @param  list<array{period_type: string, table_no: int, column_no: int, income_from: int, income_to: ?int, value_type: string, value: int}>  $rows
     */
    private function check(array $rows): void
    {
        $byTable = [];

        foreach ($rows as $row) {
            if ($row['column_no'] === 1) {
                $byTable[$row['table_no']][] = $row;
            }
        }

        foreach ($byTable as $table => $intervals) {
            usort($intervals, fn (array $a, array $b): int => $a['income_from'] <=> $b['income_from']);

            if ($intervals[0]['income_from'] !== 1) {
                throw new InvalidArgumentException("Table {$table} does not start at 1 kr.");
            }

            foreach ($intervals as $index => $interval) {
                $last = $index === count($intervals) - 1;

                if ($last !== ($interval['income_to'] === null)) {
                    throw new InvalidArgumentException("Table {$table}: only the last interval may be open.");
                }

                if (! $last && $intervals[$index + 1]['income_from'] !== $interval['income_to'] + 1) {
                    throw new InvalidArgumentException("Table {$table}: gap or overlap after {$interval['income_to']} kr.");
                }
            }
        }
    }
}
