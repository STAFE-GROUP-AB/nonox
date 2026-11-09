<?php

declare(strict_types=1);

namespace App\Migration\Import;

use DateTimeInterface;
use Generator;
use OpenSpout\Reader\XLSX\Options;
use OpenSpout\Reader\XLSX\Reader as XlsxReader;
use RuntimeException;
use Throwable;
use ZipArchive;

/**
 * Opens a CSV, TSV, text or XLSX register file (ADR-076, "Generic register
 * importer"). Detects the encoding (UTF-8 with or without BOM, otherwise
 * Windows-1252, or CP437 when the caller says so) and the delimiter
 * (semicolon, comma, tab, pipe), and streams the rows: a text file is read
 * a line at a time, an XLSX with the streaming reader of openspout, so the
 * size of the file does not decide the memory.
 *
 * The first non-empty row is the header row. Empty rows are skipped.
 */
final class RegisterFileReader
{
    public const int MAX_BYTES = 20 * 1024 * 1024;

    public const int MAX_ROWS = 100_000;

    public const int MAX_UNPACKED_BYTES = 200 * 1024 * 1024;

    private const int MAX_RATIO = 200;

    private const array DELIMITERS = [';', ',', "\t", '|'];

    /**
     * @param  array{encoding?: ?string, delimiter?: ?string, sheet?: ?string}  $options  overrides of what is detected
     *
     * @throws RuntimeException with a Swedish message when the file cannot be read
     */
    public static function open(string $path, array $options = []): RegisterTable
    {
        if (! is_file($path) || ! is_readable($path)) {
            throw new RuntimeException(__('Filen går inte att läsa.'));
        }

        if (filesize($path) > self::MAX_BYTES) {
            throw new RuntimeException(__('Filen är större än 20 MB. Dela upp den i flera filer.'));
        }

        $head = (string) file_get_contents($path, false, null, 0, 4);

        return str_starts_with($head, "PK\x03\x04")
            ? self::xlsx($path, $options['sheet'] ?? null)
            : self::text($path, $options['encoding'] ?? null, $options['delimiter'] ?? null);
    }

    /** Header text reduced for matching: lower case, no spaces, dots, hyphens or underscores. */
    public static function normaliseHeader(string $header): string
    {
        return (string) preg_replace('/[\s._\-]+/u', '', mb_strtolower(trim($header)));
    }

    private static function text(string $path, ?string $encoding, ?string $delimiter): RegisterTable
    {
        $sample = (string) file_get_contents($path, false, null, 0, 65_536);
        $hasBom = str_starts_with($sample, "\xEF\xBB\xBF");
        $encoding ??= self::detectEncoding($sample);
        $decodedSample = self::decode($hasBom ? substr($sample, 3) : $sample, $encoding);
        $delimiter ??= self::detectDelimiter($decodedSample);

        $open = function () use ($path, $encoding, $hasBom) {
            $source = match ($encoding) {
                'CP437' => 'php://filter/read=convert.iconv.CP437.UTF-8/resource='.$path,
                'Windows-1252' => 'php://filter/read=convert.iconv.CP1252.UTF-8/resource='.$path,
                default => $path,
            };
            $handle = fopen($source, 'rb') ?: throw new RuntimeException(__('Filen går inte att läsa.'));

            if ($hasBom && $encoding === 'UTF-8') {
                fread($handle, 3);
            }

            return $handle;
        };

        $rows = function () use ($open, $delimiter): Generator {
            $handle = $open();

            try {
                $line = 0;

                while (($cells = fgetcsv($handle, null, $delimiter, '"', '')) !== false) {
                    $line++;
                    $cells = array_map(fn (?string $cell): string => trim((string) $cell), $cells);

                    if ($cells === [''] || implode('', $cells) === '') {
                        continue;
                    }

                    yield $line => $cells;
                }
            } finally {
                fclose($handle);
            }
        };

        return self::withHeader('csv', $encoding, $delimiter, [], null, $rows);
    }

    private static function xlsx(string $path, ?string $wanted): RegisterTable
    {
        self::checkUnpackedSize($path);

        $names = [];

        try {
            $reader = new XlsxReader(new Options);
            $reader->open($path);

            foreach ($reader->getSheetIterator() as $sheet) {
                $names[] = $sheet->getName();
            }

            $reader->close();
        } catch (Throwable) {
            throw new RuntimeException(__('Excel-filen går inte att läsa. Spara den som .xlsx eller CSV och försök igen.'));
        }

        if ($names === []) {
            throw new RuntimeException(__('Excel-filen innehåller inget kalkylblad.'));
        }

        $sheetName = in_array($wanted, $names, true) ? $wanted : $names[0];

        $rows = function () use ($path, $sheetName): Generator {
            $reader = new XlsxReader(new Options);
            $reader->open($path);

            try {
                foreach ($reader->getSheetIterator() as $sheet) {
                    if ($sheet->getName() !== $sheetName) {
                        continue;
                    }

                    $line = 0;

                    foreach ($sheet->getRowIterator() as $row) {
                        $line++;
                        $cells = array_map(self::cell(...), $row->toArray());

                        if (implode('', $cells) === '') {
                            continue;
                        }

                        yield $line => array_values($cells);
                    }
                }
            } finally {
                $reader->close();
            }
        };

        return self::withHeader('xlsx', null, null, $names, $sheetName, $rows);
    }

