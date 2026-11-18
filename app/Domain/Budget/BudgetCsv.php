<?php

declare(strict_types=1);

namespace App\Domain\Budget;

use App\Models\Account;
use App\Models\CostCenter;
use App\Models\Project;
use App\Support\Csv;

/**
 * Budget rows as a spreadsheet: one row per account (and cost centre and
 * project), one column per month, amounts in kronor in the sign people read
 * (revenue and costs both positive, BudgetMonths::shown). The same reader
 * takes a CSV file and a block pasted from a spreadsheet.
 *
 * With a header row the columns are found by name: "Konto", optionally
 * "Kostnadsställe" and "Projekt", and one "ÅÅÅÅ-MM" column per month;
 * "Kontonamn" and "Summa" are ignored. Without a header the first column is
 * the account and the rest are the fiscal year's months in order.
 *
 * Must run inside the company: accounts and objects are looked up by number
 * and code through the company scope.
 */
final class BudgetCsv
{
    public const string ACCOUNT = 'Konto';

    public const string ACCOUNT_NAME = 'Kontonamn';

    public const string COST_CENTER = 'Kostnadsställe';

    public const string PROJECT = 'Projekt';

    public const string TOTAL = 'Summa';

    /**
     * @param  list<string>  $months  the fiscal year's months ('Y-m')
     * @return array{rows: list<array{account_id: int, cost_center_id: ?int, project_id: ?int, amounts: array<string, int>}>, errors: list<string>}
     */
    public static function read(string $text, array $months): array
    {
        $lines = self::lines($text);

        if ($lines === []) {
            return ['rows' => [], 'errors' => [__('Filen är tom.')]];
        }

        if (count($lines) > BudgetMonths::MAX_IMPORT_ROWS + 1) {
            return ['rows' => [], 'errors' => [__('Filen har fler än :max rader. Dela upp den.', ['max' => BudgetMonths::MAX_IMPORT_ROWS])]];
        }

        $delimiter = str_contains($lines[0], "\t") ? "\t" : (str_contains($lines[0], ';') ? ';' : ',');
        $table = array_map(fn (string $line): array => array_map(fn (?string $cell): string => trim((string) $cell), str_getcsv($line, $delimiter, '"', '')), $lines);

        $header = preg_match('/^\d{4}$/', $table[0][0]) === 1 ? null : array_shift($table);
        $columns = $header === null ? self::positional($months) : self::named($header, $months);

        if (is_string($columns)) {
            return ['rows' => [], 'errors' => [$columns]];
        }

        $accounts = Account::query()->whereBetween('number', [BudgetMonths::FIRST_ACCOUNT, BudgetMonths::LAST_ACCOUNT])->pluck('id', 'number');
        $costCenters = CostCenter::query()->pluck('id', 'code');
        $projects = Project::query()->pluck('id', 'code');

        $rows = [];
        $errors = [];
        $seen = [];
        $first = $header === null ? 1 : 2;

        foreach ($table as $index => $cells) {
            $line = $index + $first;

            if (array_filter($cells, fn (string $cell): bool => $cell !== '') === []) {
                continue;
            }

            $number = $cells[$columns['account']] ?? '';

            if (preg_match('/^\d{4}$/', $number) !== 1 || ! BudgetMonths::isBudgetable((int) $number)) {
                $errors[] = __('Rad :line: :account är inget resultatkonto (3000–8998).', ['line' => $line, 'account' => $number]);

                continue;
            }

            if (! isset($accounts[(int) $number])) {
                $errors[] = __('Rad :line: konto :account finns inte i kontoplanen.', ['line' => $line, 'account' => $number]);

                continue;
            }

            // A code our own export guarded against formulas (Csv::cell) starts with an apostrophe.
            $costCenterCode = $columns['cost_center'] === null ? '' : self::code($cells[$columns['cost_center']] ?? '');
            $projectCode = $columns['project'] === null ? '' : self::code($cells[$columns['project']] ?? '');

            if ($costCenterCode !== '' && ! isset($costCenters[$costCenterCode])) {
                $errors[] = __('Rad :line: kostnadsställe :code finns inte.', ['line' => $line, 'code' => $costCenterCode]);

                continue;
            }

            if ($projectCode !== '' && ! isset($projects[$projectCode])) {
                $errors[] = __('Rad :line: projekt :code finns inte.', ['line' => $line, 'code' => $projectCode]);

                continue;
            }

            $key = "{$number}|{$costCenterCode}|{$projectCode}";

            if (isset($seen[$key])) {
                $errors[] = __('Rad :line: samma konto, kostnadsställe och projekt finns redan på rad :other.', ['line' => $line, 'other' => $seen[$key]]);

                continue;
            }

            $amounts = [];

            foreach ($columns['months'] as $month => $column) {
                $cell = $cells[$column] ?? '';

                $ore = $cell === '' ? 0 : BudgetMonths::parseCell($cell);

                if ($ore === null) {
                    $errors[] = __('Rad :line: :value i :month är inget belopp, eller större än 100 miljarder kr.', ['line' => $line, 'value' => mb_substr($cell, 0, 30), 'month' => $month]);

                    continue 2;
                }

                $amounts[$month] = BudgetMonths::shown((int) $number, $ore);
            }

            $seen[$key] = $line;
            $rows[] = [
                'account_id' => (int) $accounts[(int) $number],
                'cost_center_id' => $costCenterCode === '' ? null : (int) $costCenters[$costCenterCode],
                'project_id' => $projectCode === '' ? null : (int) $projects[$projectCode],
                'amounts' => $amounts,
            ];
        }

        return ['rows' => $rows, 'errors' => $errors];
    }

