<?php

declare(strict_types=1);

namespace App\Modules\Connect\Webhooks;

/**
 * A checked webhook destination: the host name (for TLS and the Host
 * header), the port and the one public address the request is pinned to.
 */
final readonly class WebhookTarget
{
    public function __construct(
        public string $host,
        public int $port,
        public string $address,
    ) {}

    /** For CURLOPT_RESOLVE: "host:port:address", IPv6 in brackets. */
    public function curlResolve(): string
    {
        $address = str_contains($this->address, ':') ? "[{$this->address}]" : $this->address;

        return "{$this->host}:{$this->port}:{$address}";
    }
}