    /**
     * A workbook is a ZIP: refuse one that unpacks to much more than the limit, or
     * to absurdly more than it weighs, before a reader opens it.
     */
    private static function checkUnpackedSize(string $path): void
    {
        $zip = new ZipArchive;

        if ($zip->open($path, ZipArchive::RDONLY) !== true) {
            throw new RuntimeException(__('Excel-filen går inte att läsa. Spara den som .xlsx eller CSV och försök igen.'));
        }

        $unpacked = 0;

        for ($i = 0; $i < $zip->numFiles; $i++) {
            $stat = $zip->statIndex($i);

            if ($stat === false) {
                continue;
            }

            $unpacked += (int) $stat['size'];

            if ($stat['comp_size'] > 0 && intdiv((int) $stat['size'], (int) $stat['comp_size']) > self::MAX_RATIO && $stat['size'] > 1_000_000) {
                $zip->close();

                throw new RuntimeException(__('Excel-filen packas upp till orimligt mycket mer än den väger packad och avvisas.'));
            }
        }

        $zip->close();

        if ($unpacked > self::MAX_UNPACKED_BYTES) {
            throw new RuntimeException(__('Excel-filen blir större än 200 MB när den packas upp. Spara den som CSV eller dela upp den.'));
        }
    }

    /**
     * @param  list<string>  $sheets
     * @param  callable(): Generator<int, list<string>>  $rows
     */
    private static function withHeader(string $format, ?string $encoding, ?string $delimiter, array $sheets, ?string $sheet, callable $rows): RegisterTable
    {
        $headers = [];

        foreach ($rows() as $cells) {
            $headers = array_map(fn (string $header): string => ltrim($header, "\u{FEFF}"), $cells);
            break;
        }

        $data = function () use ($rows): Generator {
            $first = true;

            foreach ($rows() as $line => $cells) {
                if ($first) {
                    $first = false;

                    continue;
                }

                yield $line => $cells;
            }
        };

        return new RegisterTable($format, $encoding, $delimiter, $sheets, $sheet, $headers, $data(...));
    }

    private static function cell(mixed $value): string
    {
        return match (true) {
            $value === null => '',
            $value instanceof DateTimeInterface => $value->format('Y-m-d'),
            is_bool($value) => $value ? 'True' : 'False',
            is_float($value) => rtrim(rtrim(sprintf('%.10F', $value), '0'), '.'),
            default => trim((string) $value),
        };
    }

    private static function detectEncoding(string $sample): string
    {
        if (str_starts_with($sample, "\xEF\xBB\xBF")) {
            return 'UTF-8';
        }

        // The sample may end in the middle of a multi-byte character.
        for ($cut = 0; $cut <= 3; $cut++) {
            if (mb_check_encoding(substr($sample, 0, strlen($sample) - $cut), 'UTF-8')) {
                return 'UTF-8';
            }
        }

        return 'Windows-1252';
    }

    private static function decode(string $bytes, string $encoding): string
    {
        return match ($encoding) {
            'CP437' => (string) iconv('CP437', 'UTF-8//IGNORE', $bytes),
            'Windows-1252' => (string) iconv('CP1252', 'UTF-8//IGNORE', $bytes),
            default => $bytes,
        };
    }

    private static function detectDelimiter(string $decodedSample): string
    {
        $firstLine = strtok($decodedSample, "\r\n") ?: '';
        $best = ';';
        $bestCount = 0;

        foreach (self::DELIMITERS as $candidate) {
            $count = self::countOutsideQuotes($firstLine, $candidate);

            if ($count > $bestCount) {
                $best = $candidate;
                $bestCount = $count;
            }
        }

        return $best;
    }

    private static function countOutsideQuotes(string $line, string $needle): int
    {
        $count = 0;
        $quoted = false;

        foreach (str_split($line) as $char) {
            if ($char === '"') {
                $quoted = ! $quoted;
            } elseif (! $quoted && $char === $needle) {
                $count++;
            }
        }

        return $count;
    }
}
