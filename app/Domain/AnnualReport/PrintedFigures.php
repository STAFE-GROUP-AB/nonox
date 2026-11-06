<?php

declare(strict_types=1);

namespace App\Domain\AnnualReport;

/**
 * The annual report's figures as they are printed: every line rounded to
 * whole kronor, and every subtotal, section sum and total the sum of the
 * rounded lines, so that what the report shows adds up. Used by
 * AnnualReportDocument for the preview and the PDF; the amounts stay in öre
 * (multiples of 100). The figures for decisions (the free equity in DividendLimit, the
 * booked disposition) stay exact.
 *
 * Rounding is to the nearest krona, half a krona away from zero (the
 * report states "Om inte annat särskilt anges redovisas alla belopp i hela
 * kronor"). Until 2026-11-06 each amount was cut to whole kronor on its own,
 * so a sum could differ from its lines (ADR-010, addendum 2026-11-06).
 *
 * The balance sheet: rounding the lines on each side can leave the two
 * sides a few kronor apart. The difference is put on the short-term line
 * Övriga skulder (BAS 2400–2409, 2470–2479, 2490–2499, 2600–2859,
 * 2870–2899), never left out of the sums.
 * TODO(legal-verify): which line K2 practice puts a rounding difference on
 * (none found in ÅRL, BFNAR 2016:10 or BAS); Övriga skulder is a choice.
 *
 * The specification of changes in equity: the amounts at the start and the
 * end come from the rounded balance sheet, the changes are rounded, and the
 * year's result row is the result of the income statement. What rounding
 * leaves over in a column goes to its "Balanseras i ny räkning" cell, else
 * "Övriga förändringar", else "Utdelning"; a column with none of them gets
 * an "Övriga förändringar" row.
 * TODO(legal-verify): the same question for the equity specification.
 */
final class PrintedFigures
{
    /** Whole kronor in öre: nearest krona, half a krona away from zero. */
    public static function round(int $ore): int
    {
        $kronor = intdiv(abs($ore) + 50, 100);

        return ($ore < 0 ? -$kronor : $kronor) * 100;
    }

    /**
     * @param  array<string, mixed>  $data  AnnualReportDocument::data() with exact figures
     * @return array<string, mixed> the same with the printed figures
     */
    public static function apply(array $data): array
    {
        $hasPrevious = $data['hasPreviousIncome'] === true;
        $data['income'] = self::income($data['income'], $hasPrevious);
        $results = self::incomeValues($data['income']);
        $data['fixedAssets'] = self::fixedAssets($data['fixedAssets']);
        $data['balance'] = self::balance($data['balance'], $results, $hasPrevious, $data['fixedAssets']);
        $data['equityChanges'] = self::equity($data['equityChanges'], $data['balance'], $results['amount']['year_result'] ?? 0);
        $data['multiYear'] = self::multiYear($data['multiYear'], $results, $hasPrevious);
        $data['netSalesVariations'] = self::variations($data['netSalesVariations'], $data['multiYear']);
        $data['disposition'] = self::disposition($data['balance'], (int) $data['disposition']['dividend']);
        $data['pledged'] = array_map(fn (array $row): array => ['amount' => self::round($row['amount'])] + $row, $data['pledged']);
        $data['contingent'] = $data['contingent'] === null ? null : self::round($data['contingent']);

        return $data;
    }

    /**
     * @param  list<array{key: string, label: string, amount: int, previous: ?int, subtotal: bool}>  $lines
     * @return list<array{key: string, label: string, amount: int, previous: ?int, subtotal: bool}>
     */
    private static function income(array $lines, bool $hasPrevious): array
    {
        $values = ['amount' => [], 'previous' => []];
        $subtotals = [];

        foreach (K2Schedule::INCOME_SUBTOTALS as $subtotal) {
            $subtotals[$subtotal['key']] = $subtotal['lines'];
        }

        foreach ($lines as $index => $line) {
            foreach (['amount', 'previous'] as $field) {
                if ($field === 'previous' && ! $hasPrevious) {
                    continue;
                }

                $value = isset($subtotals[$line['key']])
                    ? array_sum(array_map(fn (string $key): int => $values[$field][$key] ?? 0, $subtotals[$line['key']]))
                    : self::round((int) $line[$field]);
                $values[$field][$line['key']] = $value;
                $lines[$index][$field] = $value;
            }
        }

        return $lines;
    }

    /**
     * @param  list<array{key: string, amount: int, previous: ?int}>  $lines
     * @return array{amount: array<string, int>, previous: array<string, int>}
     */
    private static function incomeValues(array $lines): array
    {
        $values = ['amount' => [], 'previous' => []];

        foreach ($lines as $line) {
            $values['amount'][$line['key']] = $line['amount'];
            $values['previous'][$line['key']] = (int) $line['previous'];
        }

        return $values;
    }

