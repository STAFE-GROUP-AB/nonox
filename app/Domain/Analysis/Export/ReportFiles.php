<?php

declare(strict_types=1);

namespace App\Domain\Analysis\Export;

use App\Models\Company;
use App\Support\Csv;
use Dompdf\Dompdf;
use Dompdf\Options;
use OpenSpout\Common\Entity\Cell;
use OpenSpout\Common\Entity\Cell\NumericCell;
use OpenSpout\Common\Entity\Cell\StringCell;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Writer\XLSX\Writer;
use RuntimeException;

/**
 * Writes a ReportTable as CSV, XLSX or PDF (ADR-081 gap 7, E18). CSV is UTF-8
 * with a byte order mark and semicolons, decimals with a comma, text guarded
 * against spreadsheet formulas (Csv::cell). XLSX holds money as numbers in
 * kronor, text as explicit string cells (Row::fromValues would turn a text
 * starting with = into a formula, so every cell is built by hand), through the package the register import already uses (MIT).
 * PDF is a landscape A4 list through Dompdf, for tables that fit a page width.
 */
final class ReportFiles
{
    public const int PDF_MAX_ROWS = 400;

    public const int PDF_MAX_COLUMNS = 14;

    public static function csv(ReportTable $table): string
    {
        $out = fopen('php://temp', 'w+b');

        if ($out === false) {
            throw new RuntimeException('No temporary stream.');
        }

        fwrite($out, "\xEF\xBB\xBF");
        fputcsv($out, Csv::row([$table->title]), ';', '"', '');
        fputcsv($out, Csv::row([$table->subtitle]), ';', '"', '');
        fputcsv($out, Csv::row($table->header), ';', '"', '');

        foreach ($table->rows as $row) {
            fputcsv($out, Csv::row(array_map(fn (int|string|null $value): string => self::text($table, $value), $row)), ';', '"', '');
        }

        rewind($out);
        $contents = (string) stream_get_contents($out);
        fclose($out);

        return $contents;
    }

    public static function xlsx(ReportTable $table): string
    {
        $path = tempnam(sys_get_temp_dir(), 'nonox-report') ?: throw new RuntimeException('No temporary file.');
        $writer = new Writer;

        try {
            $writer->openToFile($path);
            $writer->addRow(new Row([new StringCell($table->title, null)]));
            $writer->addRow(new Row([new StringCell($table->subtitle, null)]));
            $writer->addRow(new Row(array_map(fn (string $heading): Cell => new StringCell($heading, null), $table->header)));

            foreach ($table->rows as $row) {
                $writer->addRow(new Row(array_map(
                    fn (int|string|null $value): Cell => match (true) {
                        $value === null => new StringCell('', null),
                        is_int($value) => new NumericCell($table->money ? $value / 100 : $value, null),
                        default => new StringCell($value, null),
                    },
                    $row,
                )));
            }

            $writer->close();

            return (string) file_get_contents($path);
        } finally {
            @unlink($path);
        }
    }

    public static function tooLargeForPdf(ReportTable $table): bool
    {
        return count($table->rows) > self::PDF_MAX_ROWS || count($table->header) > self::PDF_MAX_COLUMNS;
    }

    public static function pdf(Company $company, ReportTable $table): string
    {
        if (self::tooLargeForPdf($table)) {
            throw new RuntimeException('The table is too large for a PDF.');
        }

        $options = new Options;
        $options->setIsRemoteEnabled(false);
        $options->setDefaultFont('DejaVu Sans');
        $options->setChroot(resource_path('views'));

        $rows = array_map(fn (array $row): array => array_map(fn (int|string|null $value): array => [
            'text' => self::text($table, $value, "\u{00A0}"),
            'figure' => ReportTable::isFigure($value),
        ], $row), $table->rows);

        $pdf = new Dompdf($options);
        $pdf->loadHtml(view('analysis.report-pdf', ['company' => $company, 'table' => $table, 'rows' => $rows])->render());
        $pdf->setPaper('A4', 'landscape');
        $pdf->render();

        return (string) $pdf->output();
    }

    /** A figure as text: money in kronor with a comma, a count as it is. */
    private static function text(ReportTable $table, int|string|null $value, string $thousands = ''): string
    {
        if ($value === null) {
            return '';
        }

        if (is_string($value)) {
            return $value;
        }

        return $table->money ? number_format($value / 100, 2, ',', $thousands) : number_format($value, 0, ',', $thousands);
    }
}
