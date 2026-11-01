<?php

declare(strict_types=1);

namespace App\Modules\Connect\Webhooks;

use Symfony\Component\HttpFoundation\IpUtils;

/**
 * Where a webhook may be sent (ADR-032), the defence against server-side
 * request forgery: a webhook must never become a way to reach the NONOX
 * server itself or the network around it.
 *
 * - https only, no user name or password in the address, no fragment;
 *   port 443 or 1024–65535;
 * - the host is resolved here, and every address it resolves to must be a
 *   public unicast address: loopback, private, link-local, carrier-grade
 *   NAT, multicast, documentation, benchmarking and reserved ranges are
 *   refused, in IPv4 and IPv6 (including IPv4-mapped, 6to4, Teredo and
 *   NAT64, which can carry a private IPv4 address);
 * - the request then goes to exactly the address checked (WebhookTarget),
 *   so a second DNS answer cannot point it elsewhere (DNS rebinding), and
 *   redirects are never followed.
 *
 * The check runs when the webhook is saved and again before every attempt.
 */
final class WebhookAddress
{
    /** Ranges on top of Symfony's IpUtils::PRIVATE_SUBNETS. */
    private const array ALSO_REFUSED = [
        '192.0.0.0/24',     // IETF protocol assignments (RFC 6890)
        '192.88.99.0/24',   // 6to4 relay anycast (RFC 7526)
        '224.0.0.0/4',      // Multicast
        '255.255.255.255/32',
        '100::/64',         // Discard-only (RFC 6666)
        '2001::/23',        // IETF protocol assignments (RFC 2928)
        'fec0::/10',        // Site-local (deprecated)
        'ff00::/8',         // Multicast
    ];

    public function __construct(private readonly HostResolver $resolver) {}

    /**
     * @throws UnsafeWebhookAddress
     */
    public function target(string $url): WebhookTarget
    {
        $parts = parse_url($url);

        if (! is_array($parts) || strtolower($parts['scheme'] ?? '') !== 'https' || ! isset($parts['host'])) {
            throw new UnsafeWebhookAddress(__('Adressen måste börja med https://.'), 'not_https');
        }

        if (isset($parts['user']) || isset($parts['pass'])) {
            throw new UnsafeWebhookAddress(__('Adressen får inte innehålla användarnamn eller lösenord.'), 'credentials_in_url');
        }

        if (isset($parts['fragment'])) {
            throw new UnsafeWebhookAddress(__('Adressen får inte innehålla #.'), 'fragment');
        }

        $port = (int) ($parts['port'] ?? 443);

        if ($port !== 443 && $port < 1024) {
            throw new UnsafeWebhookAddress(__('Använd port 443, eller en port mellan 1024 och 65535.'), 'port');
        }

        $host = strtolower(trim($parts['host'], '[]'));

        if ($host === 'localhost' || str_ends_with($host, '.localhost') || str_ends_with($host, '.local') || str_ends_with($host, '.internal')) {
            throw new UnsafeWebhookAddress(__('Adressen måste gå till en server på internet, inte till ett internt nät.'), 'private_address');
        }

        $addresses = $this->resolver->resolve($host);

        if ($addresses === []) {
            throw new UnsafeWebhookAddress(__('Namnet :host går inte att slå upp.', ['host' => $host]), 'dns_failed');
        }

        foreach ($addresses as $address) {
            if (! self::isPublic($address)) {
                throw new UnsafeWebhookAddress(__('Adressen måste gå till en server på internet, inte till ett internt nät.'), 'private_address');
            }
        }

        return new WebhookTarget($host, $port, $addresses[0]);
    }

    /** A public unicast address that a webhook may be sent to. */
    public static function isPublic(string $address): bool
    {
        if (filter_var($address, FILTER_VALIDATE_IP) === false) {
            return false;
        }

        return ! IpUtils::checkIp($address, [...IpUtils::PRIVATE_SUBNETS, ...self::ALSO_REFUSED]);
    }
}