    /**
     * @param  list<array{key: string, label: string, acquisition: array{opening: int, additions: int, disposals: int, closing: int}, depreciation: array{opening: int, disposals: int, charge: int, closing: int}, book_value: int}>  $notes
     * @return list<array{key: string, label: string, acquisition: array{opening: int, additions: int, disposals: int, closing: int}, depreciation: array{opening: int, disposals: int, charge: int, closing: int}, book_value: int}>
     */
    private static function fixedAssets(array $notes): array
    {
        foreach ($notes as $index => $note) {
            $cost = $note['acquisition'];
            $cost = ['opening' => self::round($cost['opening']), 'additions' => self::round($cost['additions']), 'disposals' => self::round($cost['disposals'])];
            $cost['closing'] = $cost['opening'] + $cost['additions'] - $cost['disposals'];
            $depreciation = $note['depreciation'];
            $depreciation = ['opening' => self::round($depreciation['opening']), 'disposals' => self::round($depreciation['disposals']), 'charge' => self::round($depreciation['charge'])];
            // Accumulated depreciation is a positive number that disposals reduce and the year's charge raises.
            $depreciation['closing'] = $depreciation['opening'] - $depreciation['disposals'] + $depreciation['charge'];
            $notes[$index] = ['acquisition' => $cost, 'depreciation' => $depreciation, 'book_value' => $cost['closing'] - $depreciation['closing']] + $note;
        }

        return $notes;
    }

    /**
     * @param  array{sections: list<array{key: string, label: string, lines: list<array{key: string, label: string, amount: int, previous: int}>, amount: int, previous: int}>}  $sheet
     * @param  array{amount: array<string, int>, previous: array<string, int>}  $results
     * @param  list<array{key: string, book_value: int}>  $notes
     * @return array{sections: list<array{key: string, label: string, lines: list<array{key: string, label: string, amount: int, previous: int}>, amount: int, previous: int}>, assets: int, assets_previous: int, equity_liabilities: int, equity_liabilities_previous: int, equity: int, equity_previous: int}
     */
    private static function balance(array $sheet, array $results, bool $hasPrevious, array $notes): array
    {
        $bookValues = array_column($notes, 'book_value', 'key');
        $lines = [];
        $labels = [];

        foreach ($sheet['sections'] as $section) {
            $labels[$section['key']] = $section['label'];

            foreach ($section['lines'] as $line) {
                $line['amount'] = $line['key'] === 'year_result' ? ($results['amount']['year_result'] ?? 0) : ($bookValues[$line['key']] ?? self::round($line['amount']));
                $line['previous'] = $line['key'] === 'year_result' && $hasPrevious ? ($results['previous']['year_result'] ?? 0) : self::round($line['previous']);
                $lines[$line['key']] = $line + ['section' => $section['key']];
            }
        }

        foreach (['amount', 'previous'] as $field) {
            $assets = 0;
            $other = 0;

            foreach ($lines as $line) {
                in_array($line['section'], K2Schedule::ASSET_SECTIONS, true) ? $assets += $line[$field] : $other += $line[$field];
            }

            if ($assets !== $other) {
                $lines['other_liabilities'] ??= ['key' => 'other_liabilities', 'label' => (string) __('Övriga skulder'), 'amount' => 0, 'previous' => 0, 'section' => 'short_term'];
                $lines['other_liabilities'][$field] += $assets - $other;
            }
        }

        $order = array_flip([...array_column(K2Schedule::BALANCE, 'key'), 'year_result']);
        $order['year_result'] = $order['retained'] + 0.5;
        uasort($lines, fn (array $a, array $b): int => $order[$a['key']] <=> $order[$b['key']]);
        $sections = [];

        foreach (K2Schedule::SECTIONS as $key => $label) {
            $sectionLines = [];
            $amount = 0;
            $previous = 0;

            foreach ($lines as $line) {
                if ($line['section'] === $key) {
                    $sectionLines[] = ['key' => $line['key'], 'label' => $line['label'], 'amount' => $line['amount'], 'previous' => $line['previous']];
                    $amount += $line['amount'];
                    $previous += $line['previous'];
                }
            }

            if ($sectionLines !== []) {
                $sections[] = ['key' => $key, 'label' => $labels[$key] ?? (string) __($label), 'lines' => $sectionLines, 'amount' => $amount, 'previous' => $previous];
            }
        }

        $liabilities = ['restricted', 'free', 'untaxed', 'provisions', 'long_term', 'short_term'];
        $totals = ['assets' => 0, 'assets_previous' => 0, 'equity_liabilities' => 0, 'equity_liabilities_previous' => 0, 'equity' => 0, 'equity_previous' => 0];

        foreach ($sections as $section) {
            $groups = [
                'assets' => in_array($section['key'], K2Schedule::ASSET_SECTIONS, true),
                'equity_liabilities' => in_array($section['key'], $liabilities, true),
                'equity' => in_array($section['key'], ['restricted', 'free'], true),
            ];

            foreach ($groups as $group => $belongs) {
                if ($belongs) {
                    $totals[$group] += $section['amount'];
                    $totals["{$group}_previous"] += $section['previous'];
                }
            }
        }

        return ['sections' => $sections, ...$totals];
    }

