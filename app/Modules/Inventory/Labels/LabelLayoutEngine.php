<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Labels;

use App\Modules\Inventory\Models\LabelTemplate;

/**
 * Places a label's elements in millimetre boxes and says, in Swedish, what
 * will not print well. The studio preview and the PDF draw the same boxes,
 * so what the preview shows is what prints.
 *
 * The rules: the inner area is the label less its padding. A QR code sits at
 * the right edge, as large as the height allows (at most 42 % of the width).
 * A barcode sits at the bottom of the text column with its digits under it.
 * The text rows stack in the space above, centred vertically. Font sizes
 * grow with the label (50 × 25 mm is the reference); the Auto layout instead
 * sizes the name and the price (a location's code) to fill the free space.
 */
final class LabelLayoutEngine
{
    private const float GAP = 0.5;

    private const float MIN_BARS_MM = 4.0;

    /**
     * @return array{boxes: list<LabelBox>, warnings: list<string>}
     */
    public static function layout(LabelTemplate $template, LabelData $data, ?string $logoUri = null): array
    {
        $padding = max(0.0, $template->padding_mm);
        $width = $template->width_mm - 2 * $padding;
        $height = $template->height_mm - 2 * $padding;
        $warnings = [];

        if ($width <= 4 || $height <= 4) {
            return ['boxes' => [], 'warnings' => [__('Marginalerna är så stora att inget får plats på etiketten.')]];
        }

        if (2 * $padding > 0.4 * min($template->width_mm, $template->height_mm)) {
            $warnings[] = __('Marginalerna tar mer än 40 % av etiketten. Minska dem så får innehållet mer plats.');
        }

        $align = $template->layout === LabelLayout::Stacked ? 'center' : 'left';
        $boxes = [];

        $qr = 0.0;

        if ($template->shows(LabelElement::Qr) && $data->qr !== null) {
            $qr = round(min($height, $width * 0.42, 40.0), 2);
            [$uri] = Barcodes::qr($data->qr);
            $boxes[] = new LabelBox(LabelElement::Qr, round($padding + $width - $qr, 3), round($padding + ($height - $qr) / 2, 3), $qr, $qr, src: $uri);

            if ($qr < Barcodes::MIN_QR_MM) {
                $warnings[] = __('QR-koden blir bara :size mm på den här etiketten. Under :min mm kan den vara svår att läsa; välj en högre etikett.', ['size' => LabelSheets::mm($qr), 'min' => LabelSheets::mm(Barcodes::MIN_QR_MM)]);
            }
        }

        $textWidth = $width - ($qr > 0 ? $qr + 1.0 : 0.0);
        $barcode = null;

        if ($template->shows(LabelElement::Barcode)) {
            if ($data->barcode !== null) {
                $barcode = $data->barcode;
                $minimum = Barcodes::minimumWidth($barcode['value'], $barcode['symbology']);

                if ($textWidth < $minimum) {
                    $warnings[] = __('Streckkoden (:type) behöver minst :min mm i bredd men får :size mm. Den kan bli svår att läsa; ta bort QR-koden eller välj en bredare etikett.', ['type' => $barcode['symbology']->label(), 'min' => LabelSheets::mm($minimum), 'size' => LabelSheets::mm(round($textWidth, 1))]);
                }
            } elseif ($data->barcodeProblem !== null) {
                $warnings[] = $data->barcodeProblem;
            }
        }

        // Sizes grow with the label; when the content is too high they shrink step by step, down to 60 %.
        $scale = max(0.6, min(3.0, min($height / 21, $textWidth / 46)));

        while (true) {
            $captionPt = max(TextFitter::MIN_PT, round(5.5 * $scale * 2) / 2);
            $bars = min(15.0, $height * 0.3, max(self::MIN_BARS_MM, 7.0 * $scale));
            $blockHeight = $barcode !== null ? $bars + TextFitter::height($captionPt) : 0.0;
            $logo = $logoUri !== null && $template->shows(LabelElement::Logo) ? round(min(4.0 * $scale, $height * 0.25), 2) : 0.0;
            $rows = self::rows($template, $data, $scale);

            if ($template->layout === LabelLayout::Auto) {
                $rows = self::fitPrimary($rows, $data, $textWidth, $height - $blockHeight - ($blockHeight > 0 ? self::GAP : 0.0) - ($logo > 0 ? $logo + self::GAP : 0.0));
            }

            $placed = [];
            $truncated = false;
            $textHeight = $logo > 0 ? $logo + self::GAP : 0.0;

            foreach ($rows as $row) {
                $clamped = TextFitter::clamp($row['text'], $row['pt'], $textWidth, $row['lines'], $row['bold']);

                if ($clamped['lines'] === []) {
                    continue;
                }

                $truncated = $truncated || ($clamped['truncated'] && $row['element'] === LabelElement::Name);
                $rowHeight = TextFitter::height($row['pt'], count($clamped['lines']));
                $placed[] = [$row, $clamped['lines'], $rowHeight];
                $textHeight += $rowHeight + self::GAP;
            }

            $textHeight = max(0.0, $textHeight - self::GAP);
            $free = $height - $blockHeight - ($blockHeight > 0 ? self::GAP : 0.0);

            if ($textHeight > $free && $barcode !== null) {
                $bars = max(self::MIN_BARS_MM, $bars - ($textHeight - $free));
                $blockHeight = $bars + TextFitter::height($captionPt);
                $free = $height - $blockHeight - self::GAP;
            }

            if ($textHeight <= $free + 0.01 || $scale <= 0.6) {
                break;
            }

            $scale = max(0.6, $scale * 0.9);
        }

        if ($truncated) {
            $warnings[] = __('Namnet får inte plats och kortas av med "…". Välj en mindre text, layouten Anpassa automatiskt eller en större etikett.');
        }

        if ($textHeight > $free + 0.01) {
            $warnings[] = __('Innehållet är högre än etiketten och skärs av. Ta bort något, välj mindre text eller en större etikett.');
        }

        $y = $padding + max(0.0, ($free - $textHeight) / 2);

        if ($logo > 0) {
            $boxes[] = new LabelBox(LabelElement::Logo, $padding, $y, $textWidth, $logo, align: $align, src: $logoUri);
            $y += $logo + self::GAP;
        }

        foreach ($placed as [$row, $lines, $rowHeight]) {
            $boxes[] = new LabelBox($row['element'], $padding, round($y, 3), $textWidth, round($rowHeight, 3), $lines, $row['pt'], $row['bold'], $align);
            $y += $rowHeight + self::GAP;
        }

        if ($barcode !== null) {
            $modules = Barcodes::modules($barcode['value'], $barcode['symbology']);
            $barWidth = round(min($textWidth, max(Barcodes::minimumWidth($barcode['value'], $barcode['symbology']), $modules * 0.38)), 2);
            $x = $align === 'center' ? $padding + ($textWidth - $barWidth) / 2 : $padding;
            $boxes[] = new LabelBox(
                LabelElement::Barcode,
                round($x, 3),
                round($padding + $height - $blockHeight, 3),
                $barWidth,
                round($blockHeight, 3),
                align: 'center',
                src: Barcodes::barcodeUri($barcode['value'], $barcode['symbology'], $barWidth, $bars),
                caption: $barcode['text'],
                captionPt: $captionPt,
                barsHeight: round($bars, 3),
            );
        }

        return ['boxes' => $boxes, 'warnings' => array_values(array_unique($warnings))];
    }

