<?php

declare(strict_types=1);

namespace App\Modules\Inventory;

use App\Domain\Company\ReportingFramework;
use App\Models\Company;
use App\Modules\Inventory\Domain\StockClass;
use App\Modules\Inventory\Domain\ValuationMethod;
use App\Modules\ModuleManager;

/**
 * Lager's settings per company (ADR-039), kept in company_modules.settings.
 *
 * The stock change is booked against an account in the 4900 group. The
 * default is 4960 (Förändring av lager av handelsvaror) for goods for resale
 * on 1460, because NONOX puts 4960–4969 on the K2 line Handelsvaror and
 * INK2R 3.6, while 4980–4999 (including 4990) land on Råvaror och
 * förnödenheter (K2Schedule, Ink2Codes). Since ADR-073 every article has a
 * stock class: raw materials are booked on their own pair (1410/4910 by
 * default), goods for resale on the original pair; an article without a
 * class takes the company's default class. BAS 2026 names the accounts
 * 1410 Lager av råvaror, 4910 Förändring av lager av råvaror, 1460 Lager
 * av handelsvaror and 4960 Förändring av lager av handelsvaror (rule
 * sheet RS-R1).
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
        // The class of an article without its own (ADR-073 §9); the pair above is the GOODS_FOR_RESALE pair.
        'default_stock_class' => 'GOODS_FOR_RESALE',
        'raw_material_stock_account' => 1410,
        'raw_material_change_account' => 4910,
        // K2 12.5: the stock at no less than 97 % of its total cost. Offered to K2 companies only, off by default (Lead O3).
        'rule_97' => false,
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

    public static function defaultClass(Company $company): StockClass
    {
        return StockClass::tryFrom((string) self::for($company)['default_stock_class']) ?? StockClass::GoodsForResale;
    }

    /**
     * The account pair per stock class: [stock account, change account].
     * Handelsvaror keeps the company's original pair, so a company that
     * never chose a class books exactly as before.
     *
     * @return array<string, array{0: int, 1: int}>
     */
    public static function pairs(Company $company): array
    {
        $settings = self::for($company);

        return [
            StockClass::GoodsForResale->value => [(int) $settings['stock_account'], (int) $settings['change_account']],
            StockClass::RawMaterial->value => [(int) $settings['raw_material_stock_account'], (int) $settings['raw_material_change_account']],
        ];
    }

    /** Whether the 97 % rule applies: chosen, and the company reports under K2. */
    public static function usesRule97(Company $company): bool
    {
        return $company->reporting_framework === ReportingFramework::K2 && (bool) self::for($company)['rule_97'];
    }

    public static function allowsNegative(Company $company): bool
    {
        return (bool) self::for($company)['allow_negative_stock'];
    }
}
