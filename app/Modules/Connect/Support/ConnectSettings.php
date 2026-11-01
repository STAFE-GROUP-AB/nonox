<?php

declare(strict_types=1);

namespace App\Modules\Connect\Support;

use App\Models\Company;
use App\Modules\Connect\ConnectModule;
use App\Modules\ModuleManager;
use Symfony\Component\HttpFoundation\IpUtils;

/**
 * The company's Connect settings with their defaults (ADR-032).
 */
final class ConnectSettings
{
    /** @return array{app_policy: string, allowed_clients: list<string>, ip_allowlist: list<string>} */
    public static function for(Company $company): array
    {
        $settings = [...ConnectModule::DEFAULTS, ...app(ModuleManager::class)->settings(ConnectModule::KEY, $company)];

        return [
            'app_policy' => $settings['app_policy'] === 'listed' ? 'listed' : 'all',
            'allowed_clients' => array_values(array_map('strval', (array) $settings['allowed_clients'])),
            'ip_allowlist' => array_values(array_map('strval', (array) $settings['ip_allowlist'])),
        ];
    }

    public static function allowsClient(Company $company, string $clientId): bool
    {
        $settings = self::for($company);

        return $settings['app_policy'] === 'all' || in_array($clientId, $settings['allowed_clients'], true);
    }

    /**
     * Whether an address passes an allowlist; an empty list allows all.
     *
     * @param  list<string>  $allowlist
     */
    public static function ipAllowed(?string $ip, array $allowlist): bool
    {
        if ($allowlist === []) {
            return true;
        }

        return $ip !== null && IpUtils::checkIp($ip, $allowlist);
    }

    /**
     * A normalised allowlist from text with one address or CIDR range per
     * line, or null when an entry is not valid.
     *
     * @return ?list<string>
     */
    public static function parseAllowlist(string $text): ?array
    {
        $entries = array_values(array_unique(array_filter(array_map('trim', preg_split('/[\s,]+/', $text) ?: []), fn (string $entry): bool => $entry !== '')));

        foreach ($entries as $entry) {
            $parts = explode('/', $entry, 2);
            $address = $parts[0];
            $bits = $parts[1] ?? null;

            if (filter_var($address, FILTER_VALIDATE_IP) === false) {
                return null;
            }

            $max = str_contains($address, ':') ? 128 : 32;

            if ($bits !== null && (! ctype_digit($bits) || (int) $bits > $max)) {
                return null;
            }
        }

        return $entries;
    }
}
