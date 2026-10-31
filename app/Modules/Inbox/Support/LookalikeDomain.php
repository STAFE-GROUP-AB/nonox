<?php

declare(strict_types=1);

namespace App\Modules\Inbox\Support;

/**
 * Whether a sender's domain looks like a known one without being it: a
 * typo distance of at most two, the same name under another top-level
 * domain, or the same letters written with look-alike characters
 * (homoglyphs, punycode). Used to warn about invoice fraud.
 */
final class LookalikeDomain
{
    /** Look-alike characters and sequences, mapped to what they imitate. */
    private const array HOMOGLYPHS = [
        'rn' => 'm', 'vv' => 'w', 'cl' => 'd', '0' => 'o', '1' => 'l', 'i' => 'l', '3' => 'e', '5' => 's', '@' => 'a',
        'а' => 'a', 'е' => 'e', 'о' => 'o', 'р' => 'p', 'с' => 'c', 'у' => 'y', 'х' => 'x', 'і' => 'l', 'ӏ' => 'l', 'ԁ' => 'd', 'ո' => 'n', 'ѕ' => 's',
    ];

    /**
     * The known domain the candidate imitates, or null.
     *
     * @param  list<string>  $known
     */
    public static function imitates(string $candidate, array $known): ?string
    {
        $candidate = self::normalise($candidate);

        if ($candidate === '' || in_array($candidate, array_map(self::normalise(...), $known), true)) {
            return null;
        }

        foreach ($known as $domain) {
            $domain = self::normalise($domain);

            if ($domain === '' || $domain === $candidate) {
                continue;
            }

            if (str_starts_with($candidate, $domain.'.') || (self::name($candidate) === self::name($domain) && self::name($domain) !== '')) {
                return $domain;
            }

            if (self::skeleton($candidate) === self::skeleton($domain)) {
                return $domain;
            }

            if (strlen($domain) >= 5 && levenshtein($candidate, $domain) <= 2) {
                return $domain;
            }
        }

        return null;
    }

    private static function normalise(string $domain): string
    {
        $domain = mb_strtolower(trim($domain, " .@\t"));

        if (str_contains($domain, 'xn--') && function_exists('idn_to_utf8')) {
            $decoded = idn_to_utf8($domain, IDNA_DEFAULT, INTL_IDNA_VARIANT_UTS46);
            $domain = is_string($decoded) ? $decoded : $domain;
        }

        return $domain;
    }

    /** The domain without its top-level domain: "leverantoren" for "leverantoren.se". */
    private static function name(string $domain): string
    {
        $dot = strrpos($domain, '.');

        return $dot === false ? $domain : substr($domain, 0, $dot);
    }

    private static function skeleton(string $domain): string
    {
        return strtr($domain, self::HOMOGLYPHS);
    }
}
