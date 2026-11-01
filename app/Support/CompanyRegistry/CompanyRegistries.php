<?php

declare(strict_types=1);

namespace App\Support\CompanyRegistry;

/**
 * Which register this installation looks companies up in
 * (services.company_registry.driver). Without a setting, local and test
 * installations use the invented register and every other installation
 * has no lookup at all, so the button is hidden until an operator has
 * Bolagsverket credentials. The invented register is refused in
 * production.
 */
final class CompanyRegistries
{
    public static function driver(): string
    {
        $driver = (string) config('services.company_registry.driver');

        if ($driver === '') {
            return app()->environment('local', 'testing') ? FakeCompanyRegistry::KEY : '';
        }

        return $driver;
    }

    public static function isConfigured(): bool
    {
        return match (self::driver()) {
            FakeCompanyRegistry::KEY => ! app()->isProduction(),
            BolagsverketRegistry::KEY => BolagsverketRegistry::isConfigured(),
            default => false,
        };
    }

    /** The installation's register, or null when lookup is switched off. */
    public static function make(): ?CompanyRegistry
    {
        if (! self::isConfigured()) {
            return null;
        }

        return match (self::driver()) {
            FakeCompanyRegistry::KEY => app(FakeCompanyRegistry::class),
            BolagsverketRegistry::KEY => BolagsverketRegistry::fromConfig(),
            default => null,
        };
    }
}
