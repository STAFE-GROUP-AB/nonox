<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Labels;

use App\Modules\Inventory\Domain\Gtin;
use BaconQrCode\Common\ErrorCorrectionLevel;
use BaconQrCode\Encoder\Encoder;
use Picqer\Barcode\Barcode;
use Picqer\Barcode\Types\TypeCode128;
use Picqer\Barcode\Types\TypeEan13;
use Picqer\Barcode\Types\TypeEan8;

/**
 * Barcodes and QR codes for labels, as small inline SVG data URIs (dompdf
 * and the browser both draw them; nothing is fetched). The bars come from
 * picqer/php-barcode-generator and the QR matrix from bacon/bacon-qr-code;
 * the SVG is drawn here, sized to the box it is placed in, with the quiet
 * zones inside the image so the layout can treat it as one box.
 */
final class Barcodes
{
    /** Quiet zones in modules (left, right) per symbology (GS1 / ISO 15417). */
    private const array QUIET = [
        'EAN13' => [11, 7],
        'EAN8' => [7, 7],
        'CODE128' => [10, 10],
    ];

    /** The quiet zone around a QR code, in modules (the standard asks for 4; labels are small). */
    public const int QR_QUIET = 2;

    /**
     * Narrowest bar that prints reliably on a 203 dpi label printer (two
     * dots); a practical limit, not a standard.
     */
    public const float MIN_MODULE_MM = 0.25;

    /** Smallest QR code (with its quiet zone) that phone cameras read well. */
    public const float MIN_QR_MM = 10.0;

    /** @var array<string, Barcode> */
    private static array $barcodes = [];

    /** @var array<string, array{0: string, 1: int}> */
    private static array $qr = [];

    /**
     * The barcode for an article: its GTIN as EAN-13 or EAN-8 when the check
     * digit is right (a GTIN-12 becomes EAN-13 with a leading 0), else null.
     *
     * @return ?array{0: string, 1: Symbology}
     */
    public static function forGtin(?string $gtin): ?array
    {
        $gtin = trim((string) $gtin);

        if ($gtin === '' || ! Gtin::isValid($gtin)) {
            return null;
        }

        return match (strlen($gtin)) {
            13 => [$gtin, Symbology::Ean13],
            8 => [$gtin, Symbology::Ean8],
            12 => ['0'.$gtin, Symbology::Ean13],
            default => null,
        };
    }

    /** Whether Code 128 can carry the text (printable ASCII only: no å, ä or ö). */
    public static function code128Encodable(string $value): bool
    {
        return $value !== '' && strlen($value) <= 48 && preg_match('/^[\x20-\x7E]+$/', $value) === 1;
    }

    /** The width of the symbol in modules, quiet zones included. */
    public static function modules(string $value, Symbology $symbology): int
    {
        [$left, $right] = self::QUIET[$symbology->value];

        return self::barcode($value, $symbology)->getWidth() + $left + $right;
    }

    /** The narrowest box (mm) the symbol fits in at the minimum bar width. */
    public static function minimumWidth(string $value, Symbology $symbology): float
    {
        return round(self::modules($value, $symbology) * self::MIN_MODULE_MM, 1);
    }

    /** The barcode as an SVG data URI filling a box of the given size (mm). */
    public static function barcodeUri(string $value, Symbology $symbology, float $widthMm, float $heightMm): string
    {
        $barcode = self::barcode($value, $symbology);
        [$left] = self::QUIET[$symbology->value];
        $modules = self::modules($value, $symbology);
        $height = round($modules * max($heightMm, 0.1) / max($widthMm, 0.1), 3);
        $path = '';
        $x = $left;

        foreach ($barcode->getBars() as $bar) {
            $width = $bar->getWidth();

            if ($bar->isBar() && $width > 0) {
                $path .= "M{$x} 0h{$width}v{$height}h-{$width}z";
            }

            $x += $width;
        }

        $svg = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 '.$modules.' '.$height.'" width="'.$modules.'" height="'.$height.'" shape-rendering="crispEdges"><path d="'.$path.'" fill="#000"/></svg>';

        return 'data:image/svg+xml;base64,'.base64_encode($svg);
    }

    /**
     * The QR code as an SVG data URI (square, with its quiet zone), and its
     * size in modules. Error correction M; byte mode without an ECI header,
     * which more hand scanners read.
     *
     * @return array{0: string, 1: int}
     */
    public static function qr(string $value): array
    {
        if (isset(self::$qr[$value])) {
            return self::$qr[$value];
        }

        $matrix = Encoder::encode($value, ErrorCorrectionLevel::M(), 'UTF-8', null, false)->getMatrix();
        $size = $matrix->getWidth();
        $total = $size + 2 * self::QR_QUIET;
        $path = '';

        for ($y = 0; $y < $size; $y++) {
            for ($x = 0; $x < $size; $x++) {
                if ($matrix->get($x, $y) === 1) {
                    $path .= 'M'.($x + self::QR_QUIET).' '.($y + self::QR_QUIET).'h1v1h-1z';
                }
            }
        }

        $svg = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 '.$total.' '.$total.'" width="'.$total.'" height="'.$total.'" shape-rendering="crispEdges"><path d="'.$path.'" fill="#000"/></svg>';

        return self::$qr[$value] = ['data:image/svg+xml;base64,'.base64_encode($svg), $total];
    }

    private static function barcode(string $value, Symbology $symbology): Barcode
    {
        $key = $symbology->value.':'.$value;

        return self::$barcodes[$key] ??= match ($symbology) {
            Symbology::Ean13 => (new TypeEan13)->getBarcode($value),
            Symbology::Ean8 => (new TypeEan8)->getBarcode($value),
            Symbology::Code128 => (new TypeCode128)->getBarcode($value),
        };
    }
}
