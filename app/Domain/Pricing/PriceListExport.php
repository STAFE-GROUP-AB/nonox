<?php

declare(strict_types=1);

namespace App\Domain\Pricing;

use App\Models\PriceList;
use App\Models\PriceListItem;
use App\Models\PriceUpdateRun;
use App\Models\PriceUpdateRunLine;
use App\Support\Csv;
use Carbon\CarbonImmutable;
use OpenSpout\Common\Entity\Cell;
use OpenSpout\Common\Entity\Cell\NumericCell;
use OpenSpout\Common\Entity\Cell\StringCell;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Writer\XLSX\Writer;
use RuntimeException;

/**
 * Exports a price list as CSV or XLSX in the columns of PriceFile, so
 * "export, edit in a spreadsheet, import as a price update" is a round trip
 * (ADR-082 §8), and the diff of a mass price update as CSV for the archive.
 *
 * Text that a spreadsheet would run as a formula (starting with =, +, -, @)
 * gets an apostrophe first (Csv::cell), in the XLSX too; amounts are numbers.
 * CSV is UTF-8 with a byte order mark, semicolons and decimal commas.
 */
final class PriceListExport
{
    /**
     * The running and coming prices of the list, ordered by article number.
     *
     * @return list<array{0: string, 1: string, 2: string, 3: float|int|null, 4: ?int, 5: ?int, 6: string, 7: string}> article number, name, unit, from quantity, price in minor units, discount in basis points, valid from, valid to
     */
    public static function rows(PriceList $list): array
    {
        $today = CarbonImmutable::today()->toDateString();

        $items = PriceListItem::query()
            ->where('price_list_items.price_list_id', $list->id)
            ->whereNull('price_list_items.archived_at')
            ->where(fn ($query) => $query->whereNull('price_list_items.valid_to')->orWhere('price_list_items.valid_to', '>=', $today))
            ->join('articles', 'articles.id', '=', 'price_list_items.article_id')
            ->select('price_list_items.*')
            ->with(['article:id,number,name', 'unit:id,code'])
            ->orderBy('articles.number')
            ->orderBy('price_list_items.unit_id')
            ->orderBy('price_list_items.min_quantity_milli')
            ->orderBy('price_list_items.valid_from')
            ->get();

        return array_values($items->map(fn (PriceListItem $item): array => [
            (string) $item->article?->number,
            (string) $item->article?->name,
            (string) $item->unit?->code,
            $item->min_quantity_milli === 0 ? null : $item->min_quantity_milli / 1000,
            $item->price_minor === null ? null : $item->price_minor,
            $item->discount_bp === null ? null : $item->discount_bp,
            // A price that has started gets no start date, so a file read back in starts on the update's own date.
            $item->valid_from !== null && $item->valid_from->greaterThan(CarbonImmutable::today()) ? $item->valid_from->toDateString() : '',
            (string) $item->valid_to?->toDateString(),
        ])->all());
    }

    public static function csv(PriceList $list): string
    {
        return self::csvOf(PriceFile::HEADERS, array_map(fn (array $row): array => [
            $row[0], $row[1], $row[2],
            $row[3] === null ? '' : str_replace('.', ',', (string) $row[3]),
            $row[4] === null ? '' : self::decimal((int) $row[4]),
            $row[5] === null ? '' : self::decimal((int) $row[5]),
            $row[6], $row[7],
        ], self::rows($list)));
    }

    public static function xlsx(PriceList $list): string
    {
        $path = tempnam(sys_get_temp_dir(), 'nonox-prices') ?: throw new RuntimeException('No temporary file.');
        $writer = new Writer;

        try {
            $writer->openToFile($path);
            $writer->addRow(new Row(array_map(fn (string $heading): Cell => new StringCell($heading, null), PriceFile::HEADERS)));

            foreach (self::rows($list) as $row) {
                $writer->addRow(new Row([
                    new StringCell(Csv::cell($row[0]), null),
                    new StringCell(Csv::cell($row[1]), null),
                    new StringCell(Csv::cell($row[2]), null),
                    $row[3] === null ? new StringCell('', null) : new NumericCell($row[3], null),
                    $row[4] === null ? new StringCell('', null) : new NumericCell((int) $row[4] / 100, null),
                    $row[5] === null ? new StringCell('', null) : new NumericCell((int) $row[5] / 100, null),
                    new StringCell((string) $row[6], null),
                    new StringCell((string) $row[7], null),
                ]));
            }

            $writer->close();

            return (string) file_get_contents($path);
        } finally {
            @unlink($path);
        }
    }

    /**
     * The diff of a run, one row per line, for the archive: kept when the
     * uploaded file is pruned (decision 10).
     *
     * @param  iterable<PriceUpdateRunLine>  $lines  with article, priceList and unit loaded
     */
    public static function diffCsv(PriceUpdateRun $run, iterable $lines): string
    {
        $rows = [];

        foreach ($lines as $line) {
            $rows[] = [
                (string) $line->position,
                $line->target === PriceUpdateTarget::Base ? __('Grundpris') : (string) $line->priceList?->code,
                (string) ($line->article->number ?? $line->article_number),
                (string) $line->unit?->code,
                $line->min_quantity_milli === 0 ? '' : str_replace('.', ',', (string) ($line->min_quantity_milli / 1000)),
                $line->old_minor === null ? ($line->old_discount_bp === null ? '' : self::decimal($line->old_discount_bp).' %') : self::decimal($line->old_minor),
                $line->new_minor === null ? ($line->new_discount_bp === null ? '' : self::decimal($line->new_discount_bp).' %') : self::decimal($line->new_minor),
                $line->valid_from->toDateString(),
                $line->status->label(),
                (string) $line->reason?->label(),
            ];
        }

        return self::csvOf([__('Rad'), __('Pris'), __('Artikelnummer'), __('Enhet'), __('Från antal'), __('Gammalt'), __('Nytt'), __('Gäller från'), __('Status'), __('Orsak')], $rows);
    }

    /** Minor units (or basis points) as a decimal with a comma and two decimals, without a thousands separator. */
    public static function decimal(int $minor): string
    {
        $sign = $minor < 0 ? '-' : '';
        $minor = abs($minor);

        return $sign.intdiv($minor, 100).','.str_pad((string) ($minor % 100), 2, '0', STR_PAD_LEFT);
    }

    /**
     * @param  list<string>  $header
     * @param  list<list<int|string|float|null>>  $rows
     */
    private static function csvOf(array $header, array $rows): string
    {
        $out = fopen('php://temp', 'w+b') ?: throw new RuntimeException('No temporary stream.');

        fwrite($out, "\xEF\xBB\xBF");
        fputcsv($out, Csv::row($header), ';', '"', '');

        foreach ($rows as $row) {
            fputcsv($out, Csv::row($row), ';', '"', '');
        }

        rewind($out);
        $contents = (string) stream_get_contents($out);
        fclose($out);

        return $contents;
    }
}
