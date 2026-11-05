<?php

declare(strict_types=1);

namespace App\Support\Sru;

use ZipArchive;

/** The two SRU files in one ZIP, as the pages hand them over. */
final class SruZip
{
    /** @param  array<string, string>  $files  file name => content */
    public static function of(array $files): string
    {
        $path = (string) tempnam(sys_get_temp_dir(), 'sru');
        $zip = new ZipArchive;
        $zip->open($path, ZipArchive::OVERWRITE);

        foreach ($files as $filename => $content) {
            $zip->addFromString($filename, $content);
        }

        $zip->close();
        $content = (string) file_get_contents($path);
        @unlink($path);

        return $content;
    }
}
