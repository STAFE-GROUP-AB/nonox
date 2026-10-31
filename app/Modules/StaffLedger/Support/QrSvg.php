<?php

declare(strict_types=1);

namespace App\Modules\StaffLedger\Support;

use BaconQrCode\Renderer\Image\SvgImageBackEnd;
use BaconQrCode\Renderer\ImageRenderer;
use BaconQrCode\Renderer\RendererStyle\RendererStyle;
use BaconQrCode\Writer;

/**
 * A QR code as SVG, with bacon/bacon-qr-code (BSD-2-Clause), which Fortify
 * already brings in.
 */
final class QrSvg
{
    public static function render(string $text, int $size = 320): string
    {
        $svg = (new Writer(new ImageRenderer(new RendererStyle($size, 2), new SvgImageBackEnd)))->writeString($text);

        return trim((string) preg_replace('/^<\?xml.*?\?>\s*/', '', $svg));
    }
}
