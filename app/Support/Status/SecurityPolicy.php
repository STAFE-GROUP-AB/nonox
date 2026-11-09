<?php

declare(strict_types=1);

namespace App\Support\Status;

/**
 * Whether the public security policy page exists (ADR-078), and which
 * version of its text is shown.
 *
 * It is off until NONOX_SECURITY_POLICY_PUBLISHED is turned on. The hosted
 * service turns it on only after a lawyer has read the safe-harbour
 * section (TODO(legal-verify), docs/juridik/fragor-till-jurist.md). A
 * self-hosted installation gets the neutral text, without any waiver, and
 * only when it has set a company name to stand behind the policy.
 *
 * In the Markdown, `hosted:start`/`hosted:end` comments mark text only the
 * hosted service shows, and `selfhost:start`/`selfhost:end` text only a
 * self-hosted installation shows.
 */
final class SecurityPolicy
{
    public static function hosted(): bool
    {
        return (bool) config('app.landing.hosted');
    }

    public static function published(): bool
    {
        if (config('app.landing.security_policy_published') !== true) {
            return false;
        }

        return self::hosted() || trim((string) config('app.landing.company_name')) !== '';
    }

    public static function filter(string $markdown): string
    {
        $hosted = self::hosted();

        $markdown = (string) preg_replace_callback('/<!-- hosted:start -->(.*?)<!-- hosted:end -->\n?/s', fn (array $match): string => $hosted ? $match[1] : '', $markdown);

        return (string) preg_replace_callback('/<!-- selfhost:start -->(.*?)<!-- selfhost:end -->\n?/s', fn (array $match): string => $hosted ? '' : $match[1], $markdown);
    }
}
