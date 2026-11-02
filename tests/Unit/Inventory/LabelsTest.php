<?php

declare(strict_types=1);

use App\Models\Article;
use App\Modules\Inventory\Labels\Barcodes;
use App\Modules\Inventory\Labels\LabelData;
use App\Modules\Inventory\Labels\LabelElement;
use App\Modules\Inventory\Labels\LabelKind;
use App\Modules\Inventory\Labels\LabelLayoutEngine;
use App\Modules\Inventory\Labels\LabelSheets;
use App\Modules\Inventory\Labels\Symbology;
use App\Modules\Inventory\Labels\TextFitter;
use App\Modules\Inventory\Models\LabelTemplate;
use App\Modules\Inventory\Models\StockLocation;
use App\Support\Money\Money;

/*
 * The pure parts of labels (ADR-041): barcode choice, prices from öre,
 * text fitting in DejaVu Sans, grid positions and the layout warnings.
 */

function labelArticle(array $attributes = []): Article
{
    $article = new Article([
        'number' => '1001',
        'name' => 'Kaffekopp',
        'unit' => 'st',
        'price_ore' => Money::ofOre(11920),
        'vat_rate' => 2500,
        ...$attributes,
    ]);
    $article->id = 7;

    return $article;
}

function labelTemplate(array $attributes = []): LabelTemplate
{
    return new LabelTemplate([
        'kind' => 'ARTICLE',
        'media' => 'ROLL',
        'width_mm' => 50,
        'height_mm' => 25,
        'padding_mm' => 2,
        'columns' => 1,
        'rows' => 1,
        'layout' => 'STACKED',
        'elements' => ['name', 'price', 'vat_note', 'barcode'],
        ...$attributes,
    ]);
}

it('prints a valid GTIN as EAN and falls back to Code 128 otherwise', function (?string $gtin, ?string $scanner, string $value, Symbology $symbology) {
    $data = LabelData::forArticle(labelArticle(['gtin' => $gtin, 'scanner_code' => $scanner]), true);

    expect($data->barcode['value'])->toBe($value)
        ->and($data->barcode['symbology'])->toBe($symbology)
        ->and($data->qr)->toBe($value);
})->with([
    'EAN-13' => ['7350000000016', null, '7350000000016', Symbology::Ean13],
    'EAN-8' => ['96385074', null, '96385074', Symbology::Ean8],
    'GTIN-12 as EAN-13' => ['036000291452', null, '0036000291452', Symbology::Ean13],
    'bad check digit, scanner code' => ['7350000000019', 'KOPP-1', 'KOPP-1', Symbology::Code128],
    'bad check digit, number' => ['7350000000019', null, 'A:1001', Symbology::Code128],
    'no GTIN' => [null, null, 'A:1001', Symbology::Code128],
]);

it('leaves the barcode out and keeps the QR code when Code 128 cannot carry the text', function () {
    $data = LabelData::forArticle(labelArticle(['scanner_code' => 'KÖK-1']), true);

    expect($data->barcode)->toBeNull()
        ->and($data->qr)->toBe('KÖK-1')
        ->and($data->barcodeProblem)->toContain('å, ä eller ö');

    $layout = LabelLayoutEngine::layout(labelTemplate(), $data);
    expect($layout['warnings'])->toContain($data->barcodeProblem);
});

it('formats label prices from öre incl. VAT, rounding half up', function (int $net, int $rate, string $expected) {
    $data = LabelData::forArticle(labelArticle(['price_ore' => Money::ofOre($net), 'vat_rate' => $rate]), true);

    expect(LabelData::formatPrice($data->price))->toBe($expected);
})->with([
    'whole kronor' => [11920, 2500, "149\u{00A0}kr"],
    'with öre' => [11960, 2500, "149,50\u{00A0}kr"],
    '12 % half up' => [13305, 1200, "149,02\u{00A0}kr"],
    '6 % half up' => [9425, 600, "99,91\u{00A0}kr"],
    'thousands' => [99920, 2500, "1\u{00A0}249\u{00A0}kr"],
]);

it('writes the VAT note and the comparison price', function () {
    expect(LabelData::vatNote(2500))->toBe('inkl. moms 25 %')
        ->and(LabelData::vatNote(600))->toBe('inkl. moms 6 %')
        ->and(LabelData::vatNote(0))->toBe('momsfri')
        ->and(LabelData::comparison(Money::ofOre(14900), 'st', 350))->toBe("Jämförpris 425,71\u{00A0}kr/kg")
        ->and(LabelData::comparison(Money::ofOre(4990), 'kg', null))->toBe("Jämförpris 49,90\u{00A0}kr/kg")
        ->and(LabelData::comparison(Money::ofOre(4990), 'st', null))->toBeNull();
});

it('gives no price to a label printed without prices', function () {
    $data = LabelData::forArticle(labelArticle(), false);

    expect($data->price)->toBeNull()
        ->and($data->vatRate)->toBeNull()
        ->and($data->comparisonPrice)->toBeNull();
});

it('encodes a location as L: plus its full code', function () {
    $location = new StockLocation(['type' => 'BIN', 'code' => '2', 'full_code' => 'HL-A-03-2', 'name' => null]);
    $location->id = 3;
    $data = LabelData::forLocation($location);

    expect($data->qr)->toBe('L:HL-A-03-2')
        ->and($data->barcode)->toBe(['value' => 'L:HL-A-03-2', 'symbology' => Symbology::Code128, 'text' => 'HL-A-03-2'])
        ->and($data->name)->toBe('Pallplats/fack');
});

