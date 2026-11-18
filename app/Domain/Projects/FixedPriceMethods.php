<?php

declare(strict_types=1);

namespace App\Domain\Projects;

use App\Models\Company;
use App\Modules\WorkOrders\Domain\FixedPriceMethod;
use App\Modules\WorkOrders\WorkOrdersSettings;

/**
 * The company's one method for all fixed-price jobs at year end (K2 6.15,
 * K3 23.31), for work orders and for projects alike (ADR-081 gap 6, E8).
 * Kept on the company (`teams.fixed_price_method`); a company that chose its
 * method before E8 had it only in the work order module's settings, which
 * still count until the method is saved again.
 *
 * TODO(legal-verify): L13 — which proposals are right per framework.
 */
final class FixedPriceMethods
{
    public static function for(Company $company): FixedPriceMethod
    {
        $own = FixedPriceMethod::tryFrom((string) $company->getAttribute('fixed_price_method'));

        if ($own !== null) {
            return $own;
        }

        return FixedPriceMethod::tryFrom((string) (WorkOrdersSettings::for($company)['fixed_price_method'] ?? '')) ?? FixedPriceMethod::NotSet;
    }
}
