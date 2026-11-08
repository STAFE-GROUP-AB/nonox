<?php

declare(strict_types=1);

namespace App\Livewire\Mileage;

use App\Actions\Mileage\BookOwnerMileage;
use App\Actions\Mileage\CloseVehicleYear;
use App\Actions\Mileage\SaveVehicle;
use App\Actions\Mileage\SubmitMileageClaim;
use App\Domain\Mileage\MileageLog;
use App\Domain\Mileage\TripKind;
use App\Domain\Mileage\TripStatus;
use App\Domain\Mileage\VehicleKind;
use App\Livewire\Concerns\LogsTrips;
use App\Models\Account;
use App\Models\Company;
use App\Models\MileageRoute;
use App\Models\Project;
use App\Models\User;
use App\Models\Vehicle;
use App\Models\VehicleTrip;
use App\Models\VehicleYearReading;
use App\Modules\Payroll\Domain\Benefits\CarBenefitCar;
use App\Modules\Payroll\Models\PayrollRuleVersion;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * Bokföring → Körjournal (ADR-072 §1 and §7). Staff with mileage:view see
 * each company car's log; mileage:manage keeps the cars and the odometer at
 * the start and end of each year. The owner of an enskild firma logs trips
 * in their own car and books a month as a draft voucher. Trips are shown
 * per car, never as a report per person.
 */
class MileageLogPage extends Component
{
    use LogsTrips;

    #[Url(as: 'manad')]
    public string $month = '';

    #[Url(as: 'bil')]
    public string $vehicle = '';

    #[Url(as: 'ar')]
    public int $year = 0;

    /** @var array<string, mixed> */
    public array $companyCar = ['label' => '', 'registration_number' => '', 'fuel' => 'PETROL'];

    /** @var array<int, array{start: string, end: string}> by vehicle id */
    public array $readings = [];

    public string $costAccount = '5800';

    public function mount(): void
    {
        $company = $this->tripCompany();
        abort_unless(MileageLog::enabled($company), 404);
        abort_unless(MileageLog::canView($this->tripUser(), $company), 403);

        if (preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $this->month) !== 1) {
            $this->month = CarbonImmutable::today()->format('Y-m');
        }

