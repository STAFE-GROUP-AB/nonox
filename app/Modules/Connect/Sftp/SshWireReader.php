<?php

declare(strict_types=1);

namespace App\Modules\Connect\Sftp;

/**
 * Reads the length-prefixed strings of the SSH wire format (RFC 4251 §5):
 * a 32-bit big-endian length, then that many bytes.
 */
final class SshWireReader
{
    private int $offset = 0;

    public function __construct(private readonly string $bytes) {}

    /** The next string, or null when the data ends early. */
    public function string(): ?string
    {
        if (strlen($this->bytes) - $this->offset < 4) {
            return null;
        }

        /** @var array{1: int} $length */
        $length = unpack('N', substr($this->bytes, $this->offset, 4));
        $this->offset += 4;

        if ($length[1] > strlen($this->bytes) - $this->offset) {
            return null;
        }

        $value = substr($this->bytes, $this->offset, $length[1]);
        $this->offset += $length[1];

        return $value;
    }

    public function atEnd(): bool
    {
        return $this->offset === strlen($this->bytes);
    }
}
