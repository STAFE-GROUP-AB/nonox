<?php

declare(strict_types=1);

namespace App\Support\Status;

use App\Models\InstanceSetting;
use App\Support\Build\BuildInfo;
use App\Support\Instance\InstanceSettings;
use Carbon\CarbonImmutable;
use Throwable;

/**
 * The /.well-known/security.txt of this installation (RFC 9116, ADR-078).
 *
 * - Contact (required): the instance setting security.contact set in /hq,
 *   else NONOX_SECURITY_EMAIL, else NONOX_PRIVACY_EMAIL. An e-mail address
 *   becomes a mailto: URI; a web address must be https (RFC 9116 2.5.3).
 *   Without a contact there is no file: a security.txt without Contact is
 *   not valid, so the route answers 404 instead.
 * - Expires (required, once): RFC 3339. It is the day the contact was last
 *   confirmed in /hq (the setting's updated_at) plus 364 days, or, when the
 *   contact comes from the environment, the day the build was made plus 364
 *   days. With an environment contact every deploy renews it. It is not
 *   renewed by being read: a stale file says so, and /hq warns before that
 *   happens.
 * - Policy: only when the policy page is published (SecurityPolicy).
 * - Canonical, Policy: built from APP_URL, never from the request's Host
 *   header, so a forged Host cannot change what the file claims.
 *
 * Not included: Encryption and a cleartext signature (section 2.3 only
 * recommends them), Hiring, Acknowledgments.
 */
final class SecurityTxt
{
    public const int VALID_DAYS = 364;

    public const int WARN_DAYS = 30;

    /** The installation's own address, always https, from APP_URL. */
    public static function baseUrl(): string
    {
        $url = rtrim((string) config('app.url'), '/');

        return (string) preg_replace('#^http://#i', 'https://', $url);
    }

    public static function host(): string
    {
        return (string) parse_url(self::baseUrl(), PHP_URL_HOST);
    }

    /** The contact as written in the setting or the environment, before it is made a URI. */
    public static function rawContact(): ?string
    {
        return InstanceSettings::current()->securityContact()
            ?? self::config('security_email')
            ?? self::config('privacy_email');
    }

    public static function contact(): ?string
    {
        $raw = self::rawContact();

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

    /**
     * When the contact was last confirmed: the setting's update, else the build.
     * With a contact from the environment every deploy therefore renews Expires; that is accepted (ADR-078).
     */
    public static function confirmedAt(): CarbonImmutable
    {
        $setting = InstanceSetting::query()->find(InstanceSettings::SECURITY_CONTACT);

        if ($setting instanceof InstanceSetting && $setting->value !== null) {
            return $setting->updated_at;
        }

        try {
            $built = BuildInfo::builtAt();

            if (is_string($built) && $built !== '') {
                return CarbonImmutable::parse($built);
            }
        } catch (Throwable) {
            // No build date: the file then expires a year after the current moment.
        }

        return CarbonImmutable::now();
    }

    public static function expiresAt(): CarbonImmutable
    {
        return self::confirmedAt()->utc()->addDays(self::VALID_DAYS);
    }

    public static function expires(): string
    {
        return self::expiresAt()->format('Y-m-d\TH:i:s\Z');
    }

    /** Whether the file has expired or will within WARN_DAYS, so /hq can ask for a new confirmation. */
    public static function expiresSoon(?CarbonImmutable $now = null): bool
    {
        return self::expiresAt()->lessThan(($now ?? CarbonImmutable::now())->addDays(self::WARN_DAYS));
    }

    public static function render(): ?string
    {
        $contact = self::contact();

        if ($contact === null) {
            return null;
        }

        $lines = [
            '# Säkerhetskontakt för '.self::host().' (RFC 9116).',
            "Contact: {$contact}",
            'Expires: '.self::expires(),
            'Preferred-Languages: sv, en',
        ];

        if (SecurityPolicy::published()) {
            $lines[] = 'Policy: '.self::baseUrl().route('security.policy', [], false);
        }

        $lines[] = 'Canonical: '.self::baseUrl().'/.well-known/security.txt';

        return implode("\n", $lines)."\n";
    }

    private static function config(string $key): ?string
    {
        $value = config("app.landing.{$key}");

        return is_string($value) && trim($value) !== '' ? trim($value) : null;
    }
}
