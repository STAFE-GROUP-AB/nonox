<?php

declare(strict_types=1);

namespace App\Modules\Connect\Webhooks;

/**
 * Looks up the addresses of a host name (A and AAAA). Bound in the
 * container so tests can answer without the network.
 */
class HostResolver
{
    /** @return list<string> every IPv4 and IPv6 address the name resolves to; an IP literal resolves to itself */
    public function resolve(string $host): array
    {
        if (filter_var($host, FILTER_VALIDATE_IP) !== false) {
            return [$host];
        }

        $records = @dns_get_record($host, DNS_A | DNS_AAAA);
        $addresses = [];

        foreach (is_array($records) ? $records : [] as $record) {
            $address = $record['ip'] ?? $record['ipv6'] ?? null;

            if (is_string($address)) {
                $addresses[] = $address;
            }
        }

        return array_values(array_unique($addresses));
    }
}
