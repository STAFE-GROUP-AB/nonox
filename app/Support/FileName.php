<?php

declare(strict_types=1);

namespace App\Support;

/**
 * A safe display name for an uploaded file: no directory parts, no
 * backslashes or control characters (which break Content-Disposition on
 * download), at most 255 characters.
 */
final class FileName
{
    public static function clean(string $name): string
    {
        $name = (string) preg_replace('/[\\\\\/\x00-\x1F\x7F]+/u', '_', $name);
        $name = trim(basename($name), " .\t");

        return mb_substr($name === '' ? 'fil' : $name, 0, 255);
    }
}
