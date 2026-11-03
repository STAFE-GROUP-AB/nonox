<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\File;

/**
 * Deletes expired entries of the encrypted chat (ADR-045) when its store is
 * files: a file cache removes an expired entry only when someone reads it, so
 * a chat nobody opened again would otherwise stay on disk as ciphertext until
 * then. Redis expires keys by itself; then this does nothing.
 */
class PruneChatCommand extends Command
{
    protected $signature = 'chat:prune';

    protected $description = 'Raderar utgångna krypterade chattar när chatten sparas som filer.';

    public function handle(): int
    {
        if (config('cache.stores.chat.driver') !== 'file') {
            $this->comment('Chatten sparas inte som filer; inget att rensa.');

            return self::SUCCESS;
        }

        $path = (string) config('cache.stores.chat.path');

        if (! File::isDirectory($path)) {
            $this->comment('Inga chattfiler.');

            return self::SUCCESS;
        }

        $now = Carbon::now()->getTimestamp();
        $deleted = 0;

        foreach (File::allFiles($path, true) as $file) {
            // A file cache entry starts with its expiry as a ten-digit Unix time.
            $head = @file_get_contents($file->getPathname(), false, null, 0, 10);
            $expiry = is_string($head) && ctype_digit($head) ? (int) $head : 0;

            if ($expiry > 0 && $expiry <= $now && @unlink($file->getPathname())) {
                $deleted++;
            }
        }

        $this->comment("Raderade {$deleted} utgångna chattfiler.");

        return self::SUCCESS;
    }
}
