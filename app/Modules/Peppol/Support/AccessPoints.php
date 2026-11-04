<?php

declare(strict_types=1);

namespace App\Modules\Peppol\Support;

use App\Modules\Peppol\Contracts\PeppolAccessPoint;
use App\Modules\Peppol\Domain\AccessPointFailed;
use App\Modules\Peppol\Drivers\LogAccessPoint;
use App\Modules\Peppol\Drivers\QvaliaAccessPoint;

/**
 * Which access point this installation uses (services.peppol.driver). One
 * driver per installation: the agreement with the provider is the
 * operator's. Without a setting, local and test installations use the log
 * driver and every other installation has none, so the module cannot be
 * turned on until the operator has an agreement and keys (ADR-050). The
 * log driver is refused in production.
 */
final class AccessPoints
{
    public static function driver(): string
    {
        $driver = (string) config('services.peppol.driver');

        if ($driver === '') {
            return app()->environment('local', 'testing') ? LogAccessPoint::KEY : '';
        }

        return $driver;
    }

    public static function isConfigured(): bool
    {
        return match (self::driver()) {
            LogAccessPoint::KEY => ! app()->isProduction(),
            QvaliaAccessPoint::KEY => QvaliaAccessPoint::isConfigured(),
            default => false,
        };
    }

    public static function make(?string $key = null): PeppolAccessPoint
    {
        $key ??= self::driver();

        return match ($key) {
            LogAccessPoint::KEY => app()->isProduction()
                ? throw new AccessPointFailed('The log access point is not available in production.')
                : app(LogAccessPoint::class),
            QvaliaAccessPoint::KEY => QvaliaAccessPoint::fromConfig(),
            default => throw new AccessPointFailed("No Peppol access point is configured ({$key})."),
        };
    }
}
