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
 */
final class ManufacturingSettings
{
    /** @var array<string, mixed> */
    public const array DEFAULTS = [
        'overhead_bp' => null,
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
}
