<?php

declare(strict_types=1);

namespace App\Domain\Signing;

use Illuminate\Validation\ValidationException;

/**
 * Checks a drawn signature from the signature pad (ADR-046): a PNG data URL
 * of at most 200 kB, with a real PNG header and at most 2400 × 1200 pixels.
 * Returns the base64 of the PNG; anything else is refused, so nothing but a
 * small image is ever stored or put into a PDF.
 */
final class SignatureImage
{
    public const int MAX_BYTES = 200_000;

    public const int MAX_WIDTH = 2400;

    public const int MAX_HEIGHT = 1200;

    private const string PREFIX = 'data:image/png;base64,';

    /** @throws ValidationException */
    public static function base64(string $dataUrl): string
    {
        $fail = fn (): ValidationException => ValidationException::withMessages(['signature' => __('Rita din namnteckning i rutan, eller skriv ditt namn som underskrift.')]);

        if (! str_starts_with($dataUrl, self::PREFIX) || strlen($dataUrl) > (int) ceil(self::MAX_BYTES * 4 / 3) + strlen(self::PREFIX) + 4) {
            throw $fail();
        }

        $base64 = substr($dataUrl, strlen(self::PREFIX));
        $bytes = base64_decode($base64, true);

        if ($bytes === false || $bytes === '' || strlen($bytes) > self::MAX_BYTES || ! str_starts_with($bytes, "\x89PNG\r\n\x1a\n")) {
            throw $fail();
        }

        $size = @getimagesizefromstring($bytes);

        if ($size === false || $size[2] !== IMAGETYPE_PNG || $size[0] < 1 || $size[1] < 1 || $size[0] > self::MAX_WIDTH || $size[1] > self::MAX_HEIGHT) {
            throw $fail();
        }

        return base64_encode($bytes);
    }
}