    /**
     * The text rows to stack, in order, at their base size.
     *
     * @return list<array{element: LabelElement, text: string, pt: float, bold: bool, lines: int}>
     */
    private static function rows(LabelTemplate $template, LabelData $data, float $scale): array
    {
        $size = fn (float $pt): float => max(TextFitter::MIN_PT, round($pt * $scale * 2) / 2);
        $rows = [];
        $add = function (LabelElement $element, ?string $text, float $pt, bool $bold = false, int $lines = 1) use (&$rows, $template, $size): void {
            if ($template->shows($element) && $text !== null && trim($text) !== '') {
                $rows[] = ['element' => $element, 'text' => trim($text), 'pt' => $size($pt), 'bold' => $bold, 'lines' => $lines];
            }
        };

        if ($data->kind === LabelKind::Location) {
            $add(LabelElement::Code, $data->code, 16, true);
            $add(LabelElement::Name, $data->name, 7, false, 2);
            $add(LabelElement::FreeText, $template->free_text, 6, false, 2);

            return $rows;
        }

        $add(LabelElement::Brand, $data->brand, 6);
        $add(LabelElement::Name, $data->name, 8, true, 2);

        if ($data->price !== null) {
            $add(LabelElement::Price, LabelData::formatPrice($data->price), 14, true);
        }

        if ($data->vatRate !== null) {
            $add(LabelElement::VatNote, LabelData::vatNote($data->vatRate), 5.5);
        }

        $add(LabelElement::ComparisonPrice, $data->comparisonPrice, 5.5);
        $add(LabelElement::Properties, implode(' · ', array_map(fn (array $property): string => "{$property['key']}: {$property['value']}", $data->properties)), 5.5, false, 2);

        $codes = array_filter([
            $template->shows(LabelElement::Code) ? (string) __('Art.nr :number', ['number' => $data->code]) : null,
            $template->shows(LabelElement::Sku) && $data->sku !== null && $data->sku !== '' ? (string) __('SKU :sku', ['sku' => $data->sku]) : null,
        ]);

        if ($codes !== []) {
            $rows[] = ['element' => $template->shows(LabelElement::Code) ? LabelElement::Code : LabelElement::Sku, 'text' => implode(' · ', $codes), 'pt' => $size(6), 'bold' => false, 'lines' => 1];
        }

        $add(LabelElement::FreeText, $template->free_text, 6, false, 2);

        return $rows;
    }

