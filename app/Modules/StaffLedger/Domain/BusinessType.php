<?php

declare(strict_types=1);

namespace App\Modules\StaffLedger\Domain;

/**
 * The industries with a staff ledger obligation that v1 covers. Construction
 * (ID06, byggarbetsplatsanmälan) waits for v2. "Other" is a location without
 * the obligation, where the ledger is used for time reporting only.
 *
 * TODO(legal-verify): the industry list and its exact delimitation against
 * 39 kap. skatteförfarandelagen as amended by prop. 2025/26:282.
 */
enum BusinessType: string
{
    case Restaurant = 'RESTAURANT';
    case VehicleService = 'VEHICLE_SERVICE';
    case FoodTobaccoWholesale = 'FOOD_TOBACCO_WHOLESALE';
    case BeautyCare = 'BEAUTY_CARE';
    case Laundry = 'LAUNDRY';
    case Other = 'OTHER';

    public function label(): string
    {
        return match ($this) {
            self::Restaurant => __('Restaurang'),
            self::VehicleService => __('Fordonsservice'),
            self::FoodTobaccoWholesale => __('Livsmedels- och tobaksgrossist'),
            self::BeautyCare => __('Kropps- och skönhetsvård (även frisör)'),
            self::Laundry => __('Tvätteri'),
            self::Other => __('Övrig verksamhet (bara tidsredovisning)'),
        };
    }
}
