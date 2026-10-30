<?php

declare(strict_types=1);

namespace App\Modules\Payroll\Support\Tax;

use Illuminate\Support\Facades\DB;

/**
 * Reads the row of a tax table that covers a period income in whole kronor.
 */
final class TaxTableLookup
{
    /**
     * @return array{value_type: 'AMOUNT'|'PERCENT', value: int, income_from: int, income_to: ?int}
     *
     * @throws MissingTaxTableException
     */
    public function row(int $year, int $table, int $column, int $incomeKronor, string $period = 'MONTH'): array
    {
        $row = DB::table('tax_tables')
            ->where('year', $year)
            ->where('period_type', $period)
            ->where('table_no', $table)
            ->where('column_no', $column)
            ->where('income_from', '<=', max($incomeKronor, 1))
            ->where(fn ($query) => $query->whereNull('income_to')->orWhere('income_to', '>=', max($incomeKronor, 1)))
            ->first(['value_type', 'value', 'income_from', 'income_to']);

        if ($row === null) {
            throw new MissingTaxTableException("No tax table {$table}, column {$column} for {$year} ({$period}).");
        }

        return [
            'value_type' => $row->value_type === 'PERCENT' ? 'PERCENT' : 'AMOUNT',
            'value' => (int) $row->value,
            'income_from' => (int) $row->income_from,
            'income_to' => $row->income_to === null ? null : (int) $row->income_to,
        ];
    }
}
