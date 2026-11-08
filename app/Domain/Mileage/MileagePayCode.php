<?php

declare(strict_types=1);

namespace App\Domain\Mileage;

use App\Modules\Payroll\Domain\Benefits\CarBenefitCar;
use Carbon\CarbonImmutable;

/**
 * Which pay code a trip is paid on, from the rule sheet
 * (docs/specs/rules/korjournal.md):
 *
 * - a tjänsteresa in the person's own car: MILERSATTNING, tax-free up to
 *   IL 12 kap. 5 § första stycket (KJ-R1);
 * - a tjänsteresa in a company car where the driver paid all the fuel:
 *   MIL_FORMANSBIL_EL for a car driven only by electricity, otherwise
 *   MIL_FORMANSBIL (12 kap. 5 § andra stycket, KJ-R2); a plug-in hybrid
 *   charged at the workplace qualifies only for trips from 2026-07-01
 *   (12 kap. 5 § tredje stycket, SFS 2026:464, KJ-R4);
 * - a trip to or from work, or a private trip, is never tax-free: when the
 *   company pays for it at all it is MIL_SKATTEPLIKTIG (KJ-R6).
 *
 * The amounts are never here: they come from the payroll rules.
 */
final class MileagePayCode
{
    public const string OWN_CAR = 'MILERSATTNING';

    public const string COMPANY_CAR = 'MIL_FORMANSBIL';

    public const string COMPANY_CAR_ELECTRIC = 'MIL_FORMANSBIL_EL';

    public const string TAXABLE = 'MIL_SKATTEPLIKTIG';

    /** Tax-free pay code => the payroll rule with its amount per mil. */
    public const array RULES = [
        self::OWN_CAR => 'mileage.own_car_tax_free_per_mil',
        self::COMPANY_CAR => 'mileage.company_car_fossil',
        self::COMPANY_CAR_ELECTRIC => 'mileage.company_car_electric',
    ];

    /**
     * IL 12 kap. 5 § tredje stycket applies to trips "efter ikraftträdandet" (1 July 2026).
     * TODO(legal-verify): L4 — whether a trip on 2026-07-01 itself is covered; the law is in force that day, so it is.
     */
    public const string WORKPLACE_CHARGING_FROM = '2026-07-01';

    public static function forTrip(VehicleKind $vehicle, ?string $fuel, TripKind $kind): string
    {
        if ($kind !== TripKind::Business) {
            return self::TAXABLE;
        }

        if ($vehicle === VehicleKind::PrivateCar) {
            return self::OWN_CAR;
        }

        // TODO(legal-verify): L16 — a fuel-cell (hydrogen) car is not "elektricitet till elbil" in the literal reading, so 12 kr/mil.
        return $fuel === CarBenefitCar::ELECTRIC ? self::COMPANY_CAR_ELECTRIC : self::COMPANY_CAR;
    }

    /** Whether "laddad på arbetsplatsen" still allows the tax-free company-car amount on the trip date (KJ-R4). */
    public static function workplaceChargingAllowed(?string $fuel, CarbonImmutable $tripDate): bool
    {
        return $fuel === CarBenefitCar::PLUG_IN_HYBRID && ! $tripDate->lessThan(CarbonImmutable::parse(self::WORKPLACE_CHARGING_FROM));
    }

    public static function label(string $payCode): string
    {
        return match ($payCode) {
            self::OWN_CAR => __('Egen bil'),
            self::COMPANY_CAR => __('Förmånsbil'),
            self::COMPANY_CAR_ELECTRIC => __('Förmånsbil (el)'),
            default => __('Skattepliktig milersättning'),
        };
    }
}
