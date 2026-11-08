<?php

declare(strict_types=1);

namespace App\Domain\Mileage;

use App\Models\Vehicle;
use App\Models\VehicleTrip;
use App\Modules\Payroll\Domain\Benefits\FuelBenefit;
use App\Modules\Payroll\Domain\Rules\MissingPayrollRuleException;
use App\Modules\Payroll\Domain\Rules\RuleSet;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * A company car's calendar year in the körjournal, as evidence for the car
 * benefit (ADR-072 §5). Advice only: NONOX never sets a benefit to zero or
 * to 75 % by itself (KJ-R11 to KJ-R13); payroll staff decide.
 *
 * - Business km against 3 000 mil (IL 61 kap. 9 §, F8a).
 * - Private km and private occasions against Skatteverket's reading of
 *   ringa omfattning (61 kap. 11 §): at most 100 mil AND at most 10
 *   occasions; both must hold (rule benefit.car.minor_extent, F8b, F8c).
 * - A proposed private fuel value for a month: fuel cost × private km /
 *   total km (61 kap. 10 §, F9). TODO(legal-verify): L6 — whether a distance
 *   share is enough to "göra sannolikt" the private consumption.
 */
final readonly class CompanyCarYear
{
    /** IL 61 kap. 9 §: at least 3 000 mil in service in a calendar year gives 75 % of the value. */
    public const int LONG_BUSINESS_MIL = 3000;

    public function __construct(
        public int $year,
        public int $businessHm,
        public int $privateHm,
        public int $privateOccasions,
        public int $commuteHm,
        public ?int $maxPrivateMil,
        public ?int $maxPrivateOccasions,
    ) {}

    /** @param Collection<int, VehicleTrip> $trips the car's trips in the year */
    public static function of(int $year, Collection $trips, RuleSet $rules): self
    {
        try {
            $minor = (array) $rules->get('benefit.car.minor_extent');
        } catch (MissingPayrollRuleException) {
            $minor = [];
        }

        $sum = fn (TripKind $kind): int => (int) $trips->filter(fn (VehicleTrip $trip): bool => $trip->kind === $kind)->sum('distance_hm');

        return new self(
            year: $year,
            businessHm: $sum(TripKind::Business),
            privateHm: $sum(TripKind::Private),
            privateOccasions: $trips->filter(fn (VehicleTrip $trip): bool => $trip->kind === TripKind::Private && $trip->distance_hm > 0)->count(),
            commuteHm: $sum(TripKind::Commute),
            maxPrivateMil: isset($minor['max_private_mil']) ? (int) $minor['max_private_mil'] : null,
            maxPrivateOccasions: isset($minor['max_private_occasions']) ? (int) $minor['max_private_occasions'] : null,
        );
    }

    public function longBusinessMileageReached(): bool
    {
        return $this->businessHm >= self::LONG_BUSINESS_MIL * Distance::HM_PER_MIL;
    }

    /** Mil left to 3 000 in service, never below zero. */
    public function milToLongBusiness(): string
    {
        return Distance::formatMil(max(0, self::LONG_BUSINESS_MIL * Distance::HM_PER_MIL - $this->businessHm));
    }

    /** True, false, or null while the rule is not checked. */
    public function withinMinorExtent(): ?bool
    {
        if ($this->maxPrivateMil === null || $this->maxPrivateOccasions === null) {
            return null;
        }

        return $this->privateHm <= $this->maxPrivateMil * Distance::HM_PER_MIL && $this->privateOccasions <= $this->maxPrivateOccasions;
    }

    /** What the screen says about private use, in Swedish. */
    public function minorExtentText(): string
    {
        return match (true) {
            $this->withinMinorExtent() === null => __('Regeln för bilförmån i ringa omfattning är inte kontrollerad. Kontrollera den under Lön → Regler.'),
            $this->withinMinorExtent() => __('Privat körning inom :mil mil och :times tillfällen', ['mil' => $this->maxPrivateMil, 'times' => $this->maxPrivateOccasions]),
            $this->privateOccasions > (int) $this->maxPrivateOccasions => __('Fler än :times privata tillfällen', ['times' => $this->maxPrivateOccasions]),
            default => __('Mer än :mil mil privat körning', ['mil' => $this->maxPrivateMil]),
        };
    }

    /**
     * The proposed fuel benefit for a month with employer-paid fuel: the
     * market value of the private share and the taxable value × the rule's
     * factor. Null when the month has no driving.
     *
     * @param  Collection<int, VehicleTrip>  $trips
     * @return ?array{private_hm: int, total_hm: int, market_value_ore: int, benefit_ore: int, factor_bp: int}
     */
    public static function fuelProposal(Vehicle $vehicle, CarbonImmutable $month, int $fuelCostOre, Collection $trips, RuleSet $rules): ?array
    {
        $inMonth = $trips->filter(fn (VehicleTrip $trip): bool => $trip->vehicle_id === $vehicle->id && $trip->trip_date->format('Y-m') === $month->format('Y-m'));
        $total = (int) $inMonth->sum('distance_hm');
        $private = (int) $inMonth->filter(fn (VehicleTrip $trip): bool => $trip->kind !== TripKind::Business)->sum('distance_hm');

        if ($total <= 0 || $fuelCostOre <= 0) {
            return null;
        }

        $factor = (int) ((array) $rules->get(FuelBenefit::RULE))['tax_factor_bp'];
        $market = BigDecimal::of($fuelCostOre)->multipliedBy($private)->dividedBy($total, 0, RoundingMode::HalfUp)->toInt();

        return [
            'private_hm' => $private,
            'total_hm' => $total,
            'market_value_ore' => $market,
            'benefit_ore' => BigDecimal::of($market)->multipliedBy($factor)->dividedBy(10_000, 0, RoundingMode::HalfUp)->toInt(),
            'factor_bp' => $factor,
        ];
    }
}
