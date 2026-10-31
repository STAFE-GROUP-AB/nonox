<?php

declare(strict_types=1);

namespace App\Support\Files;

use finfo;
use Illuminate\Contracts\Encryption\Encrypter;
use League\Flysystem\Config;
use League\Flysystem\FileAttributes;
use League\Flysystem\FilesystemAdapter;
use League\Flysystem\UnableToReadFile;

/**
 * Encrypts every file at rest (AGENTS.md §10, GDPR art. 32): underlag,
 * receipts, payslips, AGI and payment files, SIE and bank files. Contents
 * are encrypted with the application key (AES-256 with a MAC) when written
 * and decrypted when read, so the rest of the code uses Storage as usual.
 * A file written before encryption was turned on (no marker) is read as
 * it is; `files:encrypt` encrypts those in place.
 *
 * Losing APP_KEY makes the files unreadable, like the encrypted columns:
 * the key must be kept as safely as the backups.
 */
final class EncryptedAdapter implements FilesystemAdapter
{
    public const string MARKER = "NXENC1\n";

    public function __construct(
        private readonly FilesystemAdapter $inner,
        private readonly Encrypter $encrypter,
    ) {}

    public static function isEncrypted(string $contents): bool
    {
        return str_starts_with($contents, self::MARKER);
    }

    public function encrypt(string $contents): string
    {
        return self::MARKER.$this->encrypter->encryptString($contents);
    }

    public function decrypt(string $contents): string
    {
        return self::isEncrypted($contents)
            ? $this->encrypter->decryptString(substr($contents, strlen(self::MARKER)))
            : $contents;
    }

    public function fileExists(string $path): bool
    {
        return $this->inner->fileExists($path);
    }

    public function directoryExists(string $path): bool
    {
        return $this->inner->directoryExists($path);
    }

    public function write(string $path, string $contents, Config $config): void
    {
        $this->inner->write($path, $this->encrypt($contents), $config);
    }

    public function writeStream(string $path, $contents, Config $config): void
    {
        $this->write($path, (string) stream_get_contents($contents), $config);
    }

    public function read(string $path): string
    {
        return $this->decrypt($this->inner->read($path));
    }

    public function readStream(string $path)
    {
        $stream = fopen('php://temp', 'w+b');

        if ($stream === false) {
            throw UnableToReadFile::fromLocation($path, 'Could not open a temporary stream.');
        }

        fwrite($stream, $this->read($path));
        rewind($stream);

        return $stream;
    }

    public function delete(string $path): void
    {
        $this->inner->delete($path);
    }

    public function deleteDirectory(string $path): void
    {
        $this->inner->deleteDirectory($path);
    }

    public function createDirectory(string $path, Config $config): void
    {
        $this->inner->createDirectory($path, $config);
    }

    public function setVisibility(string $path, string $visibility): void
    {
        $this->inner->setVisibility($path, $visibility);
    }

    public function visibility(string $path): FileAttributes
    {
        return $this->inner->visibility($path);
    }

    public function mimeType(string $path): FileAttributes
    {
        $mime = (new finfo(FILEINFO_MIME_TYPE))->buffer($this->read($path));

        return new FileAttributes($path, null, null, null, is_string($mime) ? $mime : 'application/octet-stream');
    }

    public function lastModified(string $path): FileAttributes
    {
        return $this->inner->lastModified($path);
    }

    public function fileSize(string $path): FileAttributes
    {
        return new FileAttributes($path, strlen($this->read($path)));
    }

    public function listContents(string $path, bool $deep): iterable
    {
        return $this->inner->listContents($path, $deep);
    }

    public function move(string $source, string $destination, Config $config): void
    {
        $this->inner->move($source, $destination, $config);
    }

    public function copy(string $source, string $destination, Config $config): void
    {
        $this->inner->copy($source, $destination, $config);
    }
}