    /**
     * @param  list<array{key: string, label: string, amounts: array<string, int>, total: int}>  $rows
     * @param  array{sections: list<array{lines: list<array{key: string, amount: int, previous: int}>}>}  $sheet
     * @return list<array{key: string, label: string, amounts: array<string, int>, total: int}>
     */
    private static function equity(array $rows, array $sheet, int $yearResult): array
    {
        $balance = [];

        foreach ($sheet['sections'] as $section) {
            foreach ($section['lines'] as $line) {
                $balance[$line['key']] = $line;
            }
        }

        $byKey = [];

        foreach ($rows as $row) {
            $byKey[$row['key']] = $row;
        }

        $columns = array_keys($byKey['opening']['amounts'] ?? []);
        /** @var array<string, array<string, int>> $amounts printed amounts per row key and column */
        $amounts = [];
        $labels = [];

        foreach ($byKey as $key => $row) {
            $labels[$key] = $row['label'];

            foreach ($columns as $column) {
                $amounts[$key][$column] = match ($key) {
                    'opening' => (int) ($balance[$column]['previous'] ?? 0),
                    'closing' => (int) ($balance[$column]['amount'] ?? 0),
                    'year_result' => $column === 'year_result' ? $yearResult : 0,
                    default => self::round($row['amounts'][$column] ?? 0),
                };
            }
        }

        foreach ($columns as $column) {
            $changes = 0;

            foreach ($amounts as $key => $cells) {
                $changes += in_array($key, ['opening', 'closing'], true) ? 0 : $cells[$column];
            }

            $left = ($amounts['closing'][$column] ?? 0) - ($amounts['opening'][$column] ?? 0) - $changes;

            if ($left === 0) {
                continue;
            }

            $target = 'other';

            foreach (['carried_forward', 'other', 'dividend'] as $candidate) {
                if (($byKey[$candidate]['amounts'][$column] ?? 0) !== 0) {
                    $target = $candidate;

                    break;
                }
            }

            if (! isset($amounts[$target])) {
                $amounts[$target] = array_fill_keys($columns, 0);
                $labels[$target] = __('Övriga förändringar');
            }

            $amounts[$target][$column] += $left;
        }

        $order = ['opening', 'dividend', 'carried_forward', 'year_result', 'other', 'closing'];
        $result = [];

        foreach ($order as $key) {
            if (isset($amounts[$key])) {
                $result[] = ['key' => $key, 'label' => (string) $labels[$key], 'amounts' => $amounts[$key], 'total' => array_sum($amounts[$key])];
            }
        }

        return $result;
    }

    /**
     * The overview's net sales and result after financial items: the income
     * statement's printed figures for this year and the previous one, the
     * rounded figure for earlier years.
     *
     * @param  list<array{label: string, net_sales: int, after_financial: int, solidity: ?float}>  $rows
     * @param  array{amount: array<string, int>, previous: array<string, int>}  $results
     * @return list<array{label: string, net_sales: int, after_financial: int, solidity: ?float}>
     */
    private static function multiYear(array $rows, array $results, bool $hasPrevious): array
    {
        foreach ($rows as $index => $row) {
            $field = match (true) {
                $index === 0 => 'amount',
                $index === 1 && $hasPrevious => 'previous',
                default => null,
            };
            $rows[$index]['net_sales'] = $field === null ? self::round($row['net_sales']) : ($results[$field]['net_sales'] ?? 0);
            $rows[$index]['after_financial'] = $field === null ? self::round($row['after_financial']) : ($results[$field]['after_financial'] ?? 0);
        }

        return $rows;
    }

    /**
     * @param  list<array{from: string, to: string, from_amount: int, to_amount: int}>  $variations
     * @param  list<array{label: string, net_sales: int}>  $rows
     * @return list<array{from: string, to: string, from_amount: int, to_amount: int}>
     */
    private static function variations(array $variations, array $rows): array
    {
        $sales = array_column($rows, 'net_sales', 'label');

        return array_map(fn (array $variation): array => ['from_amount' => $sales[$variation['from']] ?? self::round($variation['from_amount']), 'to_amount' => $sales[$variation['to']] ?? self::round($variation['to_amount'])] + $variation, $variations);
    }

    /**
     * The free equity as printed (the balance sheet's Fritt eget kapital),
     * the dividend rounded and the rest carried forward.
     *
     * @param  array{sections: list<array{key: string, amount: int}>}  $sheet
     * @return array{free: int, dividend: int, carried: int}
     */
    private static function disposition(array $sheet, int $dividend): array
    {
        $free = 0;

        foreach ($sheet['sections'] as $section) {
            if ($section['key'] === 'free') {
                $free = $section['amount'];
            }
        }

        $dividend = self::round($dividend);

        return ['free' => $free, 'dividend' => $dividend, 'carried' => $free - $dividend];
    }
}
