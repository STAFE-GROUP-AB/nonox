<?php

declare(strict_types=1);

namespace App\Modules\Inbox\Support\Imap;

/**
 * Looks up the addresses of a host name. The real one asks the system
 * (SystemHostResolver); tests bind a fake, so they never depend on DNS.
 */
interface HostResolver
{
    /**
     * Every IPv4 and IPv6 address the name has, IPv4 first. Empty when the
     * name does not resolve.
     *
     * @return list<string>
     */
    public function resolve(string $host): array;
}