    /**
     * @param  list<string>  $months
     * @param  list<array{number: int, name: string, cost_center: string, project: string, amounts: array<string, int>}>  $rows  amounts in ledger sign
     */
    public static function write(array $months, array $rows): string
    {
        $out = fopen('php://temp', 'r+');

        if ($out === false) {
            return '';
        }

        fwrite($out, "\u{FEFF}");
        fputcsv($out, [self::ACCOUNT, self::ACCOUNT_NAME, self::COST_CENTER, self::PROJECT, ...$months, self::TOTAL], ';', '"', '');

        foreach ($rows as $row) {
            $shown = array_map(fn (string $month): int => BudgetMonths::shown($row['number'], $row['amounts'][$month] ?? 0), $months);

            fputcsv($out, Csv::row([
                (string) $row['number'],
                $row['name'],
                $row['cost_center'],
                $row['project'],
                ...array_map(self::amount(...), $shown),
                self::amount(array_sum($shown)),
            ]), ';', '"', '');
        }

        rewind($out);
        $csv = (string) stream_get_contents($out);
        fclose($out);

        return str_replace("\n", "\r\n", $csv);
    }

    /** Kronor with a decimal comma and no thousands separator, so spreadsheets read it as a number. */
    private static function amount(int $ore): string
    {
        $sign = $ore < 0 ? '-' : '';
        $absolute = abs($ore);

        return $absolute % 100 === 0
            ? $sign.intdiv($absolute, 100)
            : $sign.intdiv($absolute, 100).','.str_pad((string) ($absolute % 100), 2, '0', STR_PAD_LEFT);
    }

    private static function code(string $cell): string
    {
        return str_starts_with($cell, "'") ? substr($cell, 1) : $cell;
    }

    /** @return list<string> */
    private static function lines(string $text): array
    {
        if (! mb_check_encoding($text, 'UTF-8')) {
            // Spreadsheets on Windows often save CSV as Windows-1252.
            $text = mb_convert_encoding($text, 'UTF-8', 'Windows-1252');
        }

        $text = (string) preg_replace('/^\x{FEFF}/u', '', $text);
        $lines = preg_split('/\r\n|\r|\n/', $text) ?: [];

        return array_values(array_filter($lines, fn (string $line): bool => trim($line) !== ''));
    }

    /**
     * @param  list<string>  $months
     * @return array{account: int, cost_center: ?int, project: ?int, months: array<string, int>}
     */
    private static function positional(array $months): array
    {
        $columns = [];

        foreach ($months as $index => $month) {
            $columns[$month] = $index + 1;
        }

        return ['account' => 0, 'cost_center' => null, 'project' => null, 'months' => $columns];
    }

    /**
     * @param  list<string>  $header
     * @param  list<string>  $months
     * @return array{account: int, cost_center: ?int, project: ?int, months: array<string, int>}|string
     */
    private static function named(array $header, array $months): array|string
    {
        $names = array_map(fn (string $name): string => mb_strtolower($name), $header);
        $account = array_search(mb_strtolower(self::ACCOUNT), $names, true);

        if ($account === false) {
            return __('Kolumnen :column saknas i första raden.', ['column' => self::ACCOUNT]);
        }

        $costCenter = array_search(mb_strtolower(self::COST_CENTER), $names, true);
        $project = array_search(mb_strtolower(self::PROJECT), $names, true);
        $columns = [];

        foreach ($header as $index => $name) {
            if (preg_match('/^\d{4}-\d{2}$/', $name) !== 1) {
                continue;
            }

            if (! in_array($name, $months, true)) {
                return __('Månaden :month ingår inte i räkenskapsåret.', ['month' => $name]);
            }

            $columns[$name] = $index;
        }

        if ($columns === []) {
            return __('Första raden har inga månadskolumner (ÅÅÅÅ-MM).');
        }

        return [
            'account' => $account,
            'cost_center' => $costCenter === false ? null : $costCenter,
            'project' => $project === false ? null : $project,
            'months' => $columns,
        ];
    }
}
