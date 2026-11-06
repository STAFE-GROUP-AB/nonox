<?php

declare(strict_types=1);

namespace App\Support\Integrations;

/**
 * The operator's kill switch for an outside provider (bank data, Peppol
 * access point): a config value that turns the provider off at once
 * without removing its keys. `true` (or "1", "all", "yes", "on") pauses
 * every provider of the area; a comma list pauses the providers named
 * ("enable_banking", "qvalia"); empty or false pauses nothing.
 */
final class ProviderPause
{
    public static function covers(mixed $setting, string $provider): bool
    {
        if (is_bool($setting)) {
            return $setting;
        }

        if (! is_scalar($setting)) {
            return false;
        }

        $value = mb_strtolower(trim((string) $setting));

        if (in_array($value, ['', '0', 'false', 'no', 'off', 'null', '(null)'], true)) {
            return false;
        }

        if (in_array($value, ['1', 'true', 'all', 'yes', 'on'], true)) {
            return true;
        }

        $names = array_map('trim', explode(',', $value));

        return in_array(mb_strtolower($provider), $names, true);
    }
}
