<?php

declare(strict_types=1);

namespace App\Domain\Pricing;

use App\Migration\Import\RegisterFileReader;
use Illuminate\Validation\ValidationException;
use RuntimeException;

/**
 * The price file of a mass price update and of a list export (ADR-082 §8):
 * CSV or XLSX with the columns Artikelnummer, Benämning, Enhet, Från antal,
 * Pris, Rabatt %, Gäller från and Gäller till. Export, edit in a spreadsheet,
 * import is the round trip, so the reader takes what the export writes;
 * Benämning and Gäller till are for the reader's eyes and are not read.
 *
 * The file is streamed through the register importer's reader (ADR-076), so
 * its size does not decide the memory; more than MAX_ROWS rows is refused
 * with a plain Swedish message before anything is planned.
 */
final class PriceFile
{
    public const int MAX_ROWS = 10_000;

    public const array HEADERS = ['Artikelnummer', 'Benämning', 'Enhet', 'Från antal', 'Pris', 'Rabatt %', 'Gäller från', 'Gäller till'];

    /** Normalised header => field. */
    private const array ALIASES = [
        'artikelnummer' => 'article',
        'artikelnr' => 'article',
        'artnr' => 'article',
        'artikel' => 'article',
        'enhet' => 'unit',
        'frånantal' => 'quantity',
        'franantal' => 'quantity',
        'antal' => 'quantity',
        'pris' => 'price',
        'rabatt%' => 'discount',
        'rabatt' => 'discount',
        'rabattprocent' => 'discount',
        'gällerfrån' => 'from',
        'gallerfran' => 'from',
        'frånochmed' => 'from',
    ];

    /**
     * The rows of the file, keyed by the file's line number.
     *
     * @return array<int, array{article: string, unit: string, quantity: string, price: string, discount: string, from: string}>
     *
     * @throws ValidationException on the field `file` when the file cannot be read, lacks a column or is too long
     */
    public static function read(string $path): array
    {
        try {
            $table = RegisterFileReader::open($path);
        } catch (RuntimeException $exception) {
            throw ValidationException::withMessages(['file' => $exception->getMessage()]);
        }

        $columns = [];

        foreach ($table->headers as $index => $header) {
            $field = self::ALIASES[RegisterFileReader::normaliseHeader($header)] ?? null;

            if ($field !== null && ! in_array($field, $columns, true)) {
                $columns[$index] = $field;
            }
        }

        if (! in_array('article', $columns, true)) {
            throw ValidationException::withMessages(['file' => __('Filen saknar kolumnen Artikelnummer. Använd samma kolumner som när du exporterar en prislista.')]);
        }

        if (! in_array('price', $columns, true) && ! in_array('discount', $columns, true)) {
            throw ValidationException::withMessages(['file' => __('Filen saknar kolumnen Pris (eller Rabatt %). Använd samma kolumner som när du exporterar en prislista.')]);
        }

        $rows = [];

        try {
            foreach ($table->rows() as $line => $cells) {
                if (count($rows) >= self::MAX_ROWS) {
                    throw ValidationException::withMessages(['file' => __('Filen har fler än :max rader med priser. Dela upp den i flera filer, till exempel per nummerserie.', ['max' => number_format(self::MAX_ROWS, 0, ',', ' ')])]);
                }

                $row = ['article' => '', 'unit' => '', 'quantity' => '', 'price' => '', 'discount' => '', 'from' => ''];

                foreach ($columns as $index => $field) {
                    $row[$field] = self::unguard(trim((string) ($cells[$index] ?? '')));
                }

                $rows[(int) $line] = $row;
            }
        } catch (RuntimeException $exception) {
            throw ValidationException::withMessages(['file' => $exception->getMessage()]);
        }

        if ($rows === []) {
            throw ValidationException::withMessages(['file' => __('Filen innehåller inga priser under rubrikraden.')]);
        }

        return $rows;
    }

    /** Takes away the apostrophe the export puts before a text that a spreadsheet would read as a formula. */
    private static function unguard(string $value): string
    {
        return strlen($value) > 1 && $value[0] === "'" && in_array($value[1], ['=', '+', '-', '@'], true) ? substr($value, 1) : $value;
    }
}
