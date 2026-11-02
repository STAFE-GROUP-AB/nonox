<?php

declare(strict_types=1);

namespace App\Modules\Payroll\Support\Tax;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;
use InvalidArgumentException;

/**
 * Fetches a year's general monthly tax tables from Skatteverket's open data
 * (the RowStore dataset behind "Skattetabeller för månadslön" on
 * Skatteverket's developer portal) and writes them in the record layout of
 * the official text file, so they pass through exactly the same checks in
 * TaxTableFile and TaxTableImporter as a downloaded file. The download
 * links on skatteverket.se change every year; the dataset address does not.
 */
final class TaxTableOpenData
{
    public const string URL = 'https://skatteverket.entryscape.net/rowstore/dataset/88320397-5c32-4c16-ae79-d36d95b17b95/json';

    private const int PAGE = 100;

    private const int MAX_PAGES = 1000;

    /**
     * The year's tables as text in the record layout, or null when
     * Skatteverket has not published the year yet.
     *
     * @throws InvalidArgumentException on an answer that does not look like the dataset
     * @throws RequestException|ConnectionException when Skatteverket cannot be reached
     */
    public function fetch(int $year): ?string
    {
        $lines = [];
        $total = 0;

        for ($page = 0; $page < self::MAX_PAGES; $page++) {
            $response = Http::acceptJson()->timeout(30)->retry(3, 1000)->get(self::URL, ['år' => $year, '_limit' => self::PAGE, '_offset' => $page * self::PAGE]);
            $response->throw();
            $total = $page === 0 ? (int) $response->json('resultCount') : $total;
            $rows = (array) $response->json('results', []);

            foreach ($rows as $row) {
                $lines[] = $this->line((array) $row, $year);
            }

            if ($rows === [] || count($lines) >= $total) {
                break;
            }
        }

        if ($total === 0) {
            return null;
        }

        if (count($lines) !== $total) {
            throw new InvalidArgumentException("Skatteverket's open data gave ".count($lines)." of {$total} rows for {$year}.");
        }

        return implode("\n", $lines)."\n";
    }

    /**
     * @param  array<array-key, mixed>  $row
     */
    private function line(array $row, int $year): string
    {
        if ((string) ($row['år'] ?? '') !== (string) $year) {
            throw new InvalidArgumentException("Skatteverket's open data returned a row for another year.");
        }

        if (trim((string) ($row['kolumn 7'] ?? '')) !== '') {
            throw new InvalidArgumentException('The tables have a seventh column, which the record layout here does not know.');
        }

        if (! preg_match('/^(30|14)([B%])$/', (string) ($row['antal dgr'] ?? ''), $period)) {
            throw new InvalidArgumentException('A row has an unknown period: '.json_encode($row['antal dgr'] ?? null));
        }

        $columns = '';

        for ($column = 1; $column <= 6; $column++) {
            $columns .= $this->field((string) ($row["kolumn {$column}"] ?? ''), 5);
        }

        return $period[1].$period[2]
            .str_pad($this->digits((string) ($row['tabellnr'] ?? '')), 2, '0', STR_PAD_LEFT)
            .$this->field((string) ($row['inkomst fr.o.m.'] ?? ''), 7)
            .$this->field((string) ($row['inkomst t.o.m.'] ?? ''), 7)
            .$columns;
    }

    private function field(string $value, int $width): string
    {
        $value = $this->digits($value);

        if (strlen($value) > $width) {
            throw new InvalidArgumentException("The value {$value} does not fit the record layout.");
        }

        return str_pad($value, $width, ' ', STR_PAD_LEFT);
    }

    private function digits(string $value): string
    {
        $value = trim($value);

        if ($value !== '' && ! ctype_digit($value)) {
            throw new InvalidArgumentException("The value {$value} is not a whole number.");
        }

        return $value;
    }
}
