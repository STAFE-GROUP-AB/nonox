<?php

declare(strict_types=1);

namespace App\Modules\Payroll\Livewire;

use App\Actions\Mileage\SubmitMileageClaim;
use App\Domain\Mileage\Distance;
use App\Domain\Mileage\MileageLog;
use App\Domain\Mileage\TripKind;
use App\Domain\Mileage\TripStatus;
use App\Domain\Mileage\VehicleKind;
use App\Livewire\Concerns\LogsTrips;
use App\Models\Company;
use App\Models\MileageRoute;
use App\Models\Project;
use App\Models\User;
use App\Models\Vehicle;
use App\Models\VehicleTrip;
use App\Modules\ModuleManager;
use App\Modules\Payroll\Models\Employee;
use App\Modules\Payroll\Models\Expense;
use App\Modules\Payroll\PayrollModule;
use App\Support\Money\Money;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * Min sida → Körjournal (ADR-072 §4): the employee logs their trips by
 * hand, in their own car or the company car they drive, and sends a
 * month's trips as one mileage claim. No position is ever read. The
 * employee sees only their own trips.
 */
class MyMileage extends Component
{
    use LogsTrips;

    #[Url(as: 'manad')]
    public string $month = '';

    #[Url(as: 'resa')]
    public ?string $resa = null;

    public bool $paidAllFuel = false;

    public function mount(): void
    {
        abort_unless(MileageLog::canLogAsEmployee($this->tripUser(), $this->tripCompany()), 404);

        if (preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $this->month) !== 1) {
            $this->month = CarbonImmutable::today()->format('Y-m');
        }

        $this->trip = $this->emptyTrip();

        if ($this->resa !== null) {
            $this->editTrip($this->resa);
        } elseif (request()->boolean('ny')) {
            // The staff app's shortcut "Ny resa".
            $this->startTrip();
        }
    }

    public function submitClaim(SubmitMileageClaim $claims): void
    {
        $sent = $claims($this->tripUser(), $this->tripCompany(), $this->month, $this->paidAllFuel);
        $this->paidAllFuel = false;
        $total = array_sum(array_map(fn (Expense $expense): int => $expense->amount_ore->ore, $sent));

        $this->toast(__('Resorna för :month är inskickade (:amount). De betalas med lönen när de är godkända.', [
            'month' => SubmitMileageClaim::monthName(CarbonImmutable::parse("{$this->month}-01")),
            'amount' => Money::ofOre($total)->format(),
        ]));
    }

    public function render(): View
    {
        $user = $this->tripUser();
        $company = $this->tripCompany();
        $monthStart = CarbonImmutable::parse("{$this->month}-01");
        $settings = app(ModuleManager::class)->settings(PayrollModule::KEY, $company);

        $trips = VehicleTrip::query()->with('vehicle')
            ->where('driver_user_id', $user->id)
            ->where(fn ($query) => $query
                ->whereBetween('trip_date', [$monthStart->toDateString(), $monthStart->endOfMonth()->toDateString()])
                ->orWhere(fn ($open) => $open->whereNotNull('corrects_trip_id')->where('status', TripStatus::Draft->value)))
            ->orderByDesc('trip_date')->orderByDesc('created_at')
            ->get();

        $toSend = $trips->filter(fn (VehicleTrip $trip): bool => in_array($trip->status, [TripStatus::Draft, TripStatus::Rejected], true)
            && ($trip->kind === TripKind::Business || ($trip->kind === TripKind::Commute && (bool) ($settings['mileage_pay_commute'] ?? false))));
        $lastDay = blank($settings['mileage_claim_last_day'] ?? null) ? null : min((int) $settings['mileage_claim_last_day'], $monthStart->daysInMonth);
        $employee = Employee::query()->where('user_id', $user->id)->first(['id']);

        return view('payroll::livewire.my-mileage', [
            'vehicles' => Vehicle::query()->whereNull('archived_at')->where(fn ($query) => $query->where('user_id', $user->id)->orWhere('kind', VehicleKind::CompanyCar->value))->orderBy('kind')->orderBy('label')->get(),
            'projects' => Project::query()->where('is_active', true)->orderBy('code')->get(['id', 'code', 'name']),
            'routes' => MileageRoute::query()->where('user_id', $user->id)->orderBy('id')->get(),
            'trips' => $trips,
            'toSend' => $toSend,
            'toSendHm' => (int) $toSend->sum('distance_hm'),
            'needsFuel' => $toSend->contains(fn (VehicleTrip $trip): bool => $trip->vehicle->isCompanyCar() && $trip->kind === TripKind::Business),
            'claims' => $employee === null ? collect() : Expense::query()->where('employee_id', $employee->id)->whereIn('type', Expense::MILEAGE_TYPES)->latest('date')->latest('id')->limit(6)->get(),
            'reminder' => $lastDay !== null && $toSend->isNotEmpty() ? $monthStart->setDay($lastDay) : null,
            'monthName' => SubmitMileageClaim::monthName($monthStart),
            'kinds' => TripKind::cases(),
            'formatKm' => Distance::formatKm(...),
            'formatMil' => Distance::formatMil(...),
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
