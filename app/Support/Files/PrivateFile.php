<?php

declare(strict_types=1);

namespace App\Support\Files;

use Illuminate\Support\Facades\Storage;
use RuntimeException;

/**
 * A decrypted copy of a stored file in a temporary file, for code that
 * needs a path (streaming SIE parser, AI document reading). The copy is
 * removed when the callback returns.
 */
final class PrivateFile
{
    /**
     * @template T
     *
     * @param  callable(string): T  $callback  receives the temporary path
     * @return T
     */
    public static function withLocalCopy(string $disk, string $path, callable $callback): mixed
    {
        $temporary = tempnam(sys_get_temp_dir(), 'nonox-file') ?: throw new RuntimeException('No temporary file.');

        try {
            file_put_contents($temporary, (string) Storage::disk($disk)->get($path));
            chmod($temporary, 0600);

            return $callback($temporary);
        } finally {
            @unlink($temporary);
        }
    }

    /**
     * A decrypted temporary copy that is removed when the request ends, for
     * code that hands a path on to a reader within the same request.
     */
    public static function temporaryCopy(string $disk, string $path): string
    {
        $temporary = tempnam(sys_get_temp_dir(), 'nonox-file') ?: throw new RuntimeException('No temporary file.');
        file_put_contents($temporary, (string) Storage::disk($disk)->get($path));
        chmod($temporary, 0600);
        app()->terminating(fn () => @unlink($temporary));
        register_shutdown_function(fn () => @unlink($temporary));

        return $temporary;
    }
}
