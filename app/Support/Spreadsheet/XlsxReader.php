<?php

declare(strict_types=1);

namespace App\Support\Spreadsheet;

use RuntimeException;
use SimpleXMLElement;
use ZipArchive;

/**
 * Reads the first worksheet of an XLSX file (Office Open XML) as rows of
 * strings: numbers as written in the file, shared and inline strings as
 * text. Enough for the simple exports of cash registers; formulas are
 * read as their cached values. Uses only ext-zip and SimpleXML, so no
 * spreadsheet package is needed (ADR-023).
 *
 * The archive is checked before it is opened: at most 20 MB unpacked, so a
 * small "zip bomb" cannot fill the memory.
 */
final class XlsxReader
{
    public const int MAX_UNPACKED_BYTES = 20 * 1024 * 1024;

    public const int MAX_ROWS = 5000;

    /**
     * @return list<list<string>>
     *
     * @throws RuntimeException when the file is not a readable workbook
     */
    public static function rows(string $contents): array
    {
        if (! class_exists(ZipArchive::class)) {
            throw new RuntimeException('ext-zip is not installed.');
        }

        $path = tempnam(sys_get_temp_dir(), 'nonox-xlsx') ?: throw new RuntimeException('No temporary file.');
        file_put_contents($path, $contents);
        $zip = new ZipArchive;

        try {
            if ($zip->open($path, ZipArchive::RDONLY) !== true) {
                throw new RuntimeException('Not a ZIP archive.');
            }

            $unpacked = 0;

            for ($index = 0; $index < $zip->numFiles; $index++) {
                $unpacked += (int) ($zip->statIndex($index)['size'] ?? 0);
            }

            if ($unpacked > self::MAX_UNPACKED_BYTES) {
                throw new RuntimeException('The workbook is too large.');
            }

            $strings = self::sharedStrings($zip->getFromName('xl/sharedStrings.xml'));
            $sheet = $zip->getFromName('xl/worksheets/sheet1.xml');

            if ($sheet === false) {
                throw new RuntimeException('The workbook has no first sheet.');
            }

            return self::sheetRows($sheet, $strings);
        } finally {
            $zip->close();
            @unlink($path);
        }
    }

    /** @return list<string> */
    private static function sharedStrings(string|false $xml): array
    {
        if ($xml === false) {
            return [];
        }

        $document = self::xml($xml);
        $strings = [];

        foreach ($document->children() as $item) {
            $text = '';

            foreach ($item->xpath('.//*[local-name()="t"]') ?: [] as $part) {
                $text .= (string) $part;
            }

            $strings[] = $text;
        }

        return $strings;
    }

    /**
     * @param  list<string>  $strings
     * @return list<list<string>>
     */
    private static function sheetRows(string $xml, array $strings): array
    {
        $document = self::xml($xml);
        $rows = [];

        foreach ($document->xpath('//*[local-name()="sheetData"]/*[local-name()="row"]') ?: [] as $row) {
            $cells = [];

            foreach ($row->xpath('./*[local-name()="c"]') ?: [] as $cell) {
                $column = self::columnIndex((string) $cell['r']);
                $type = (string) $cell['t'];
                $value = match ($type) {
                    's' => $strings[(int) self::child($cell, 'v')] ?? '',
                    'inlineStr' => implode('', array_map('strval', $cell->xpath('.//*[local-name()="t"]') ?: [])),
                    default => self::child($cell, 'v'),
                };

                $cells[$column ?? count($cells)] = trim($value);
            }

            if ($cells === []) {
                continue;
            }

            $filled = array_fill(0, max(array_keys($cells)) + 1, '');
            $rows[] = array_values(array_replace($filled, $cells));

            if (count($rows) >= self::MAX_ROWS) {
                break;
            }
        }

        return $rows;
    }

    private static function child(SimpleXMLElement $cell, string $name): string
    {
        $found = $cell->xpath('./*[local-name()="'.$name.'"]');

        return $found === false || $found === null || $found === [] ? '' : (string) $found[0];
    }

    /** "C7" => 2 */
    private static function columnIndex(string $reference): ?int
    {
        if (preg_match('/^([A-Z]+)\d+$/', $reference, $match) !== 1) {
            return null;
        }

        $index = 0;

        foreach (str_split($match[1]) as $letter) {
            $index = $index * 26 + (ord($letter) - 64);
        }

        return $index - 1;
    }

    private static function xml(string $xml): SimpleXMLElement
    {
        if (preg_match('/<!DOCTYPE/i', $xml) === 1) {
            throw new RuntimeException('Document type declarations are not accepted.');
        }

        $document = simplexml_load_string($xml, SimpleXMLElement::class, LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING);

        if ($document === false) {
            throw new RuntimeException('The workbook XML cannot be read.');
        }

        return $document;
    }
}
