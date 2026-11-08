<?php

declare(strict_types=1);

namespace App\Modules\WorkOrders;

use App\Models\Company;
use App\Modules\ModuleManager;
use App\Modules\WorkOrders\Domain\FixedPriceMethod;

/**
 * Arbetsorder's settings per company, in company_modules.settings.
 */
final class WorkOrdersSettings
{
    /**
     * The standard contracts a work order can name (AO-R37): names only,
     * never clause text. TODO(legal-verify): L15 — whether naming them in a
     * pick list is fine.
     */
    public const array STANDARD_CONTRACTS = ['AB 04', 'ABT 06', 'ABS 18', 'Hantverkarformuläret 17'];

    /** @var array<string, mixed> */
    public const array DEFAULTS = [
        // ADR-074 O2: a project per work order, code = the work order's number; can be switched off.
        'auto_project' => true,
        // Mark-up on material cost when no sales price is given, in basis points.
        'material_markup_bp' => 0,
        // The one method for all fixed-price jobs at year-end (K2 6.15, AO-R31).
        'fixed_price_method' => 'NOT_SET',
    ];

    /** @return array<string, mixed> */
    public static function for(Company $company): array
    {
        return [...self::DEFAULTS, ...app(ModuleManager::class)->settings(WorkOrdersModule::KEY, $company)];
    }

    public static function autoProject(Company $company): bool
    {
        return (bool) self::for($company)['auto_project'];
    }

    public static function markupBp(Company $company): int
    {
        return (int) self::for($company)['material_markup_bp'];
    }

    public static function fixedPriceMethod(Company $company): FixedPriceMethod
    {
        return FixedPriceMethod::tryFrom((string) self::for($company)['fixed_price_method']) ?? FixedPriceMethod::NotSet;
    }
}
