<?php

declare(strict_types=1);

namespace App\Modules\Kitchen;

use App\Models\Company;
use App\Modules\ModuleManager;

/**
 * Kök's settings per company (ADR-073), kept in company_modules.settings:
 *
 * - location_id: the kitchen's stock location, the default for waste and
 *   staff meals;
 * - draw_on_sale: draw the ingredients of the till's sales from stock each
 *   night (RECIPE_SALE), off by default (Lead decision O2), from the day
 *   it was switched on (draw_on_sale_from), never for older sales;
 * - target_food_cost_bp: the target food cost in basis points, no default;
 *   dishes above it are marked, a price is never changed;
 * - average_meal_cost_ore: the cost of a "valfri rätt" staff meal.
 */
final class KitchenSettings
{
    /** @var array<string, mixed> */
    public const array DEFAULTS = [
        'location_id' => null,
        'draw_on_sale' => false,
        'draw_on_sale_from' => null,
        'target_food_cost_bp' => null,
        'average_meal_cost_ore' => null,
    ];

    /** @return array<string, mixed> */
    public static function for(Company $company): array
    {
        $stored = app(ModuleManager::class)->settings(KitchenModule::KEY, $company);

        return [...self::DEFAULTS, ...array_intersect_key($stored, self::DEFAULTS)];
    }

    public static function locationId(Company $company): ?int
    {
        $id = self::for($company)['location_id'];

        return $id === null || $id === '' ? null : (int) $id;
    }

    public static function drawsOnSale(Company $company): bool
    {
        return (bool) self::for($company)['draw_on_sale'];
    }
}
