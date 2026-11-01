<?php

declare(strict_types=1);

namespace App\Modules\Inbox\Support\Imap;

/**
 * Decides whether NONOX may connect to the IMAP server a company entered,
 * and to which address (ADR-023). The server connects from inside the
 * hosting network, so without this the settings would let anyone probe
 * internal services. A name is refused when it is malformed or a number in
 * disguise (2130706433, 0177.0.0.1, 0x7f.1), when it does not resolve, or
 * when any address it resolves to is loopback, private, link-local, shared
 * (CGNAT), reserved, documentation, multicast, IPv4-mapped or a NAT64 or
 * 6to4 address. The client then connects to the vetted address itself, so
 * the name cannot resolve to something else between the check and the
 * connection.
 *
 * A self-hoster whose mail server is on their own network may allow
 * private addresses with services.inbox.imap_allow_private.
 */
final readonly class ImapHost
{
    /** IMAP with STARTTLS (143) and IMAP over TLS (993). */
    public const array PORTS = [143, 993];

    /** IPv6 prefixes that are refused although PHP's global range filter lets them through. */
    private const array IPV6_REFUSED = [
        ['ff00::', 8],       // multicast
        ['64:ff9b::', 96],   // NAT64, carries an IPv4 address
        ['64:ff9b:1::', 48], // local NAT64
        ['2002::', 16],      // 6to4, carries an IPv4 address
    ];

    public function __construct(private HostResolver $resolver) {}

    public static function allowsPrivate(): bool
    {
        return (bool) config('services.inbox.imap_allow_private', false);
    }

    /**
     * The address to connect to.
     *
     * @throws UnsafeImapHost
     */
    public function vet(string $host): string
    {
        $host = rtrim(mb_strtolower(trim($host)), '.');

        if (! self::isWellFormed($host)) {
            throw new UnsafeImapHost(UnsafeImapHost::INVALID, $host);
        }

        $addresses = filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false ? [$host] : $this->resolver->resolve($host);
        $addresses = array_values(array_filter($addresses, fn (string $address): bool => filter_var($address, FILTER_VALIDATE_IP) !== false));

        if ($addresses === []) {
            throw new UnsafeImapHost(UnsafeImapHost::UNRESOLVABLE, $host);
        }

        if (! self::allowsPrivate()) {
            foreach ($addresses as $address) {
                if (! self::isPublic($address)) {
                    throw new UnsafeImapHost(UnsafeImapHost::INTERNAL, $host);
                }
            }
        }

        return $addresses[0];
    }

    /**
     * A host name of letters, digits, dots and hyphens, or a dotted IPv4
     * address written the usual way. A name whose last label is a number
     * (decimal, octal or hex) must be such an address: resolvers read
     * 2130706433 and 0177.0.0.1 as 127.0.0.1.
     */
    public static function isWellFormed(string $host): bool
    {
        if ($host === '' || strlen($host) > 253 || preg_match('/^[a-z0-9.-]+$/', $host) !== 1) {
            return false;
        }

        $labels = explode('.', $host);

        foreach ($labels as $label) {
            if ($label === '' || strlen($label) > 63 || str_starts_with($label, '-') || str_ends_with($label, '-')) {
                return false;
            }
        }

        if (preg_match('/^(0x[0-9a-f]*|[0-9]+)$/', (string) end($labels)) === 1) {
            return filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false;
        }

        return true;
    }

    /** Whether the address is on the public internet. */
    public static function isPublic(string $ip): bool
    {
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_GLOBAL_RANGE) === false) {
            return false;
        }

        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false) {
            return ! self::inPrefix($ip, '224.0.0.0', 4);
        }

        foreach (self::IPV6_REFUSED as [$prefix, $bits]) {
            if (self::inPrefix($ip, $prefix, $bits)) {
                return false;
            }
        }

        return true;
    }

    /** The address as stream_socket_client wants it in host:port. */
    public static function socketHost(string $ip): string
    {
        return str_contains($ip, ':') ? "[{$ip}]" : $ip;
    }

    private static function inPrefix(string $ip, string $prefix, int $bits): bool
    {
        $address = (string) inet_pton($ip);
        $network = (string) inet_pton($prefix);

        if (strlen($address) !== strlen($network)) {
            return false;
        }

        $bytes = intdiv($bits, 8);

        if (substr($address, 0, $bytes) !== substr($network, 0, $bytes)) {
            return false;
        }

        $rest = $bits % 8;

        if ($rest === 0) {
            return true;
        }

        $mask = (0xFF << (8 - $rest)) & 0xFF;

        return (ord($address[$bytes]) & $mask) === (ord($network[$bytes]) & $mask);
    }
}
