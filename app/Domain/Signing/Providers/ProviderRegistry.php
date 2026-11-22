<?php

declare(strict_types=1);

namespace App\Domain\Signing\Providers;

use App\Domain\Signing\Pdf\PdfEngine;
use App\Domain\Signing\Providers\Csc\CscProvider;
use App\Models\SigningProvider;
use App\Support\Instance\InstanceSettings;
use InvalidArgumentException;

/**
 * The signing providers this installation knows (ADR-046 amendment, gap
 * 12, package S17), by key, from config (nonox.signing.providers.drivers),
 * like the bank data providers (ADR-034). The fake driver is offered only
 * where nonox.signing.providers.allow_fake is true (the test suite).
 *
 * External signing is off until an administrator switches it on in /hq
 * (the instance setting signing.providers_enabled, off by default
 * everywhere). The hosted service keeps it off until the operator company
 * has an agreement with a provider and the legal items of gap 12 are
 * answered, as with sealing (owner decision 2026-11-18). It also needs the
 * PDF engine, which makes the signer's PAdES signature. A company can add
 * its configuration in advance; a signer can be sent to a provider only
 * while all three hold.
 */
final class ProviderRegistry
{
    /** @return array<string, class-string<SignatureProvider>> */
    public static function drivers(): array
    {
        /** @var array<string, class-string<SignatureProvider>> $drivers */
        $drivers = (array) config('nonox.signing.providers.drivers', [CscProvider::KEY => CscProvider::class]);

        if (! (bool) config('nonox.signing.providers.allow_fake', false)) {
            unset($drivers[FakeSignatureProvider::KEY]);
        }

        return $drivers;
    }

    /** @return list<string> the keys a company may configure */
    public static function keys(): array
    {
        return array_keys(self::drivers());
    }

    public static function driver(string $key): SignatureProvider
    {
        $class = self::drivers()[$key] ?? null;

        if ($class === null) {
            throw new InvalidArgumentException("Unknown signing provider driver [{$key}].");
        }

        return app($class);
    }

    public static function for(SigningProvider $config): SignatureProvider
    {
        return self::driver($config->driver);
    }

    public static function switchedOn(): bool
    {
        return InstanceSettings::current()->signingProvidersEnabled();
    }

    /** Whether a signer can be sent to a provider at all on this installation: switched on and the engine installed. */
    public static function usable(): bool
    {
        return self::switchedOn() && app(PdfEngine::class)->isInstalled();
    }

    /**
     * The company's active configurations a sender may choose, or none while external signing cannot be used.
     * Must run inside the company.
     *
     * @return list<SigningProvider>
     */
    public static function choosable(): array
    {
        if (! self::usable()) {
            return [];
        }

        return array_values(SigningProvider::query()->active()->whereIn('driver', self::keys())->orderBy('name')->get(['id', 'company_id', 'driver', 'name', 'claimed_level'])->all());
    }
}
