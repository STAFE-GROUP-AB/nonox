<?php

declare(strict_types=1);

namespace App\Domain\Documents;

use App\Support\FileName;
use App\Support\Files\StoredFiles;
use App\Support\Pdf\PdfText;
use finfo;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Stores one version's file. The type comes from the file's bytes, never from
 * its name; the stored name is generated; the file is written to the private
 * disk, whose EncryptedAdapter encrypts it at rest, and its SHA-256 is taken
 * from the bytes as uploaded.
 */
final class DocumentFile
{
    /** @var array<string, string> MIME type => stored extension */
    public const array ALLOWED = [
        'application/pdf' => 'pdf',
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
        'image/webp' => 'webp',
        'text/plain' => 'txt',
        'application/vnd.openxmlformats-officedocument.wordprocessingml.document' => 'docx',
        'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet' => 'xlsx',
        'application/vnd.oasis.opendocument.text' => 'odt',
        'application/vnd.oasis.opendocument.spreadsheet' => 'ods',
    ];

    /** Shown in the browser (in the InlineFile sandbox); every other type is downloaded. */
    public const array INLINE = ['application/pdf', 'image/jpeg', 'image/png', 'image/webp'];

    public const int MAX_KILOBYTES = 10240;

    /** The search keeps at most this much of a PDF's text. */
    public const int MAX_TEXT = 200_000;

    /**
     * @return array{path: string, mime: string, size: int, sha256: string, original_name: string, extracted_text: ?string}
     */
    public static function store(int $companyId, int $documentId, UploadedFile $file, bool $extractText): array
    {
        Validator::make(['file' => $file], [
            'file' => ['required', 'file', 'max:'.self::MAX_KILOBYTES],
        ], [
            'file.required' => __('Välj en fil.'),
            'file.max' => __('Filen är för stor. Den får vara högst 10 MB.'),
        ])->validate();

        $contents = (string) file_get_contents($file->getRealPath());
        $mime = (string) (new finfo(FILEINFO_MIME_TYPE))->buffer($contents);

        if (! array_key_exists($mime, self::ALLOWED)) {
            throw ValidationException::withMessages([
                'file' => __('Dokumentet måste vara en PDF, en bild (JPG, PNG eller WebP), en textfil eller ett Word-, Excel- eller OpenDocument-dokument.'),
            ]);
        }

        $path = "dokument/{$companyId}/{$documentId}/".Str::uuid()->toString().'.'.self::ALLOWED[$mime];
        Storage::disk(StoredFiles::DISK)->put($path, $contents);

        $text = $extractText && $mime === 'application/pdf' ? PdfText::extract($contents) : null;

        return [
            'path' => $path,
            'mime' => $mime,
            'size' => strlen($contents),
            'sha256' => hash('sha256', $contents),
            'original_name' => FileName::clean($file->getClientOriginalName()),
            'extracted_text' => $text === null ? null : mb_substr(str_replace("\0", '', $text), 0, self::MAX_TEXT),
        ];
    }
}