        $this->year = $this->year > 2000 ? $this->year : CarbonImmutable::today()->year;
        $this->costAccount = (string) BookOwnerMileage::DEFAULT_COST_ACCOUNT;
        $this->trip = $this->emptyTrip();
        $this->car = ['label' => '', 'registration_number' => '', 'is_business_asset' => ''];
    }

    public function saveCompanyCar(SaveVehicle $vehicles): void
    {
        $vehicles($this->tripUser(), $this->tripCompany(), ['kind' => VehicleKind::CompanyCar->value, ...$this->companyCar]);
        $this->companyCar = ['label' => '', 'registration_number' => '', 'fuel' => 'PETROL'];
        $this->toast(__('Bilen är tillagd i körjournalen.'));
    }

    public function archiveVehicle(int $id, SaveVehicle $vehicles): void
    {
        $vehicles->archive($this->tripUser(), $this->tripCompany(), Vehicle::query()->findOrFail($id));
        $this->toast(__('Bilen är arkiverad. Resorna i den finns kvar.'));
    }

    public function saveReading(int $vehicleId, CloseVehicleYear $years): void
    {
        $years->save($this->tripUser(), $this->tripCompany(), Vehicle::query()->findOrFail($vehicleId), $this->year, [
            'start_odometer' => blank($this->readings[$vehicleId]['start'] ?? null) ? null : $this->readings[$vehicleId]['start'],
            'end_odometer' => blank($this->readings[$vehicleId]['end'] ?? null) ? null : $this->readings[$vehicleId]['end'],
        ]);
        $this->toast(__('Mätarställningarna är sparade.'));
    }

    public function closeYear(int $vehicleId, CloseVehicleYear $years): void
    {
        $years->close($this->tripUser(), $this->tripCompany(), Vehicle::query()->findOrFail($vehicleId), $this->year);
        $this->toast(__('Året :year är stängt i körjournalen för bilen.', ['year' => $this->year]));
    }

    public function verifyRate(int $ruleId, BookOwnerMileage $book): void
    {
        $book->verifyRate($this->tripUser(), $this->tripCompany(), PayrollRuleVersion::query()->findOrFail($ruleId));
        $this->toast(__('Beloppet är markerat som kontrollerat för företaget.'));
    }

    public function bookMonth(BookOwnerMileage $book): void
    {
        $voucher = $book($this->tripUser(), $this->tripCompany(), $this->month, (int) $this->costAccount);
        $this->toast(__('Ett verifikationsutkast är skapat med körjournalen som underlag. Granska och bokför det under Verifikationer.'));
        $this->redirectRoute('accounting.vouchers.edit', ['voucher' => $voucher->id]);
    }

    public function render(): View
    {
        $user = $this->tripUser();
        $company = $this->tripCompany();
        $owner = MileageLog::isSoleTraderOwner($user, $company);
        $manager = MileageLog::canManage($user, $company);
        $monthStart = CarbonImmutable::parse("{$this->month}-01");

        $vehicles = Vehicle::query()->whereNull('archived_at')
            ->where(fn ($query) => $query->where('kind', VehicleKind::CompanyCar->value)->orWhere('user_id', $user->id))
            ->orderBy('kind')->orderBy('label')->get();
        $readings = VehicleYearReading::query()->whereIn('vehicle_id', $vehicles->pluck('id'))->where('year', $this->year)->get()->keyBy('vehicle_id');

        foreach ($vehicles as $vehicle) {
            $reading = $readings->get($vehicle->id);
            $this->readings[$vehicle->id] ??= ['start' => (string) $reading?->start_odometer_km, 'end' => (string) $reading?->end_odometer_km];
        }

        $selected = $vehicles->firstWhere('id', (int) $this->vehicle);
        $carLog = $selected === null ? collect() : VehicleTrip::query()->with(['vehicle', 'project:id,code,name'])
            ->where('vehicle_id', $selected->id)
            ->whereBetween('trip_date', [$monthStart->toDateString(), $monthStart->endOfMonth()->toDateString()])
            ->orderBy('trip_date')->orderBy('odometer_start_km')
            ->get();
        $drivers = User::query()->whereIn('id', $carLog->pluck('driver_user_id')->filter()->unique())->pluck('name', 'id');

        $ownTrips = VehicleTrip::query()->with('vehicle')
            ->where('driver_user_id', $user->id)
            ->where(fn ($query) => $query
                ->whereBetween('trip_date', [$monthStart->toDateString(), $monthStart->endOfMonth()->toDateString()])
                ->orWhere(fn ($open) => $open->whereNotNull('corrects_trip_id')->where('status', TripStatus::Draft->value)->whereNull('voucher_id')))
            ->orderByDesc('trip_date')->orderByDesc('created_at')
            ->get();
        $bookable = $ownTrips->filter(fn (VehicleTrip $trip): bool => $trip->kind === TripKind::Business && $trip->vehicle->kind === VehicleKind::PrivateCar && self::tripEditable($trip));

        return view('mileage.livewire.mileage-log', [
            'owner' => $owner,
            'manager' => $manager,
            'vehicles' => $vehicles,
            'readingRows' => $readings,
            'selected' => $selected,
            'carLog' => $carLog,
            'drivers' => $drivers,
            'trips' => $ownTrips,
            'bookable' => $bookable,
            'projects' => Project::query()->where('is_active', true)->orderBy('code')->get(['id', 'code', 'name']),
            'routes' => MileageRoute::query()->where('user_id', $user->id)->orderBy('id')->get(),
            'kinds' => TripKind::cases(),
            'fuels' => CarBenefitCar::FUELS,
            'accounts' => $owner ? Account::query()->where('is_active', true)->whereBetween('number', [4000, 7999])->orderBy('number')->get(['number', 'name']) : collect(),
            'unchecked' => $owner ? BookOwnerMileage::uncheckedRate($monthStart->year) : null,
            'differing' => $owner ? BookOwnerMileage::differing($ownTrips) : [],
            'monthName' => SubmitMileageClaim::monthName($monthStart),
            'canLog' => $owner || $manager,
        ]);
    }

    protected function tripCompany(): Company
    {
        /** @var Company $company */
        $company = $this->tripUser()->currentCompany();

        return $company;
    }

    protected function tripUser(): User
    {
        /** @var User $user */
        $user = Auth::user();

        return $user;
    }
}
