<?php

declare(strict_types=1);

namespace App\Support\Privacy;

use App\Models\SigningRequest;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use ZipArchive;

/**
 * The signer's register extract as one ZIP (ADR-046 amendment, gap 11): the
 * JSON from DataSubjectExport::signer as registerutdrag.json, and every file
 * it names, read from the private signing disk or decoded from the stored
 * PNG. A file that is no longer on the disk is listed in saknade_filer
 * instead. Written to a temporary file the response deletes after sending.
 */
final class SignerExtractZip
{
    /**
     * @param  array<string, mixed>  $data
     * @param  array<string, array{signer: int, path?: string, png?: string}>  $files
     */
    public static function build(array $data, array $files): string
    {
        $path = (string) tempnam(sys_get_temp_dir(), 'signer-extract');
        $zip = new ZipArchive;

        if ($zip->open($path, ZipArchive::OVERWRITE) !== true) {
            throw new RuntimeException('The register extract could not be written.');
        }

        $missing = [];
        $disk = Storage::disk(SigningRequest::DISK);

        foreach ($files as $name => $file) {
            $bytes = isset($file['png']) ? self::png($file['png']) : (isset($file['path']) && $disk->exists($file['path']) ? $disk->get($file['path']) : null);

            if (! is_string($bytes)) {
                $missing[] = $name;

                continue;
            }

            $zip->addFromString($name, $bytes);
        }

        $zip->addFromString('registerutdrag.json', (string) json_encode([...$data, 'saknade_filer' => $missing], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        $zip->close();

        return $path;
    }

    /** A stored PNG, with or without its data: prefix. */
    private static function png(string $stored): ?string
    {
        $base64 = str_contains($stored, ',') ? substr($stored, (int) strpos($stored, ',') + 1) : $stored;
        $bytes = base64_decode($base64, true);

        return $bytes === false ? null : $bytes;
    }
}
