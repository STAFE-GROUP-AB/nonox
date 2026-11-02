<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Labels;

use App\Models\Article;
use App\Modules\Inventory\Models\StockLocation;
use App\Support\Money\Money;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;

/**
 * What one label says, independent of the layout: built from an article or
 * a stock location. Prices are Money in öre (never floats); a price is left
 * out when the person printing may not see prices.
 *
 * What the codes carry is agreed with the scanner (ADR-040, ADR-041):
 * a location is "L:" + full_code (QR and Code 128); an article is its GTIN
 * as EAN-13/EAN-8 when valid, otherwise its scanner code, otherwise
 * "A:" + article number (Code 128 and QR).
 */
final readonly class LabelData
{
    public const string LOCATION_PREFIX = 'L:';

    public const string ARTICLE_PREFIX = 'A:';

    /**
     * @param  ?array{value: string, symbology: Symbology, text: string}  $barcode
     * @param  list<array{key: string, value: string}>  $properties
     */
    public function __construct(
        public LabelKind $kind,
        public int $id,
        public string $name,
        public string $code,
        public ?Money $price = null,
        public ?int $vatRate = null,
        public ?string $comparisonPrice = null,
        public ?array $barcode = null,
        public ?string $qr = null,
        public ?string $sku = null,
        public ?string $brand = null,
        public array $properties = [],
        public ?string $barcodeProblem = null,
    ) {}

    /** @param  list<string>  $propertyKeys */
    public static function forArticle(Article $article, bool $withPrices, array $propertyKeys = []): self
    {
        $gtin = Barcodes::forGtin($article->gtin);
        $fallback = trim((string) $article->scanner_code) !== '' ? trim((string) $article->scanner_code) : self::ARTICLE_PREFIX.$article->number;
        $problem = null;

        if ($gtin !== null) {
            $barcode = ['value' => $gtin[0], 'symbology' => $gtin[1], 'text' => $gtin[0]];
            $qr = $gtin[0];
        } elseif (Barcodes::code128Encodable($fallback)) {
            $barcode = ['value' => $fallback, 'symbology' => Symbology::Code128, 'text' => $fallback];
            $qr = $fallback;
        } else {
            $barcode = null;
            $qr = $fallback;
            $problem = self::unencodable();
        }

        $price = null;

        if ($withPrices) {
            $price = $article->price_ore->plus($article->price_ore->percent($article->vat_rate));
        }

        $wanted = array_flip($propertyKeys);
        $properties = [];

        foreach ($article->properties ?? [] as $property) {
            $key = trim($property['key']);
            $value = trim($property['value']);

            if ($key !== '' && $value !== '' && isset($wanted[$key])) {
                $properties[] = ['key' => $key, 'value' => $value];
            }
        }

        return new self(
            kind: LabelKind::Article,
            id: $article->id,
            name: $article->name,
            code: $article->number,
            price: $price,
            vatRate: $withPrices ? $article->vat_rate : null,
            comparisonPrice: $price === null ? null : self::comparison($price, $article->unit, $article->weight_grams),
            barcode: $barcode,
            qr: $qr,
            sku: $article->sku,
            brand: $article->brand,
            properties: $properties,
            barcodeProblem: $problem,
        );
    }

    public static function forLocation(StockLocation $location): self
    {
        $value = self::LOCATION_PREFIX.$location->full_code;
        $encodable = Barcodes::code128Encodable($value);

        return new self(
            kind: LabelKind::Location,
            id: $location->id,
            name: (string) ($location->name ?? $location->type->label()),
            code: $location->full_code,
            barcode: $encodable ? ['value' => $value, 'symbology' => Symbology::Code128, 'text' => $location->full_code] : null,
            qr: $value,
            barcodeProblem: $encodable ? null : self::unencodable(),
        );
    }

    /** Example data for the studio preview when the company has no article or location yet. */
    public static function sample(LabelKind $kind): self
    {
        if ($kind === LabelKind::Location) {
            return new self(LabelKind::Location, 0, __('Hylla A, fack 3'), 'HL-A-03-2', barcode: ['value' => 'L:HL-A-03-2', 'symbology' => Symbology::Code128, 'text' => 'HL-A-03-2'], qr: 'L:HL-A-03-2');
        }

        $price = Money::ofOre(14900);

        return new self(
            kind: LabelKind::Article,
            id: 0,
            name: __('Kaffekopp i stengods, blå'),
            code: '1001',
            price: $price,
            vatRate: 2500,
            comparisonPrice: self::comparison($price, 'st', 350),
            barcode: ['value' => '7350000000016', 'symbology' => Symbology::Ean13, 'text' => '7350000000016'],
            qr: '7350000000016',
            sku: 'KOPP-BL-30',
            brand: __('Exempelmärket'),
            properties: [['key' => __('Färg'), 'value' => __('Blå')], ['key' => __('Volym'), 'value' => '30 cl']],
        );
    }

    private static function unencodable(): string
    {
        return (string) __('Koden innehåller tecken som en streckkod inte kan bära (till exempel å, ä eller ö). QR-koden fungerar.');
    }

    /** A price for a label: "149 kr" when whole kronor, else "149,50 kr". */
    public static function formatPrice(Money $price): string
    {
        $text = $price->format();

        return $price->ore % 100 === 0 ? str_replace(',00', '', $text) : $text;
    }

    /** "inkl. moms 25 %" (12 % and 6 % likewise; 0 % says no VAT). */
    public static function vatNote(int $basisPoints): string
    {
        if ($basisPoints === 0) {
            return __('momsfri');
        }

        $percent = rtrim(rtrim(number_format($basisPoints / 100, 2, ',', ''), '0'), ',');

        return __('inkl. moms :rate %', ['rate' => $percent]);
    }

    /**
     * The price per kilo, litre or metre: the price itself when the article
     * is sold by that unit, or per kilo from the weight of one piece.
     * TODO(legal-verify): which goods need a jämförpris and how it must be
     * shown (Prisinformationslagen 2004:347 and Konsumentverket's
     * föreskrifter); offered as an option, never forced.
     */
    public static function comparison(Money $price, string $unit, ?int $weightGrams): ?string
    {
        $unit = mb_strtolower(trim($unit));
        $perUnit = ['kg' => 'kg', 'kilo' => 'kg', 'l' => 'l', 'liter' => 'l', 'm' => 'm', 'meter' => 'm'];

        if (isset($perUnit[$unit])) {
            return __('Jämförpris :price/:unit', ['price' => $price->format(), 'unit' => $perUnit[$unit]]);
        }

        if ($weightGrams === null || $weightGrams <= 0) {
            return null;
        }

        $perKilo = Money::ofOre(BigDecimal::of($price->ore)->multipliedBy(1000)->dividedBy($weightGrams, 0, RoundingMode::HalfUp)->toInt());

        return __('Jämförpris :price/:unit', ['price' => $perKilo->format(), 'unit' => 'kg']);
    }
}
