<?php

declare(strict_types=1);

namespace App\Modules\Payroll\Support\Tax;

use InvalidArgumentException;

/**
 * Parses Skatteverket's text file of general tax tables. Record layout
 * (postbeskrivning allmänna tabeller 2026): position 1–2 period (30 =
 * month, 14 = two weeks), 3 "B" amount or "%" percent, 4–5 table number,
 * 6–12 lowest income, 13–19 highest income (blank = no upper bound), then
 * six fields of five characters, one per column.
 */
final class TaxTableFile
{
    /**
     * @return list<array{period_type: string, table_no: int, column_no: int, income_from: int, income_to: ?int, value_type: string, value: int}>
     *
     * @throws InvalidArgumentException on any line that does not follow the layout
     */
    public static function parse(string $contents): array
    {
        $contents = (string) preg_replace('/^\xEF\xBB\xBF/', '', $contents);
        $rows = [];

        foreach (preg_split('/\r\n|\r|\n/', $contents) ?: [] as $index => $line) {
            if (trim($line) === '') {
                continue;
            }

            if (! preg_match('/^(30|14)([B%])(\d{2})([ \d]{7})([ \d]{7})([ \d]{5})([ \d]{5})([ \d]{5})([ \d]{5})([ \d]{5})([ \d]{5})$/', $line, $match)) {
                throw new InvalidArgumentException('Line '.($index + 1).' does not follow the record layout.');
            }

            $from = trim($match[4]);
            $to = trim($match[5]);

            if ($from === '') {
                throw new InvalidArgumentException('Line '.($index + 1).' has no lowest income.');
            }

            for ($column = 1; $column <= 6; $column++) {
                $rows[] = [
                    'period_type' => $match[1] === '30' ? 'MONTH' : 'TWO_WEEKS',
                    'table_no' => (int) $match[3],
                    'column_no' => $column,
                    'income_from' => (int) $from,
                    'income_to' => $to === '' ? null : (int) $to,
                    'value_type' => $match[2] === 'B' ? 'AMOUNT' : 'PERCENT',
                    'value' => (int) trim($match[5 + $column]),
                ];
            }
        }

        return $rows;
    }
}