    /**
     * Auto layout: the price (or a location's code) takes up to 55 % of the
     * free height on one line, and the name fills what is left in up to two
     * lines (three without a price).
     *
     * @param  list<array{element: LabelElement, text: string, pt: float, bold: bool, lines: int}>  $rows
     * @return list<array{element: LabelElement, text: string, pt: float, bold: bool, lines: int}>
     */
    private static function fitPrimary(array $rows, LabelData $data, float $width, float $available): array
    {
        $primary = $data->kind === LabelKind::Location ? LabelElement::Code : LabelElement::Price;
        $fixed = 0.0;
        $hasPrimary = false;
        $hasName = false;

        foreach ($rows as $row) {
            $hasPrimary = $hasPrimary || $row['element'] === $primary;
            $hasName = $hasName || $row['element'] === LabelElement::Name;

            if ($row['element'] !== $primary && $row['element'] !== LabelElement::Name) {
                $fixed += TextFitter::height($row['pt'], 1) + self::GAP;
            }
        }

        $free = max(2.0, $available - $fixed);
        $primaryHeight = 0.0;

        foreach ($rows as $index => $row) {
            if ($row['element'] === $primary) {
                $pt = TextFitter::fit($row['text'], $width, $free * ($hasName ? 0.6 : 1.0), 1, 40.0, true);
                $rows[$index]['pt'] = $pt;
                $primaryHeight = TextFitter::height($pt) + self::GAP;
            }
        }

        foreach ($rows as $index => $row) {
            if ($row['element'] === LabelElement::Name) {
                $lines = $hasPrimary ? 2 : 3;
                $rows[$index]['lines'] = $lines;
                $rows[$index]['pt'] = TextFitter::fit($row['text'], $width, max(1.0, $free - $primaryHeight), $lines, $hasPrimary ? 16.0 : 22.0, $row['bold']);
            }
        }

        return $rows;
    }
}
