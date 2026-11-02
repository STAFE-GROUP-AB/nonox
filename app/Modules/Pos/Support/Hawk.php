<?php

declare(strict_types=1);

namespace App\Modules\Pos\Support;

/**
 * Hawk HTTP authentication (the "hawk.1.header" scheme, SHA-256 only), as
 * Onslip 360 uses on every API call (ADR-042). Written from the published
 * protocol: the MAC is Base64(HMAC-SHA256(key, normalised string)), and the
 * payload hash is Base64(SHA-256("hawk.1.payload\n{type}\n{body}\n")).
 * Checked against the protocol's own examples in HawkTest.
 */
final class Hawk
{
    /**
     * The Authorization header value for a request.
     *
     * @param  string  $key  the raw key bytes (not Base64)
     */
    public static function header(string $id, string $key, string $method, string $url, ?string $payload = null, ?string $contentType = null, ?int $timestamp = null, ?string $nonce = null, string $ext = ''): string
    {
        $timestamp ??= time();
        $nonce ??= bin2hex(random_bytes(6));
        $parts = self::target($url);
        $hash = $payload === null ? '' : self::payloadHash($payload, $contentType ?? '');
        $mac = self::mac($key, $timestamp, $nonce, $method, $parts['resource'], $parts['host'], $parts['port'], $hash, $ext);

        $attributes = ['id' => $id, 'ts' => (string) $timestamp, 'nonce' => $nonce];

        if ($hash !== '') {
            $attributes['hash'] = $hash;
        }

        if ($ext !== '') {
            $attributes['ext'] = $ext;
        }

        $attributes['mac'] = $mac;

        return 'Hawk '.implode(', ', array_map(fn (string $name, string $value): string => $name.'="'.addcslashes($value, '"\\').'"', array_keys($attributes), $attributes));
    }

    public static function payloadHash(string $payload, string $contentType): string
    {
        // Only the media type counts, lower case, without parameters such as charset.
        $type = strtolower(trim(explode(';', $contentType)[0]));

        return base64_encode(hash('sha256', "hawk.1.payload\n{$type}\n{$payload}\n", true));
    }

    public static function mac(string $key, int $timestamp, string $nonce, string $method, string $resource, string $host, int $port, string $hash = '', string $ext = ''): string
    {
        $normalised = "hawk.1.header\n{$timestamp}\n{$nonce}\n".strtoupper($method)."\n{$resource}\n".strtolower($host)."\n{$port}\n{$hash}\n{$ext}\n";

        return base64_encode(hash_hmac('sha256', $normalised, $key, true));
    }

    /**
     * Checks an incoming Hawk header: the MAC, the payload hash when the
     * header carries one, and the clock (±$skew seconds). Replays within
     * the window are the caller's to refuse (the nonce is returned).
     *
     * @return array{id: string, nonce: string, ts: int}|null null when it does not verify
     */
    public static function verify(string $header, string $key, string $method, string $url, ?string $payload, ?string $contentType, int $now, int $skew = 60): ?array
    {
        $attributes = self::parse($header);

        if ($attributes === null || ! isset($attributes['id'], $attributes['ts'], $attributes['nonce'], $attributes['mac']) || preg_match('/^\d+$/', $attributes['ts']) !== 1) {
            return null;
        }

        $timestamp = (int) $attributes['ts'];
        $parts = self::target($url);
        $hash = $attributes['hash'] ?? '';
        $expected = self::mac($key, $timestamp, $attributes['nonce'], $method, $parts['resource'], $parts['host'], $parts['port'], $hash, $attributes['ext'] ?? '');

        if (! hash_equals($expected, $attributes['mac'])) {
            return null;
        }

        if ($payload !== null && $payload !== '' && ($hash === '' || ! hash_equals(self::payloadHash($payload, $contentType ?? ''), $hash))) {
            return null;
        }

        if (abs($now - $timestamp) > $skew) {
            return null;
        }

        return ['id' => $attributes['id'], 'nonce' => $attributes['nonce'], 'ts' => $timestamp];
    }

    /** @return array<string, string>|null */
    public static function parse(string $header): ?array
    {
        if (! str_starts_with($header, 'Hawk ')) {
            return null;
        }

        preg_match_all('/(\w+)="((?:[^"\\\\]|\\\\.)*)"/', substr($header, 5), $matches, PREG_SET_ORDER);
        $attributes = [];

        foreach ($matches as $match) {
            $attributes[$match[1]] = stripcslashes($match[2]);
        }

        return $attributes === [] ? null : $attributes;
    }

    /** @return array{resource: string, host: string, port: int} */
    private static function target(string $url): array
    {
        $parts = parse_url($url);
        $scheme = strtolower((string) ($parts['scheme'] ?? 'https'));
        $resource = ($parts['path'] ?? '/').(isset($parts['query']) ? '?'.$parts['query'] : '');

        return [
            'resource' => $resource === '' ? '/' : $resource,
            'host' => (string) ($parts['host'] ?? ''),
            'port' => (int) ($parts['port'] ?? ($scheme === 'http' ? 80 : 443)),
        ];
    }
}
