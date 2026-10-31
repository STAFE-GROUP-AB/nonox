<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Support\Files\EncryptedAdapter;
use Illuminate\Console\Command;
use Illuminate\Contracts\Encryption\Encrypter;
use League\Flysystem\Local\LocalFilesystemAdapter;

/**
 * Encrypts private files that were stored before encryption at rest was
 * turned on. Files already encrypted are left alone, so it can run again.
 */
class EncryptStoredFilesCommand extends Command
{
    protected $signature = 'files:encrypt {--dry-run : Visa bara hur många filer som skulle krypteras}';

    protected $description = 'Krypterar privata filer som sparades innan kryptering i vila slogs på.';

    public function handle(Encrypter $encrypter): int
    {
        $root = storage_path('app/private');

        if (! is_dir($root)) {
            $this->comment('Inga privata filer.');

            return self::SUCCESS;
        }

        $adapter = new EncryptedAdapter(new LocalFilesystemAdapter($root), $encrypter);
        $count = 0;

        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS)) as $file) {
            /** @var \SplFileInfo $file */
            if (! $file->isFile() || str_starts_with($file->getFilename(), '.')) {
                continue;
            }

            $contents = (string) file_get_contents($file->getPathname());

            if (EncryptedAdapter::isEncrypted($contents)) {
                continue;
            }

            $this->line('Krypterar '.substr($file->getPathname(), strlen($root) + 1).'...');
            $count++;

            if (! $this->option('dry-run')) {
                file_put_contents($file->getPathname(), $adapter->encrypt($contents), LOCK_EX);
            }
        }

        $this->comment($this->option('dry-run') ? "{$count} filer skulle krypteras." : "{$count} filer är krypterade.");

        return self::SUCCESS;
    }
}