it('measures, wraps and fits Swedish text in DejaVu Sans', function () {
    expect(TextFitter::width('Å', 10))->toBeGreaterThan(TextFitter::width('i', 10))
        ->and(TextFitter::width('ÅÄÖ', 10, true))->toBeGreaterThan(TextFitter::width('ÅÄÖ', 10));

    $name = 'Rökt älgfärs från Västerbotten i småförpackning';
    $lines = TextFitter::wrap($name, 8, 40);
    expect(count($lines))->toBeGreaterThan(1);

    foreach ($lines as $line) {
        expect(TextFitter::width($line, 8))->toBeLessThanOrEqual(40.0);
    }

    $clamped = TextFitter::clamp($name, 8, 30, 1);
    expect($clamped['truncated'])->toBeTrue()
        ->and($clamped['lines'][0])->toEndWith('…')
        ->and(TextFitter::width($clamped['lines'][0], 8))->toBeLessThanOrEqual(30.0);

    $fitted = TextFitter::fit($name, 46, 8, 2, 20, true);
    expect($fitted)->toBeLessThan(20.0)->toBeGreaterThanOrEqual(TextFitter::MIN_PT)
        ->and(count(TextFitter::wrap($name, $fitted, 46, true)))->toBeLessThanOrEqual(2)
        ->and(TextFitter::fit($name, 5, 2, 1, 20))->toBe(TextFitter::MIN_PT);
});

it('places labels on the sheet at the margin plus the pitch', function () {
    $template = labelTemplate(['media' => 'SHEET', 'columns' => 2, 'rows' => 7, 'width_mm' => 99.1, 'height_mm' => 38.1, 'sheet_top_mm' => 15.15, 'sheet_left_mm' => 4.65, 'pitch_x_mm' => 101.6, 'pitch_y_mm' => 38.1]);

    expect($template->perPage())->toBe(14)
        ->and($template->origin(0))->toBe([4.65, 15.15])
        ->and($template->origin(1))->toBe([106.25, 15.15])
        ->and($template->origin(13))->toBe([106.25, 243.75])
        ->and($template->pageSize())->toBe([210.0, 297.0])
        ->and(labelTemplate()->pageSize())->toBe([50.0, 25.0]);

    expect(LabelSheets::plainGrid(57, 32))->toBe(['columns' => 3, 'rows' => 8, 'top' => 20.5, 'left' => 19.5, 'pitch_x' => 57.0, 'pitch_y' => 32.0]);

    foreach (LabelSheets::SHEETS as $sheet) {
        expect($sheet['left'] + ($sheet['columns'] - 1) * $sheet['pitch_x'] + $sheet['width'])->toBeLessThanOrEqual(210.0)
            ->and($sheet['top'] + ($sheet['rows'] - 1) * $sheet['pitch_y'] + $sheet['height'])->toBeLessThanOrEqual(297.0);
    }
});

it('keeps every box inside the label and warns about what will not print well', function () {
    $data = LabelData::sample(LabelKind::Article);
    $full = LabelLayoutEngine::layout(labelTemplate(['width_mm' => 100, 'height_mm' => 50, 'layout' => 'LEFT', 'elements' => array_map(fn (LabelElement $element): string => $element->value, LabelElement::cases()), 'free_text' => 'Tål diskmaskin', 'property_keys' => ['Färg']]), $data);

    expect($full['warnings'])->toBe([]);

    foreach ($full['boxes'] as $box) {
        expect($box->x)->toBeGreaterThanOrEqual(2.0)
            ->and($box->x + $box->width)->toBeLessThanOrEqual(98.01)
            ->and($box->y + $box->height)->toBeLessThanOrEqual(48.01);
    }

    $small = LabelLayoutEngine::layout(labelTemplate(['width_mm' => 30, 'height_mm' => 12, 'elements' => ['name', 'price', 'vat_note', 'comparison_price', 'qr', 'barcode', 'code', 'sku']]), $data);
    $warnings = implode(' ', $small['warnings']);
    expect($warnings)->toContain('QR-koden blir bara')
        ->toContain('Streckkoden (EAN-13) behöver minst');

    $margins = LabelLayoutEngine::layout(labelTemplate(['padding_mm' => 5.5]), $data);
    expect(implode(' ', $margins['warnings']))->toContain('Marginalerna tar mer än 40 %');

    $long = LabelLayoutEngine::layout(labelTemplate(['elements' => ['name']]), new LabelData(LabelKind::Article, 1, str_repeat('Mycket långt artikelnamn ', 8), '1'));
    expect(implode(' ', $long['warnings']))->toContain('Namnet får inte plats');

    $auto = LabelLayoutEngine::layout(labelTemplate(['layout' => 'AUTO', 'elements' => ['name', 'price']]), $data);
    $stacked = LabelLayoutEngine::layout(labelTemplate(['elements' => ['name', 'price']]), $data);
    $price = fn (array $layout): float => collect($layout['boxes'])->firstWhere('element', LabelElement::Price)->pt;
    expect($price($auto))->toBeGreaterThan($price($stacked));
});

it('reuses the QR image for the same value and keeps quiet zones in the barcode width', function () {
    expect(Barcodes::qr('L:HL-A-01'))->toBe(Barcodes::qr('L:HL-A-01'))
        ->and(Barcodes::modules('7350000000016', Symbology::Ean13))->toBe(95 + 18)
        ->and(Barcodes::minimumWidth('7350000000016', Symbology::Ean13))->toBe(28.3)
        ->and(Barcodes::code128Encodable('HL-A-01'))->toBeTrue()
        ->and(Barcodes::code128Encodable('Å-1'))->toBeFalse()
        ->and(Barcodes::barcodeUri('7350000000016', Symbology::Ean13, 30, 10))->toStartWith('data:image/svg+xml;base64,');
});
