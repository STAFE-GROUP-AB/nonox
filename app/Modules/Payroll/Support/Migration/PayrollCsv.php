<?php

declare(strict_types=1);

namespace App\Modules\Payroll\Support\Migration;

use Illuminate\Validation\ValidationException;

/**
 * Reads the CSV files for moving payroll data: semicolon separated, the
 * first line naming the columns, UTF-8 (a byte order mark is allowed) or
 * Windows-1252 as saved by Excel. Each file has fixed column names, so the
 * columns may come in any order.
 */
final class PayrollCsv
{
    public const array EMPLOYEES = ['anstallningsnummer', 'fornamn', 'efternamn', 'personnummer', 'anstalld_fran', 'anstallningsform', 'loneform', 'lon', 'skattetabell', 'kolumn'];

    public const array EMPLOYEES_OPTIONAL = ['epost', 'clearingnummer', 'kontonummer', 'sysselsattningsgrad', 'timmar_per_vecka', 'befattning'];

    public const array YEAR_TO_DATE = ['anstallningsnummer', 'bruttolon', 'skatt', 'avgiftsunderlag', 'arbetsgivaravgifter'];

    public const array YEAR_TO_DATE_OPTIONAL = ['formaner', 'engangsbelopp'];

    public const array VACATION = ['anstallningsnummer', 'semesterar_start', 'intjanade_dagar', 'uttagna_dagar', 'sparade_dagar'];

    public const array VACATION_OPTIONAL = ['semesterlonegrundande_lon'];

    /**
     * @param  list<string>  $required
     * @param  list<string>  $optional
     * @return list<array<string, string>> rows by column name, with the line number under '_line'
     */
    public static function read(string $contents, array $required, array $optional = []): array
    {
        if (! mb_check_encoding($contents, 'UTF-8')) {
            $contents = (string) mb_convert_encoding($contents, 'UTF-8', 'Windows-1252');
        }

        $lines = preg_split('/\r\n|\r|\n/', (string) preg_replace('/^\x{FEFF}/u', '', $contents)) ?: [];
        $header = array_map(fn (?string $name): string => mb_strtolower(trim((string) $name)), str_getcsv((string) array_shift($lines), ';', '"', ''));
        $missing = array_values(array_diff($required, $header));

        if ($missing !== []) {
            throw ValidationException::withMessages(['file' => [__('Kolumnerna :columns saknas på första raden.', ['columns' => implode(', ', $missing)])]]);
        }

        $known = [...$required, ...$optional];
        $rows = [];

        foreach ($lines as $index => $line) {
            if (trim($line) === '') {
                continue;
            }

            $values = str_getcsv($line, ';', '"', '');
            $row = ['_line' => (string) ($index + 2)];

            foreach ($header as $position => $name) {
                if (in_array($name, $known, true)) {
                    $row[$name] = trim((string) ($values[$position] ?? ''));
                }
            }

            $rows[] = $row;
        }

        if ($rows === []) {
            throw ValidationException::withMessages(['file' => [__('Filen har inga rader under rubrikraden.')]]);
        }

        return $rows;
    }

    /** A Swedish amount such as 32 000,50 or 32000.50, in öre. */
    public static function ore(string $value): ?int
    {
        $clean = str_replace([' ', "\u{00A0}", "\u{202F}"], '', $value);

        if (preg_match('/^-?\d+([.,]\d{1,2})?$/', $clean) !== 1) {
            return null;
        }

        [$kronor, $ore] = array_pad(explode('.', str_replace(',', '.', $clean)), 2, '0');
        $negative = str_starts_with($kronor, '-');

        return ($negative ? -1 : 1) * (abs((int) $kronor) * 100 + (int) str_pad($ore, 2, '0'));
    }
}
