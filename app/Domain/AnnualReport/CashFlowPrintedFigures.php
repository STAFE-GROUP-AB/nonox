<?php

declare(strict_types=1);

namespace App\Domain\AnnualReport;

/**
 * The cash flow statement as it is printed (ADR-059 A.4 S1-P8): every line
 * rounded to whole kronor with PrintedFigures::round (nearest krona, half a
 * krona away from zero), and every sum the sum of the rounded lines it adds,
 * so that what the report shows adds up. Likvida medel at the start and the
 * end are rounded on their own, as the balance sheet rounds Kassa och bank
 * (K3Schedule key cash, BAS 1900–1999, the same accounts), so the printed
 * closing amount is the balance sheet's.
 *
 * Rounding the lines can leave the year's cash flow a few kronor from the
 * change in the rounded likvida medel. That leftover goes to an existing
 * working capital line with an amount (Förändring av rörelseskulder, else
 * rörelsefordringar, else varulager), never to a row of its own; a leftover
 * larger than rounding can explain (half a krona per rounded figure), or one
 * with no line to take it, refuses the statement with a reason
 * (CashFlowStatementRefused), the discipline PrintedFigures follows for the
 * K3 balance sheet and the equity statement.
 * TODO(legal-verify): which line takes a rounding difference (none found in
 * ÅRL or K3 chapter 7); the working capital lines are a choice.
 */
final class CashFlowPrintedFigures
{
    /** The lines that may take what rounding leaves over, in order. */
    public const array ROUNDING_ROWS = ['forandring_rorelseskulder', 'forandring_rorelsefordringar', 'forandring_varulager'];

    /**
     * @param  list<array{key: string, label: string, section: string, point: string, subtotal: bool, amount: int, previous: ?int}>  $rows  CashFlowStatement::rows()
     * @return list<array{key: string, label: string, section: string, point: string, subtotal: bool, amount: int, previous: ?int}>
     *
     * @throws CashFlowStatementRefused when the rounded statement cannot be made to add up
     */
    public static function apply(array $rows): array
    {
        foreach (['amount', 'previous'] as $field) {
            $values = [];

            foreach ($rows as $row) {
                if ($row[$field] !== null && ! $row['subtotal']) {
                    $values[$row['key']] = PrintedFigures::round((int) $row[$field]);
                }
            }

            if ($values === []) {
                continue;
            }

            $values = self::balanced($values, $field);

            foreach ($rows as $index => $row) {
                if ($row[$field] !== null) {
                    $rows[$index][$field] = $values[$row['key']];
                }
            }
        }

        return $rows;
    }

    /**
     * The rounded lines with the sums added, and the leftover put on a
     * working capital line.
     *
     * @param  array<string, int>  $values  the rounded lines that are not sums
     * @return array<string, int>
     */
    private static function balanced(array $values, string $field): array
    {
        $values = self::withSums($values);
        $left = ($values['likvida_medel_vid_arets_slut'] ?? 0) - ($values['likvida_medel_vid_arets_borjan'] ?? 0) - $values['arets_kassaflode'];

        if ($left === 0) {
            return $values;
        }

        $figures = 0;

        foreach (array_keys($values) as $key) {
            $line = CashFlowStatement::LINES[$key];
            $figures += ! $line['subtotal'] && $line['section'] !== 'interest_dividends' ? 1 : 0;
        }

        if (abs($left) > 50 * $figures) {
            throw new CashFlowStatementRefused((string) __('Kassaflödesanalysen går inte ihop med likvida medel (:amount öre, :field), mer än avrundningen till hela kronor kan förklara.', ['amount' => $left, 'field' => $field === 'amount' ? __('i år') : __('föregående år')]));
        }

        foreach (self::ROUNDING_ROWS as $key) {
            if (($values[$key] ?? 0) !== 0) {
                $values[$key] += $left;

                return self::withSums($values);
            }
        }

        throw new CashFlowStatementRefused((string) __('En avrundningsdifferens i kassaflödesanalysen har ingen rad att läggas på: ingen förändring av rörelsekapitalet har ett belopp.'));
    }

    /**
     * @param  array<string, int>  $values
     * @return array<string, int>
     */
    private static function withSums(array $values): array
    {
        foreach (CashFlowStatement::SUBTOTALS as $key => $lines) {
            $values[$key] = array_sum(array_map(fn (string $line): int => $values[$line] ?? 0, $lines));
        }

        return $values;
    }
}
