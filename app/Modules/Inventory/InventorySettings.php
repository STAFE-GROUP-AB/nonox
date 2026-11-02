<?php

declare(strict_types=1);

namespace App\Modules\Inventory;

use App\Models\Company;
use App\Modules\Inventory\Domain\ValuationMethod;
use App\Modules\ModuleManager;

/**
 * Lager's settings per company (ADR-039), kept in company_modules.settings.
 *
 * The stock change is booked against an account in the 4900 group. The
 * default is 4960 (Förändring av lager av handelsvaror) for goods for resale
 * on 1460, because NONOX puts 4960–4969 on the K2 line Handelsvaror and
 * INK2R 3.6, while 4980–4999 (including 4990) land on Råvaror och
 * förnödenheter (K2Schedule, Ink2Codes). A company with raw materials picks
 * 1410 and 4910. TODO(legal-verify): the account pairs against the current
 * BAS chart.
 */
final class InventorySettings
{
    /** @var array<string, mixed> */
    public const array DEFAULTS = [
        'valuation_method' => 'FIFO',
        'allow_negative_stock' => false,
        // Lager av handelsvaror; 1410 Lager av råvaror for producers.
        'stock_account' => 1460,
        'change_account' => 4960,
    ];

    /** Stock accounts offered: BAS 1400–1499. */
    public const array STOCK_ACCOUNTS = [1400, 1499];

    /** Change accounts offered: BAS 4900–4999. */
    public const array CHANGE_ACCOUNTS = [4900, 4999];

    /** BAS names for the accounts the closing step adds when the chart lacks them. */
    public const array ACCOUNT_NAMES = [
        1410 => 'Lager av råvaror',
        1460 => 'Lager av handelsvaror',
        4910 => 'Förändring av lager av råvaror',
        4960 => 'Förändring av lager av handelsvaror',
    ];

    /**
     * @param  array<string, mixed>  $stored
     * @return array<string, mixed>
     */
    public static function resolve(array $stored): array
    {
        return [...self::DEFAULTS, ...array_intersect_key($stored, self::DEFAULTS)];
    }

    /** @return array<string, mixed> */
    public static function for(Company $company): array
    {
        return self::resolve(app(ModuleManager::class)->settings(InventoryModule::KEY, $company));
    }

    public static function method(Company $company): ValuationMethod
    {
        return ValuationMethod::tryFrom((string) self::for($company)['valuation_method']) ?? ValuationMethod::Fifo;
    }

    public static function allowsNegative(Company $company): bool
    {
        return (bool) self::for($company)['allow_negative_stock'];
    }
}
