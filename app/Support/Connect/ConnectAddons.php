<?php

declare(strict_types=1);

namespace App\Support\Connect;

use App\Models\Company;
use App\Models\ConnectAddon;
use App\Models\User;
use App\Support\Audit\Audit;
use App\Support\Tenancy\CompanyScope;
use InvalidArgumentException;

/**
 * NONOX Connect (ADR-064 §2–§3): the connections a provider bills for, each
 * switched on per company. A connection is used only while it is on, and it
 * is never billed before someone with settings:manage has turned it on and
 * seen the price, shown as two lines: the provider's price and the 10 %
 * markup on it.
 *
 * Only Stripe has a switch so far (ADR-080 addendum, step 2). Stripe charges
 * NONOX nothing for API access, so its price is 0 kr + 10 % = 0 kr (§7.8,
 * decision O1). Peppol, Skatteverket and open banking keep their own gates
 * until the add-on as a whole is built (ADR-064 "Consequences").
 */
final class ConnectAddons
{
    public const string STRIPE = 'stripe';

    /** @var array<string, array{provider_ore: int}> connection => the provider's monthly price to NONOX */
    public const array CONNECTIONS = [
        self::STRIPE => ['provider_ore' => 0],
    ];

    public const int MARKUP_PERCENT = 10;

    public static function isOn(Company|int $company, string $connection): bool
    {
        $id = $company instanceof Company ? $company->id : $company;

        // Also read before a company context exists: the webhook resolves the company from its token first.
        return (bool) CompanyScope::bypass(fn (): bool => ConnectAddon::query()
            ->where('company_id', $id)
            ->where('connection', $connection)
            ->where('enabled', true)
            ->exists());
    }

    /** @return array{provider_ore: int, markup_ore: int, total_ore: int} */
    public static function price(string $connection): array
    {
        $provider = self::CONNECTIONS[$connection]['provider_ore'] ?? throw new InvalidArgumentException("Unknown connection {$connection}.");
        $markup = intdiv($provider * self::MARKUP_PERCENT + 50, 100);

        return ['provider_ore' => $provider, 'markup_ore' => $markup, 'total_ore' => $provider + $markup];
    }

    public static function set(Company $company, User $by, string $connection, bool $enabled): ConnectAddon
    {
        if (! isset(self::CONNECTIONS[$connection])) {
            throw new InvalidArgumentException("Unknown connection {$connection}.");
        }

        /** @var ConnectAddon $addon */
        $addon = CompanyScope::bypass(fn (): ConnectAddon => ConnectAddon::query()->firstOrNew(['company_id' => $company->id, 'connection' => $connection]));
        $addon->forceFill(['enabled' => $enabled, 'changed_by' => $by->id, 'changed_at' => now()])->save();

        Audit::record($enabled ? 'connect.addon_enabled' : 'connect.addon_disabled', $addon, ['connection' => $connection], $company, $by);

        return $addon;
    }
}
