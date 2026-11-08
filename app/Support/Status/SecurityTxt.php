<?php

declare(strict_types=1);

namespace App\Support\Status;

use App\Support\Instance\InstanceSettings;
use Carbon\CarbonImmutable;

/**
 * The /.well-known/security.txt of this installation (RFC 9116, ADR-078).
 *
 * - Contact (required): the instance setting security.contact set in /hq,
 *   else NONOX_SECURITY_EMAIL, else NONOX_PRIVACY_EMAIL. An e-mail address
 *   becomes a mailto: URI; a web address must be https (RFC 9116 2.5.3).
 *   Without a contact there is no file: a security.txt without Contact is
 *   not valid, so the route answers 404 instead.
 * - Expires (required, once): RFC 3339, less than a year ahead as the RFC
 *   recommends. It is computed on every request as EXPIRES_DAYS from now,
 *   so the file never goes stale while the installation runs and the
 *   operator cannot forget to renew it. That is a deliberate trade-off:
 *   the field then says "this was checked within the last request", not
 *   "a person reviewed this on date X".
 * - Policy, Canonical, Preferred-Languages: the policy page, this file's
 *   own address, and sv, en.
 *
 * Not included: Encryption and a cleartext signature (section 2.3 only
 * recommends them), Hiring, Acknowledgments.
 */
final class SecurityTxt
{
    public const int EXPIRES_DAYS = 180;

    public static function contact(): ?string
    {
        $raw = InstanceSettings::current()->securityContact()
            ?? self::config('security_email')
            ?? self::config('privacy_email');

        return $raw === null ? null : self::contactUri($raw);
    }

    /** An e-mail address as mailto:, an https address as it is, anything else null. */
    public static function contactUri(string $value): ?string
    {
        $value = trim($value);

        if (filter_var($value, FILTER_VALIDATE_EMAIL) !== false) {
            return 'mailto:'.$value;
        }

        if (str_starts_with($value, 'mailto:') && filter_var(substr($value, 7), FILTER_VALIDATE_EMAIL) !== false) {
            return $value;
        }

        if (str_starts_with($value, 'https://') && filter_var($value, FILTER_VALIDATE_URL) !== false) {
            return $value;
        }

        return null;
    }

    public static function expires(?CarbonImmutable $now = null): string
    {
        return ($now ?? CarbonImmutable::now())->utc()->addDays(self::EXPIRES_DAYS)->startOfDay()->format('Y-m-d\TH:i:s\Z');
    }

    public static function render(?CarbonImmutable $now = null): ?string
    {
        $contact = self::contact();

        if ($contact === null) {
            return null;
        }

        $lines = [
            '# Säkerhetskontakt för '.parse_url(url('/'), PHP_URL_HOST).' (RFC 9116).',
            "Contact: {$contact}",
            'Expires: '.self::expires($now),
            'Preferred-Languages: sv, en',
            'Policy: '.secure_url(route('security.policy', [], false)),
            'Canonical: '.secure_url('/.well-known/security.txt'),
        ];

        return implode("\n", $lines)."\n";
    }

    private static function config(string $key): ?string
    {
        $value = config("app.landing.{$key}");

        return is_string($value) && trim($value) !== '' ? trim($value) : null;
    }
}
