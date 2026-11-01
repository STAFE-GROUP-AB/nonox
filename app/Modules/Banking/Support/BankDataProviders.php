<?php

declare(strict_types=1);

namespace App\Modules\Banking\Support;

use App\Modules\Banking\Contracts\BankDataProvider;
use App\Modules\Banking\Providers\EnableBankingProvider;
use App\Modules\Banking\Providers\FakeBankDataProvider;

/**
 * Which provider this installation uses (services.banking.driver). One
 * driver per installation: the contract with the provider is the
 * operator's, not the company's. Without a setting, local and test
 * installations use the fake bank and every other uses Enable Banking. The
 * fake bank is refused in production.
 */
final class BankDataProviders
{
    public static function driver(): string
    {
        $driver = (string) config('services.banking.driver');

        if ($driver === '') {
            return app()->environment('local', 'testing') ? FakeBankDataProvider::KEY : EnableBankingProvider::KEY;
        }

        return $driver;
    }

    public static function isConfigured(): bool
    {
        return match (self::driver()) {
            FakeBankDataProvider::KEY => ! app()->isProduction(),
            EnableBankingProvider::KEY => EnableBankingProvider::isConfigured(),
            default => false,
        };
    }

    /** The provider a connection was made with, or the installation's for a new one. */
    public static function make(?string $key = null): BankDataProvider
    {
        $key ??= self::driver();

        return match ($key) {
            FakeBankDataProvider::KEY => app()->isProduction()
                ? throw new ProviderFailed('The fake bank is not available in production.')
                : app(FakeBankDataProvider::class),
            EnableBankingProvider::KEY => new EnableBankingProvider(
                (string) config('services.banking.enable_banking.application_id'),
                (string) config('services.banking.enable_banking.private_key_path'),
                (string) config('services.banking.enable_banking.url', 'https://api.enablebanking.com'),
            ),
            default => throw new ProviderFailed("Unknown bank data provider {$key}."),
        };
    }
}
