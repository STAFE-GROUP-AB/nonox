<?php

declare(strict_types=1);

namespace App\Support\CompanyRegistry;

/**
 * Where this installation looks companies up. Two sources, in this order
 * (ADR-035 addendum): NONOX's own register (LocalRegistry, whenever the
 * company_registry table has rows), then the API driver set in
 * services.company_registry.driver, which is also the only source for a
 * sole trader's personal number. Without a setting, local and test
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

    /** Whether the lookup can answer at all: the own register has rows, or an API driver is configured. */
    public static function isConfigured(): bool
    {
        return LocalRegistry::hasRows() || self::apiConfigured();
    }

    public static function apiConfigured(): bool
    {
        return match (self::driver()) {
            FakeCompanyRegistry::KEY => ! app()->isProduction(),
            BolagsverketRegistry::KEY => BolagsverketRegistry::isConfigured(),
            default => false,
        };
    }

    /** NONOX's own register, or null while it is empty. */
    public static function local(): ?CompanyRegistry
    {
        return LocalRegistry::hasRows() ? app(LocalRegistry::class) : null;
    }

    /** The API driver (Bolagsverket, or the invented register locally), or null when none is configured. */
    public static function make(): ?CompanyRegistry
    {
        if (! self::apiConfigured()) {
            return null;
        }

        return match (self::driver()) {
            FakeCompanyRegistry::KEY => app(FakeCompanyRegistry::class),
            BolagsverketRegistry::KEY => BolagsverketRegistry::fromConfig(),
            default => null,
        };
    }
}
