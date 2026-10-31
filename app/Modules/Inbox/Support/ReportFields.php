<?php

declare(strict_types=1);

namespace App\Modules\Inbox\Support;

use App\Support\Spreadsheet\XlsxReader;
use RuntimeException;

/**
 * The labelled amounts of a report from a cash register (a Z-report or
 * daily report), whatever its layout: label;amount lines, one row with a
 * column per amount, a table per VAT rate, or a plain text printout. Every
 * amount gets a key from its row label and its column header, so the same
 * layout gives the same keys every day. The sorted keys are the layout's
 * fingerprint, which a template is matched on.
 */
final readonly class ReportFields
{
    public const string CSV = 'csv';

    public const string XLSX = 'xlsx';

    public const string TEXT = 'text';

    /**
     * @param  array<string, int>  $fields  normalised key => öre
     * @param  array<string, string>  $labels  normalised key => the label as written
     */
    public function __construct(
        public string $format,
        public array $fields,
        public array $labels,
        public ?string $date,
    ) {}

    public static function fromCsv(string $contents): ?self
    {
        $text = self::utf8($contents);
        $lines = array_values(array_filter(preg_split('/\r\n|\r|\n/', $text) ?: [], fn (string $line): bool => trim($line) !== ''));
        $delimiter = self::delimiter(array_slice($lines, 0, 20));

        if ($delimiter === null) {
            return null;
        }

        $rows = array_map(fn (string $line): array => array_map(fn (mixed $cell): string => trim((string) $cell), str_getcsv($line, $delimiter, '"', '')), $lines);

        return self::fromRows(self::CSV, $rows);
    }

    public static function fromXlsx(string $contents): ?self
    {
        try {
            return self::fromRows(self::XLSX, XlsxReader::rows($contents));
        } catch (RuntimeException) {
            return null;
        }
    }

    /**
     * A plain text printout: a label and an amount with two decimals per
     * line. Needs at least four such lines to count as a report.
     */
    public static function fromText(string $contents): ?self
    {
        $text = self::utf8($contents);
        $fields = [];
        $labels = [];
        $date = null;

        foreach (preg_split('/\r\n|\r|\n/', $text) ?: [] as $line) {
            $date ??= self::date($line);

            if (preg_match('/^\s*(.*?\S)[\s:]+(-?(?:\d{1,3}(?:[ \x{00A0}\x{202F}.]\d{3})+|\d+)[.,]\d{2})\s*(?:kr|sek)?\s*$/iu', $line, $match) !== 1) {
                continue;
            }

            $amount = self::amount($match[2]);
            $label = trim((string) preg_replace('/\s+/u', ' ', $match[1]), " :\t");

            if ($amount === null || $label === '' || self::date($label) !== null) {
                continue;
            }

            $key = self::key($label);
            $fields[$key] = ($fields[$key] ?? 0) + $amount;
            $labels[$key] = $label;
        }

        return count($fields) >= 4 ? new self(self::TEXT, $fields, $labels, $date) : null;
    }

    /**
     * @param  list<list<string>>  $rows
     */
    public static function fromRows(string $format, array $rows): ?self
    {
        $header = [];
        $fields = [];
        $labels = [];
        $date = null;

        foreach ($rows as $cells) {
            $cells = array_map(fn (string $cell): string => trim($cell), $cells);
            $amounts = [];
            $rowLabel = [];
            $dated = false;

            foreach ($cells as $index => $cell) {
                if ($cell === '') {
                    continue;
                }

                $cellDate = self::date($cell);

                if ($cellDate !== null && strlen($cell) <= 20) {
                    $date ??= $cellDate;
                    $dated = true;

                    continue;
                }

                $amount = self::amount($cell);

                if ($amount === null) {
                    $rowLabel[] = $cell;
                } else {
                    $amounts[$index] = $amount;
                }
            }

            if ($amounts === []) {
                // A row of labels only is the header of the rows below it.
                if (! $dated && count($rowLabel) >= 2) {
                    $header = $cells;
                }

                continue;
            }

            foreach ($amounts as $index => $amount) {
                $label = trim(implode(' ', $rowLabel).' | '.self::headerLabel($header, $index), ' |');
                $label = $label === '' ? __('Kolumn :number', ['number' => $index + 1]) : $label;
                $key = self::key($label);
                $fields[$key] = ($fields[$key] ?? 0) + $amount;
                $labels[$key] = $label;
            }
        }

        return count($fields) >= 2 ? new self($format, $fields, $labels, $date) : null;
    }

    /**
     * @param  array<int, string>  $header
     */
    private static function headerLabel(array $header, int $index): string
    {
        return $header[$index] ?? '';
    }

    public function fingerprint(): string
    {
        $keys = array_keys($this->fields);
        sort($keys);

        return hash('sha256', $this->format."\n".implode("\n", $keys));
    }

    /**
     * Öre from an amount as cash registers write it: "1 234,50", "1234.50",
     * "1.234,50", "-0,40", "143". Null when the cell is not an amount, such
     * as a date, a percentage or text.
     */
    public static function amount(string $cell): ?int
    {
        $value = str_replace(["\u{00A0}", "\u{202F}", ' '], '', trim($cell));
        $value = (string) preg_replace('/(kr|sek)$/i', '', $value);

        if (preg_match('/^-?\d[\d.,]*$/', $value) !== 1) {
            return null;
        }

        $negative = str_starts_with($value, '-');
        $value = ltrim($value, '-');
        $lastComma = strrpos($value, ',');
        $lastDot = strrpos($value, '.');

        if ($lastComma !== false && $lastDot !== false) {
            $decimal = $lastComma > $lastDot ? ',' : '.';
            $thousands = $decimal === ',' ? '.' : ',';
            $value = str_replace($thousands, '', $value);
        } else {
            $decimal = $lastComma !== false ? ',' : '.';
        }

        if (substr_count($value, $decimal) > 1) {
            return null;
        }

        [$whole, $fraction] = array_pad(explode($decimal, $value), 2, '');

        if (strlen($fraction) > 2 || $whole === '' || preg_match('/\D/', $whole.$fraction) === 1) {
            return null;
        }

        $ore = (int) $whole * 100 + (int) str_pad($fraction, 2, '0');

        return $negative ? -$ore : $ore;
    }

    /** An ISO date found in the text, or null. */
    public static function date(string $text): ?string
    {
        if (preg_match('/\b(20\d{2})-(\d{2})-(\d{2})\b/', $text, $match) === 1 && checkdate((int) $match[2], (int) $match[3], (int) $match[1])) {
            return "{$match[1]}-{$match[2]}-{$match[3]}";
        }

        if (preg_match('/\b(\d{2})[.\/](\d{2})[.\/](20\d{2})\b/', $text, $match) === 1 && checkdate((int) $match[2], (int) $match[1], (int) $match[3])) {
            return "{$match[3]}-{$match[2]}-{$match[1]}";
        }

        return null;
    }

    public static function key(string $label): string
    {
        return mb_strtolower(trim((string) preg_replace('/\s+/u', ' ', $label)));
    }

    private static function utf8(string $contents): string
    {
        $contents = str_starts_with($contents, "\xEF\xBB\xBF") ? substr($contents, 3) : $contents;

        return mb_check_encoding($contents, 'UTF-8') ? $contents : (string) mb_convert_encoding($contents, 'UTF-8', 'Windows-1252');
    }

    /**
     * The separator used on most of the first lines: ";", tab, "," or "|".
     *
     * @param  list<string>  $lines
     */
    private static function delimiter(array $lines): ?string
    {
        $best = null;
        $bestScore = 0;

        foreach ([';', "\t", '|', ','] as $candidate) {
            $score = count(array_filter($lines, fn (string $line): bool => count(str_getcsv($line, $candidate, '"', '')) >= 2));

            if ($score > $bestScore) {
                [$best, $bestScore] = [$candidate, $score];
            }
        }

        return $bestScore >= 2 ? $best : null;
    }
}
