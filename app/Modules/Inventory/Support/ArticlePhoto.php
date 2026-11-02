<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Support;

use App\Modules\Ai\Support\HeicImage;
use GdImage;
use Illuminate\Http\UploadedFile;
use Illuminate\Validation\ValidationException;
use Imagick;
use ImagickException;

/**
 * An article photo as NONOX keeps it: a JPEG at most MAX_SIDE pixels on its
 * longest side, turned upright from the camera's EXIF orientation and with
 * its metadata (GPS position, camera) left behind, since only the pixels
 * are copied. Phone photos of 12 MB become a few hundred kB. HEIC from an
 * iPhone is read when the server's Imagick can (HeicImage), otherwise
 * refused with advice.
 */
final class ArticlePhoto
{
    public const array EXTENSIONS = ['jpg', 'jpeg', 'png', 'webp', 'heic', 'heif'];

    /** Livewire's own limit on a temporary upload is 12 MB. */
    public const int MAX_KILOBYTES = 12288;

    public const int MAX_SIDE = 1600;

    public const int THUMBNAIL_SIDE = 240;

    /** About a 48-megapixel phone photo; larger is refused before decoding. */
    public const int MAX_PIXELS = 50_000_000;

    private const int QUALITY = 82;

    /**
     * The upload as a stored JPEG.
     *
     * @throws ValidationException under the given field when it is not a readable picture
     */
    public static function fromUpload(UploadedFile $file, string $field = 'photo'): string
    {
        $extension = strtolower($file->getClientOriginalExtension());

        if (! in_array($extension, self::EXTENSIONS, true)) {
            throw ValidationException::withMessages([$field => __('Välj en bild i JPG, PNG, WebP eller HEIC.')]);
        }

        if ($file->getSize() > self::MAX_KILOBYTES * 1024) {
            throw ValidationException::withMessages([$field => __('Bilden är för stor. Den får vara högst 12 MB.')]);
        }

        $path = (string) $file->getRealPath();
        $heic = in_array($extension, ['heic', 'heif'], true) || HeicImage::is((string) $file->getMimeType());
        // Imagick turns a HEIC picture upright itself.
        $contents = $heic ? self::heicToJpeg($path, $field) : (string) file_get_contents($path);

        return self::normalise($contents, $heic ? 1 : self::orientation($path)) ?? throw ValidationException::withMessages([$field => __('Bilden gick inte att läsa. Prova en annan bild eller ta ett nytt foto.')]);
    }

    /** A smaller copy for lists and the article form; null when the stored file cannot be read. */
    public static function thumbnail(string $jpeg, int $side = self::THUMBNAIL_SIDE): ?string
    {
        return self::normalise($jpeg, 1, $side);
    }

    /**
     * Scales the picture down to fit $side and encodes it as JPEG. A
     * transparent PNG or WebP gets a white background.
     */
    private static function normalise(string $contents, int $orientation, int $side = self::MAX_SIDE): ?string
    {
        $size = @getimagesizefromstring($contents);

        // Read the size first: a small file can declare a huge picture and exhaust memory.
        if ($size === false || $size[0] < 1 || $size[1] < 1 || $size[0] * $size[1] > self::MAX_PIXELS) {
            return null;
        }

        $image = @imagecreatefromstring($contents);

        if ($image === false) {
            return null;
        }

        $image = self::upright($image, $orientation);
        $width = imagesx($image);
        $height = imagesy($image);
        $scale = min(1, $side / max($width, $height));
        $targetWidth = max(1, (int) round($width * $scale));
        $targetHeight = max(1, (int) round($height * $scale));
        $target = imagecreatetruecolor($targetWidth, $targetHeight);

        if ($target === false) {
            return null;
        }

        imagefill($target, 0, 0, (int) imagecolorallocate($target, 255, 255, 255));
        imagecopyresampled($target, $image, 0, 0, 0, 0, $targetWidth, $targetHeight, $width, $height);

        ob_start();
        imagejpeg($target, null, self::QUALITY);

        return (string) ob_get_clean();
    }

    /** The EXIF orientation a camera wrote (1 = upright); 1 when unknown. */
    private static function orientation(string $path): int
    {
        if (! function_exists('exif_read_data')) {
            return 1;
        }

        $exif = @exif_read_data($path);

        return is_array($exif) && isset($exif['Orientation']) ? (int) $exif['Orientation'] : 1;
    }

    private static function upright(GdImage $image, int $orientation): GdImage
    {
        $rotated = match ($orientation) {
            3 => imagerotate($image, 180, 0),
            6 => imagerotate($image, -90, 0),
            8 => imagerotate($image, 90, 0),
            default => $image,
        };

        return $rotated === false ? $image : $rotated;
    }

    private static function heicToJpeg(string $path, string $field): string
    {
        if (! HeicImage::supported()) {
            throw ValidationException::withMessages([$field => __('Servern kan inte läsa HEIC-bilder från iPhone. Ta bilden i JPEG (Inställningar → Kamera → Format → Mest kompatibelt) eller välj en annan bild.')]);
        }

        try {
            $image = new Imagick($path);
            $image->setIteratorIndex(0);
            $image->autoOrient();
            $image->stripImage();
            $image->setImageFormat('jpeg');
            $jpeg = $image->getImageBlob();
            $image->clear();
        } catch (ImagickException) {
            throw ValidationException::withMessages([$field => __('Bilden gick inte att läsa. Prova en annan bild eller ta ett nytt foto.')]);
        }

        return $jpeg;
    }
}
