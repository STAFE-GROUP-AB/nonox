<?php

declare(strict_types=1);

namespace App\Modules\Inbox\Support\Imap;

/**
 * Resolves through the system: IPv4 with the system resolver (so the hosts
 * file counts, as it does when connecting) and IPv6 from DNS.
 */
final class SystemHostResolver implements HostResolver
{
    public function resolve(string $host): array
    {
        $addresses = gethostbynamel($host) ?: [];
        $records = @dns_get_record($host, DNS_AAAA) ?: [];

        foreach ($records as $record) {
            if (is_string($record['ipv6'] ?? null)) {
                $addresses[] = $record['ipv6'];
            }
        }

        return array_values(array_unique($addresses));
    }
}
