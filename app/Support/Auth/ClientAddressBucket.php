<?php

declare(strict_types=1);

namespace App\Support\Auth;

/**
 * The key a rate limit counts a client address under. An IPv4 address counts
 * on its own; an IPv6 address counts per /64, because one connection usually
 * gets a whole /64 and could otherwise change address for every request.
 * An IPv4-mapped IPv6 address counts as the IPv4 address it carries.
 */
final class ClientAddressBucket
{
    public static function for(?string $address): string
    {
        if ($address === null || $address === '') {
            return 'unknown';
        }

        if (filter_var($address, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) === false) {
            return $address;
        }

        $packed = inet_pton($address);

        if ($packed === false) {
            return $address;
        }

        // An IPv4-mapped address (::ffff:a.b.c.d) is that IPv4 client, counted like one.
        if (str_starts_with($packed, str_repeat("\0", 10)."\xff\xff")) {
            $ipv4 = inet_ntop(substr($packed, 12));

            return $ipv4 === false ? $address : $ipv4;
        }

        $network = inet_ntop(substr($packed, 0, 8).str_repeat("\0", 8));

        return ($network === false ? $address : $network).'/64';
    }
}
