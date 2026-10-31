<?php

declare(strict_types=1);

namespace App\Modules\Inbox\Support;

use finfo;

/**
 * The type of an attachment, from its bytes and never from the sender's
 * claim, and what kind of document it may be for the interpreters.
 */
final class AttachmentType
{
    public static function detect(string $contents): string
    {
        return (string) (new finfo(FILEINFO_MIME_TYPE))->buffer($contents);
    }

    public static function isSie(string $filename, string $contents): bool
    {
        $extension = mb_strtolower(pathinfo($filename, PATHINFO_EXTENSION));
        $head = ltrim(substr($contents, 0, 400), "\xEF\xBB\xBF \r\n\t");

        return str_starts_with($head, '#FLAGGA') || (in_array($extension, ['se', 'si', 'sie'], true) && str_starts_with($head, '#'));
    }

    public static function isXml(string $filename, string $contents): bool
    {
        $head = ltrim(substr($contents, 0, 200), "\xEF\xBB\xBF \r\n\t");

        return str_starts_with($head, '<?xml') || mb_strtolower(pathinfo($filename, PATHINFO_EXTENSION)) === 'xml';
    }

    public static function isSpreadsheet(string $filename, string $contents): bool
    {
        $extension = mb_strtolower(pathinfo($filename, PATHINFO_EXTENSION));

        return $extension === 'xlsx' && str_starts_with($contents, "PK\x03\x04");
    }

    public static function isText(string $filename, string $mime): bool
    {
        $extension = mb_strtolower(pathinfo($filename, PATHINFO_EXTENSION));

        return in_array($extension, ['csv', 'txt', 'tsv', 'skv'], true) || in_array($mime, ['text/plain', 'text/csv'], true);
    }

    /** A stored file extension for the type; "bin" when unknown. */
    public static function storedExtension(string $filename): string
    {
        $extension = mb_strtolower(pathinfo($filename, PATHINFO_EXTENSION));

        return preg_match('/^[a-z0-9]{1,8}$/', $extension) === 1 ? $extension : 'bin';
    }
}
