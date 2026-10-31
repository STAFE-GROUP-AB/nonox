<?php

declare(strict_types=1);

namespace App\Modules\Inbox\Support;

/**
 * Scans with ClamAV's daemon over its INSTREAM command, on a Unix socket
 * ("/var/run/clamav/clamd.ctl") or TCP ("tcp://127.0.0.1:3310").
 */
final class ClamdScanner implements VirusScanner
{
    private const int CHUNK = 8192;

    public function __construct(private readonly string $socket, private readonly int $timeout = 30) {}

    public function enabled(): bool
    {
        return true;
    }

    public function scan(string $contents): ?string
    {
        $address = str_contains($this->socket, '://') ? $this->socket : "unix://{$this->socket}";
        $connection = @stream_socket_client($address, $code, $message, $this->timeout);

        if ($connection === false) {
            throw new VirusScanFailed("clamd is not reachable: {$message}");
        }

        try {
            stream_set_timeout($connection, $this->timeout);
            fwrite($connection, "zINSTREAM\0");

            foreach (str_split($contents === '' ? "\0" : $contents, self::CHUNK) as $chunk) {
                fwrite($connection, pack('N', strlen($chunk)).$chunk);
            }

            fwrite($connection, pack('N', 0));
            $reply = trim((string) stream_get_contents($connection), "\0\r\n ");
        } finally {
            fclose($connection);
        }

        if (str_ends_with($reply, 'OK')) {
            return null;
        }

        if (preg_match('/:\s*(.+)\s+FOUND$/', $reply, $match) === 1) {
            return __('Viruskontrollen hittade :name.', ['name' => $match[1]]);
        }

        throw new VirusScanFailed("clamd answered: {$reply}");
    }
}
