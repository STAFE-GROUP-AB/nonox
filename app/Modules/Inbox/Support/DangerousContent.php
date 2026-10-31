<?php

declare(strict_types=1);

namespace App\Modules\Inbox\Support;

use ZipArchive;

/**
 * Blocks attachments that can run code: programs, scripts, Office files
 * with macros, HTML and SVG (never rendered on NONOX's origin), and
 * archives holding any of those or that cannot be looked into. Applied
 * always, with or without a virus scanner.
 */
final class DangerousContent
{
    private const array EXTENSIONS = [
        'exe', 'com', 'bat', 'cmd', 'scr', 'pif', 'cpl', 'msi', 'msp', 'dll', 'sys', 'jar', 'class', 'apk', 'app', 'dmg', 'pkg',
        'js', 'jse', 'mjs', 'vbs', 'vbe', 'wsf', 'wsh', 'wsc', 'ps1', 'psm1', 'sh', 'bash', 'zsh', 'py', 'pl', 'rb', 'php', 'phtml', 'hta', 'lnk', 'reg', 'inf', 'scf', 'url',
        'docm', 'dotm', 'xlsm', 'xltm', 'xlam', 'xlsb', 'pptm', 'potm', 'ppam', 'ppsm', 'sldm',
        'html', 'htm', 'xhtml', 'mht', 'mhtml', 'svg', 'svgz',
        'iso', 'img', 'vhd', 'vhdx', 'rar', '7z', 'cab', 'arj', 'ace', 'tar', 'gz', 'tgz', 'bz2', 'xz',
    ];

    private const array ARCHIVE_MIMES = ['application/x-rar-compressed', 'application/vnd.rar', 'application/x-7z-compressed', 'application/gzip', 'application/x-tar', 'application/x-bzip2', 'application/x-xz', 'application/vnd.ms-cab-compressed'];

    /** Office Open XML files are ZIP archives; these are allowed when they hold no macros. */
    private const array OFFICE_EXTENSIONS = ['xlsx', 'docx', 'pptx', 'ods', 'odt'];

    /** Null when the attachment may be kept, otherwise why not, in Swedish. */
    public static function reason(string $filename, string $contents): ?string
    {
        $extension = mb_strtolower(pathinfo($filename, PATHINFO_EXTENSION));
        $mime = AttachmentType::detect($contents);

        if (in_array($extension, self::EXTENSIONS, true)) {
            return __('Filtypen .:extension kan innehålla program eller skript och tas inte emot.', ['extension' => $extension]);
        }

        if (str_starts_with($contents, 'MZ') || str_starts_with($contents, "\x7FELF") || str_starts_with($contents, "\xCF\xFA\xED\xFE") || str_starts_with($contents, '#!')) {
            return __('Filen är ett program och tas inte emot.');
        }

        if (in_array($mime, ['text/html', 'image/svg+xml', 'application/x-dosexec', 'application/x-executable', 'application/x-msi'], true) || in_array($mime, self::ARCHIVE_MIMES, true)) {
            return __('Filtypen kan innehålla program eller skript och tas inte emot.');
        }

        if ($mime === 'application/zip' || str_starts_with($contents, "PK\x03\x04")) {
            return self::zipReason($contents, in_array($extension, self::OFFICE_EXTENSIONS, true));
        }

        return null;
    }

    private static function zipReason(string $contents, bool $office): ?string
    {
        if (! class_exists(ZipArchive::class)) {
            return __('Arkivet kan inte undersökas och tas inte emot.');
        }

        $path = tempnam(sys_get_temp_dir(), 'nonox-zip') ?: '';
        file_put_contents($path, $contents);
        $zip = new ZipArchive;

        try {
            if ($zip->open($path, ZipArchive::RDONLY) !== true) {
                return __('Arkivet kan inte undersökas och tas inte emot.');
            }

            for ($index = 0; $index < $zip->numFiles; $index++) {
                $name = mb_strtolower((string) $zip->getNameIndex($index));

                if (str_ends_with($name, 'vbaproject.bin') || str_contains($name, 'activex')) {
                    return __('Dokumentet innehåller makron och tas inte emot.');
                }

                if (in_array(pathinfo($name, PATHINFO_EXTENSION), self::EXTENSIONS, true) || str_ends_with($name, '.zip')) {
                    return __('Arkivet innehåller :name, som inte tas emot.', ['name' => basename($name)]);
                }
            }

            return null;
        } finally {
            $zip->close();
            @unlink($path);
        }
    }
}
