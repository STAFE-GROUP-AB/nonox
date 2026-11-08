<?php

declare(strict_types=1);

namespace App\Modules\Payroll\Livewire;

use App\Domain\Mileage\CompanyCarBenefits;
use App\Domain\Mileage\CompanyCarYear;
use App\Domain\Mileage\MileageLog;
use App\Domain\Mileage\TripKind;
use App\Domain\Mileage\VehicleKind;
use App\Livewire\Concerns\ShowsToasts;
use App\Models\Company;
use App\Models\User;
use App\Models\Vehicle;
use App\Models\VehicleTrip;
use App\Modules\Payroll\Domain\Rules\MissingPayrollRuleException;
use App\Modules\Payroll\Domain\Rules\RuleSet;
use App\Support\Money\Money;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;
use InvalidArgumentException;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * Lön → Förmånsbilar, the körjournal's part (ADR-072 §5): per company car
 * and calendar year the business km against 3 000 mil, private km and
 * occasions against Skatteverket's reading of ringa omfattning, a warning
 * for private trips in a pool car, and a proposed private fuel value.
 * Advice only: payroll staff decide, NONOX never changes a benefit.
 */
class CarMileageSummary extends Component
{
    use ShowsToasts;

    #[Url(as: 'ar')]
    public int $year = 0;

    /** @var array<string, string> vehicle_id, month, fuel_cost */
    public array $fuel = ['vehicle_id' => '', 'month' => '', 'fuel_cost' => ''];

    /** @var ?array<string, int> */
    public ?array $proposal = null;

    public function mount(): void
    {
        abort_unless($this->user()->hasTeamPermission($this->company(), 'payroll:view'), 403);
        $this->year = $this->year > 2000 ? $this->year : CarbonImmutable::today()->year;
        $this->fuel['month'] = CarbonImmutable::today()->subMonth()->format('Y-m');
    }

    public function propose(): void
    {
        $vehicle = Vehicle::query()->where('kind', VehicleKind::CompanyCar->value)->findOrFail((int) $this->fuel['vehicle_id']);

        try {
            $cost = Money::parse((string) $this->fuel['fuel_cost'])->ore;
            $month = CarbonImmutable::parse(((string) $this->fuel['month']).'-01');
        } catch (InvalidArgumentException) {
            $this->addError('fuel.fuel_cost', __('Skriv drivmedelskostnaden i kronor, till exempel 3 000.'));

            return;
        }

        $trips = VehicleTrip::query()->where('vehicle_id', $vehicle->id)->whereBetween('trip_date', [$month->toDateString(), $month->endOfMonth()->toDateString()])->get();

        try {
            $this->proposal = CompanyCarYear::fuelProposal($vehicle, $month, $cost, $trips, RuleSet::for($month->endOfMonth()));
        } catch (MissingPayrollRuleException) {
            $this->toast(__('Regeln för drivmedelsförmån är inte kontrollerad för månaden. Kontrollera den under Lön → Regler.'), 'error');

            return;
        }

        if ($this->proposal === null) {
            $this->toast(__('Det finns inga resor eller ingen kostnad i månaden att räkna på.'), 'error');
        }
    }

    public function render(): View
    {
        $company = $this->company();
        $enabled = MileageLog::enabled($company);
        $cars = $enabled ? Vehicle::query()->where('kind', VehicleKind::CompanyCar->value)->orderBy('label')->get() : collect();
        $start = CarbonImmutable::create($this->year, 1, 1) ?? CarbonImmutable::today()->startOfYear();
        $trips = $cars->isEmpty() ? collect() : VehicleTrip::query()
            ->whereIn('vehicle_id', $cars->pluck('id'))
            ->whereBetween('trip_date', [$start->toDateString(), $start->endOfYear()->toDateString()])
            ->get(['id', 'vehicle_id', 'trip_date', 'kind', 'distance_hm'])
            ->groupBy('vehicle_id');
        $rules = RuleSet::for($start->endOfYear());
        $benefits = CompanyCarBenefits::for($company);

        $summaries = $cars->map(fn (Vehicle $car): array => [
            'car' => $car,
            'year' => CompanyCarYear::of($this->year, $trips->get($car->id, collect()), $rules),
            'pool' => $benefits->forYear($car, $this->year)->isEmpty(),
            'poolPrivate' => $trips->get($car->id, collect())->contains(fn (VehicleTrip $trip): bool => $trip->kind === TripKind::Private),
        ]);

        return view('payroll::livewire.car-mileage', [
            'enabled' => $enabled,
            'summaries' => $summaries,
            'cars' => $cars,
        ]);
    }

    private function company(): Company
    {
        /** @var Company $company */
        $company = $this->user()->currentCompany();

        return $company;
    }

    private function user(): User
    {
        /** @var User $user */
        $user = Auth::user();

        return $user;
    }
}
