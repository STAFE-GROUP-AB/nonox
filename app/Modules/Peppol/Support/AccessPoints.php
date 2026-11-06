<?php

declare(strict_types=1);

namespace App\Modules\Peppol\Support;

use App\Modules\Peppol\Contracts\PeppolAccessPoint;
use App\Modules\Peppol\Domain\AccessPointFailed;
use App\Modules\Peppol\Drivers\LogAccessPoint;
use App\Modules\Peppol\Drivers\QvaliaAccessPoint;
use App\Support\Integrations\ProviderPause;

/**
 * Which access point this installation uses (services.peppol.driver). One
 * driver per installation: the agreement with the provider is the
 * operator's. Without a setting, local and test installations use the log
 * driver and every other installation has none, so the module cannot be
 * turned on until the operator has an agreement and keys (ADR-050). The
 * log driver is refused in production.
 *
 * The operator's kill switch (services.peppol.paused, NONOX_PEPPOL_PAUSED)
 * turns the access point off at once without removing its keys: nothing is
 * sent to it, queued sendings wait, documents for the companies stay at the
 * access point (the poll window is not moved), and the pages say so.
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

    /** Whether the operator has paused the access point (the installation's when none is named). */
    public static function isPaused(?string $key = null): bool
    {
        return ProviderPause::covers(config('services.peppol.paused'), $key ?? self::driver());
    }

    /** What a user is told while the access point is paused. */
    public static function pausedMessage(): string
    {
        return __('Peppol är tillfälligt avstängt av den som driftar NONOX. Inga e-fakturor skickas eller hämtas just nu. Fakturor som väntar skickas när Peppol är på igen, och e-fakturor till företaget ligger kvar hos Peppol-operatören tills dess.');
    }

    /** $evenWhenPaused only for verifying a webhook's signature, which sends nothing to the access point. */
    public static function make(?string $key = null, bool $evenWhenPaused = false): PeppolAccessPoint
    {
        $key ??= self::driver();

        if (! $evenWhenPaused && self::isPaused($key)) {
            // The last guard, and retryable so nothing is marked as refused: callers check isPaused() first.
            throw new AccessPointFailed('The access point is paused by the operator.', retryable: true);
        }

        return match ($key) {
            LogAccessPoint::KEY => app()->isProduction()
                ? throw new AccessPointFailed('The log access point is not available in production.')
                : app(LogAccessPoint::class),
            QvaliaAccessPoint::KEY => QvaliaAccessPoint::fromConfig(),
            default => throw new AccessPointFailed("No Peppol access point is configured ({$key})."),
        };
    }
}
