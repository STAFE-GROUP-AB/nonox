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
            $image = new Imagick($path);
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
}
