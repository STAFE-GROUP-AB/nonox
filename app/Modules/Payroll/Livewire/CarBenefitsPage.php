<?php

declare(strict_types=1);

namespace App\Modules\Payroll\Livewire;

use App\Livewire\Concerns\ShowsToasts;
use App\Models\Company;
use App\Models\User;
use App\Modules\Payroll\Actions\CompanyCars;
use App\Modules\Payroll\Domain\Benefits\CarBenefitValue;
use App\Modules\Payroll\Domain\Engine\PayrollBlockedException;
use App\Modules\Payroll\Domain\Rules\RuleSet;
use App\Modules\Payroll\Models\Benefit;
use App\Modules\Payroll\Models\Employee;
use App\Modules\Payroll\Support\CarBenefits;
use App\Support\Money\Money;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * Lön → Förmånsbilar (/lon/formansbilar, ADR-061): the company cars given to
 * employees, each car's benefit value for a year with the whole calculation,
 * and registering, changing or ending a car. The regular payroll run adds
 * the month's value by itself. Thin: CompanyCars and CarBenefits do the
 * work; a fixed number of queries whatever the number of cars.
 */
class CarBenefitsPage extends Component
{
    use ShowsToasts;

    #[Url(as: 'ar')]
    public ?int $year = null;

    #[Url(as: 'bil')]
    public ?int $shown = null;

    #[Locked]
    public ?int $editing = null;

    /** @var array<string, mixed> */
    public array $form = [];

    public function mount(): void
    {
        abort_unless($this->user()->hasTeamPermission($this->company(), 'payroll:view'), 403);
        $this->year ??= CarbonImmutable::today()->year;
        $this->resetForm();
    }

    public function edit(int $carId): void
    {
        $car = Benefit::query()->where('type', Benefit::CAR)->findOrFail($carId);
        $data = $car->car_data ?? [];
        $kronor = fn (string $key): string => (int) ($data[$key] ?? 0) === 0 ? '' : Money::ofOre((int) $data[$key])->format(false);
        $this->editing = $car->id;
        $this->form = [
            'employee_id' => $car->employee_id,
            'make_model' => (string) ($data['make_model'] ?? ''),
            'registration_number' => (string) ($data['registration_number'] ?? ''),
            'model_year' => (string) ($data['model_year'] ?? ''),
            'first_taxable_on' => (string) ($data['first_taxable_on'] ?? ''),
            'fuel' => (string) ($data['fuel'] ?? 'PETROL'),
            'new_car_price' => $kronor('new_car_price_ore'),
            'extra_equipment' => $kronor('extra_equipment_ore'),
            'vehicle_tax' => Money::ofOre((int) ($data['vehicle_tax_ore'] ?? 0))->format(false),
            'long_business_mileage' => (bool) ($data['long_business_mileage'] ?? false),
            'own_payment_monthly' => $kronor('own_payment_monthly_ore'),
            'valid_from' => $car->valid_from->toDateString(),
            'valid_to' => $car->valid_to?->toDateString() ?? '',
        ];
    }

    public function cancel(): void
    {
        $this->resetForm();
        $this->resetErrorBag();
    }

    public function save(CompanyCars $cars): void
    {
        $car = $this->editing === null ? null : Benefit::query()->where('type', Benefit::CAR)->findOrFail($this->editing);

        try {
            $saved = $cars->save($this->user(), $this->company(), $this->form, $car);
        } catch (ValidationException $exception) {
            $this->setErrorBag($exception->validator->errors());
            $this->toast((string) collect($exception->errors())->flatten()->first(), 'error');

            return;
        }

        $this->resetErrorBag();
        $this->resetForm();
        $this->shown = $saved->id;
        $this->toast($car === null
            ? __('Förmånsbilen är registrerad. Lönekörningen tar med förmånsvärdet varje månad.')
            : __('Förmånsbilen är ändrad.'));
    }

    public function delete(int $carId, CompanyCars $cars): void
    {
        try {
            $cars->delete($this->user(), $this->company(), Benefit::query()->where('type', Benefit::CAR)->findOrFail($carId));
        } catch (ValidationException $exception) {
            $this->toast((string) collect($exception->errors())->flatten()->first(), 'error');

            return;
        }

        $this->shown = null;
        $this->toast(__('Förmånsbilen är borttagen.'));
    }

    public function render(CarBenefits $benefits): View
    {
        $year = (int) $this->year;
        $cars = Benefit::query()->with('employee:id,first_name,last_name')->where('type', Benefit::CAR)->orderByDesc('valid_from')->orderByDesc('id')->get();
        $rules = RuleSet::for(CarbonImmutable::create($year, 1, 1) ?? CarbonImmutable::today()->startOfYear());
        /** @var array<int, CarBenefitValue|string> $values */
        $values = [];

        foreach ($cars as $car) {
            try {
                $values[$car->id] = $benefits->value($car, $year, $rules);
            } catch (PayrollBlockedException $exception) {
                $values[$car->id] = $exception->getMessage();
            }
        }

        return view('payroll::livewire.car-benefits', [
            'cars' => $cars,
            'values' => $values,
            'employees' => Employee::query()->orderBy('first_name')->orderBy('last_name')->get(['id', 'first_name', 'last_name']),
            'canManage' => $this->user()->hasTeamPermission($this->company(), 'payroll:manage'),
        ]);
    }

    private function resetForm(): void
    {
        $this->editing = null;
        $this->form = [
            'employee_id' => null, 'make_model' => '', 'registration_number' => '', 'model_year' => '', 'first_taxable_on' => '',
            'fuel' => 'PETROL', 'new_car_price' => '', 'extra_equipment' => '', 'vehicle_tax' => '', 'long_business_mileage' => false,
            'own_payment_monthly' => '', 'valid_from' => CarbonImmutable::today()->startOfMonth()->toDateString(), 'valid_to' => '',
        ];
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
