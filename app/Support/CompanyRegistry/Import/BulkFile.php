<?php

declare(strict_types=1);

namespace App\Support\CompanyRegistry\Import;

use Illuminate\Support\Facades\Http;
use RuntimeException;
use ZipArchive;

/**
 * A weekly bulk file of the company register (ADR-047): downloaded from
 * Bolagsverket's open-data bucket with Http::sink (never held in memory),
 * or a local file given with --file. A zip is read through
 * ZipArchive::getStream, so its text file is never unpacked to disk.
 */
final class BulkFile
{
    private ?string $path = null;

    private ?ZipArchive $zip = null;

    public function __construct(
        private readonly string $url,
        private readonly ?string $localFile,
        private readonly string $name,
    ) {}

    /** The file's ETag (or size and time for a local file): an unchanged file is not read twice. */
    public function version(): ?string
    {
        if ($this->localFile !== null) {
            if (! is_file($this->localFile)) {
                throw new RuntimeException("Filen {$this->localFile} finns inte.");
            }

            return 'file:'.filesize($this->localFile).':'.filemtime($this->localFile);
        }

        $response = Http::timeout(30)->head($this->url);

        if (! $response->successful()) {
            throw new RuntimeException("Filen kunde inte nås ({$response->status()}): {$this->url}");
        }

        $etag = trim((string) $response->header('ETag'), '"');

        return $etag !== '' ? $etag : ($response->header('Last-Modified') ?: null);
    }

    /**
     * Opens the text file for reading.
     *
     * @return resource
     */
    public function open()
    {
        $path = $this->path();

        if (strtolower(pathinfo($path, PATHINFO_EXTENSION)) !== 'zip') {
            $handle = fopen($path, 'rb');

            if ($handle === false) {
                throw new RuntimeException("Filen {$path} kunde inte öppnas.");
            }

            return $handle;
        }

        $this->zip = new ZipArchive;

        if ($this->zip->open($path) !== true) {
            throw new RuntimeException("Zip-filen {$path} kunde inte öppnas.");
        }

        for ($index = 0; $index < $this->zip->numFiles; $index++) {
            $entry = (string) $this->zip->getNameIndex($index);

            if (str_ends_with(strtolower($entry), '.txt')) {
                $stream = $this->zip->getStream($entry);

                if ($stream !== false) {
                    return $stream;
                }
            }
        }

        throw new RuntimeException("Zip-filen {$path} innehåller ingen textfil.");
    }

    /** @param  resource  $handle */
    public function close($handle): void
    {
        if (is_resource($handle)) {
            fclose($handle);
        }

        $this->zip?->close();
        $this->zip = null;
    }

    private function path(): string
    {
        if ($this->localFile !== null) {
            return $this->localFile;
        }

        if ($this->path !== null) {
            return $this->path;
        }

        $directory = storage_path('app/registry');

        if (! is_dir($directory) && ! mkdir($directory, 0775, true) && ! is_dir($directory)) {
            throw new RuntimeException("Katalogen {$directory} kunde inte skapas.");
        }

        $target = "{$directory}/{$this->name}";
        $response = Http::timeout(1800)->sink($target)->get($this->url);

        if (! $response->successful()) {
            throw new RuntimeException("Filen kunde inte hämtas ({$response->status()}): {$this->url}");
        }

        return $this->path = $target;
    }
}
