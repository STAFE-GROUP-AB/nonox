<?php

declare(strict_types=1);

namespace App\Modules\Manufacturing;

use App\Models\Company;
use App\Modules\ModuleManager;

/**
 * Tillverkning's settings per company (ADR-071 §6), kept in
 * company_modules.settings:
 *
 * - overhead_bp: the overhead (omkostnadspålägg) added to material and
 *   labour in the costing, in basis points; null = off, the default.
 * - overhead_in_stock_value: whether that overhead is also part of the
 *   manufacturing cost of finished goods and of the work in progress
 *   (§8); off by default, so the stock value is material and direct labour.
 * - wip_stock_account, wip_change_account: the account pair the year-end
 *   valuation books the work in progress on (BAS 1440 Produkter i arbete,
 *   4940 Förändring av produkter i arbete by default).
 *
 * TODO(legal-verify): whether K2 (BFNAR 2016:10 ch. 12) and K3 (ch. 13)
 * allow or require indirect costs (overhead) in the cost of own-made goods;
 * the BAS accounts for finished goods and work in progress (1450/1440) and
 * their change accounts (4950 or 4970 / 4940) beside 1460/4960.
 */
final class ManufacturingSettings
{
    /** @var array<string, mixed> */
    public const array DEFAULTS = [
        'overhead_bp' => null,
        'overhead_in_stock_value' => false,
        'wip_stock_account' => 1440,
        'wip_change_account' => 4940,
    ];

    /** BAS names for the work in progress accounts the closing step adds when the chart lacks them. */
    public const array ACCOUNT_NAMES = [
        1440 => 'Produkter i arbete',
        4940 => 'Förändring av produkter i arbete',
    ];

    /** @return array<string, mixed> */
    public static function for(Company $company): array
    {
        $stored = app(ModuleManager::class)->settings(ManufacturingModule::KEY, $company);

        return [...self::DEFAULTS, ...array_intersect_key($stored, self::DEFAULTS)];
    }

    public static function overheadBp(Company $company): int
    {
        $bp = self::for($company)['overhead_bp'];

        return $bp === null || $bp === '' ? 0 : max(0, (int) $bp);
    }

    /** Whether finished goods and work in progress carry the overhead (§8); off by default. */
    public static function overheadInStockValue(Company $company): bool
    {
        return (bool) self::for($company)['overhead_in_stock_value'];
    }

    /** @return array{0: int, 1: int} the work in progress stock account and its change account */
    public static function wipAccounts(Company $company): array
    {
        $settings = self::for($company);

        return [(int) $settings['wip_stock_account'], (int) $settings['wip_change_account']];
    }
}
