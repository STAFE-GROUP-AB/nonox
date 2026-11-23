<?php

declare(strict_types=1);

namespace App\Modules\Ai\Support;

use Imagick;
use ImagickException;

/**
 * Photos from an iPhone are often HEIC, which no AI provider reads. They are
 * turned into JPEG for the analysis only; the original stays the receipt.
 */
final class HeicImage
{
    public const array TYPES = ['image/heic', 'image/heif', 'image/heic-sequence', 'image/heif-sequence'];

    private const int QUALITY = 90;

    /** The major brands a HEIF or HEIC file declares in its ftyp box. */
    private const array BRANDS = ['heic', 'heix', 'heim', 'heis', 'hevc', 'hevx', 'hevm', 'hevs', 'mif1', 'msf1'];

    public static function is(string $mime): bool
    {
        return in_array(strtolower($mime), self::TYPES, true);
    }

    /** Whether this server can read HEIC (Imagick built with libheif). */
    public static function supported(): bool
    {
        return class_exists(Imagick::class) && Imagick::queryFormats('HEIC') !== [];
    }

    /**
     * The image as JPEG, base64-encoded.
     *
     * @throws AiAnalysisException when the server cannot read HEIC or the file is broken
     */
    public static function toJpegBase64(string $path): string
    {
        if (! self::supported()) {
            throw new AiAnalysisException(__('Servern kan inte läsa HEIC-bilder från iPhone. Fyll i uppgifterna själv, eller ta bilden i JPEG (Inställningar → Kamera → Format → Mest kompatibelt).'));
        }

        try {
            $image = self::read($path);
            $image->setIteratorIndex(0);
            $image->autoOrient();
            $image->stripImage();
            $image->setImageFormat('jpeg');
            $image->setImageCompressionQuality(self::QUALITY);
            $jpeg = $image->getImageBlob();
            $image->clear();
        } catch (ImagickException) {
            throw new AiAnalysisException(__('HEIC-bilden gick inte att läsa. Fyll i uppgifterna själv eller skicka kvittot som JPEG eller PDF.'));
        }

        return base64_encode($jpeg);
    }

    /**
     * Opens the file with ImageMagick's HEIC decoder only. Left to itself,
     * ImageMagick picks a decoder from the file's content, so an SVG or any
     * other format named .heic would be parsed by that format's decoder. The
     * file must also begin like a HEIF file (an ISO base media "ftyp" box
     * with a HEIF brand) before ImageMagick sees it.
     *
     * @throws ImagickException when the file is not HEIF or cannot be read
     */
    public static function read(string $path): Imagick
    {
        if (! self::looksLikeHeif($path)) {
            throw new ImagickException('Not a HEIF file.');
        }

        return new Imagick("HEIC:{$path}");
    }

    public static function looksLikeHeif(string $path): bool
    {
        $head = @file_get_contents($path, false, null, 0, 12);

        if (! is_string($head) || strlen($head) < 12 || substr($head, 4, 4) !== 'ftyp') {
            return false;
        }

        return in_array(substr($head, 8, 4), self::BRANDS, true);
    }
}
