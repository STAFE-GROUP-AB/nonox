<?php

declare(strict_types=1);

namespace App\Modules\Payroll\Actions;

use App\Models\Company;
use App\Models\User;
use App\Modules\ModuleManager;
use App\Modules\Payroll\Domain\Benefits\CarBenefitCar;
use App\Modules\Payroll\Models\Benefit;
use App\Modules\Payroll\Models\Employee;
use App\Modules\Payroll\Models\PayrollRun;
use App\Modules\Payroll\PayrollModule;
use App\Support\Audit\Audit;
use App\Support\Money\Money;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

/**
 * The company car register (Lön → Förmånsbilar, ADR-061): a car given to an
 * employee as a benefit, from a first to an optional last day, with what its
 * value is calculated from (Support\CarBenefits, IL 61 kap. 5–11 §§). A
 * month whose regular run is attested keeps what it was paid with: a car
 * that has been in such a month can only get an end date (on or after that
 * month), never other changes and never be deleted; register a new car from
 * a later day instead. Calculated runs in the months touched go back to
 * draft. Audited with ids and field names only.
 */
class CompanyCars
{
    public function __construct(private readonly ModuleManager $modules, private readonly PayrollRuns $runs) {}

    /**
     * @param  array<string, mixed>  $input  employee_id, make_model, registration_number, model_year, first_taxable_on, fuel, new_car_price, extra_equipment, vehicle_tax, long_business_mileage, own_payment_monthly, valid_from, valid_to
     */
    public function save(User $user, Company $company, array $input, ?Benefit $car = null): Benefit
    {
        $this->authorize($user, $company);

        $data = Validator::make($input, [
            'employee_id' => ['required', 'integer'],
            'make_model' => ['required', 'string', 'max:120'],
            'registration_number' => ['nullable', 'string', 'regex:/^[A-Za-z0-9 ]{2,10}$/'],
            'model_year' => ['required', 'integer', 'between:1950,'.(CarbonImmutable::today()->year + 1)],
            'first_taxable_on' => ['required', 'date_format:Y-m-d'],
            'fuel' => ['required', Rule::in(CarBenefitCar::FUELS)],
            'new_car_price' => ['required', 'string'],
            'extra_equipment' => ['nullable', 'string'],
            'vehicle_tax' => ['required', 'string'],
            'long_business_mileage' => ['boolean'],
            'own_payment_monthly' => ['nullable', 'string'],
            'valid_from' => ['required', 'date_format:Y-m-d'],
            'valid_to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:valid_from'],
        ], [
            'registration_number.regex' => __('Skriv registreringsnumret med bokstäver och siffror, till exempel ABC123.'),
        ], [
            'make_model' => __('märke och modell'),
            'model_year' => __('årsmodell'),
            'first_taxable_on' => __('dag då bilen blev skattepliktig'),
            'fuel' => __('drivmedel'),
            'new_car_price' => __('nybilspris'),
            'vehicle_tax' => __('fordonsskatt'),
            'valid_from' => __('första dag'),
            'valid_to' => __('sista dag'),
        ])->validate();

        $employee = Employee::query()->findOrFail((int) $data['employee_id']);
        $carData = [
            'make_model' => trim((string) $data['make_model']),
            'registration_number' => strtoupper(str_replace(' ', '', (string) ($data['registration_number'] ?? ''))),
            'model_year' => (int) $data['model_year'],
            'first_taxable_on' => (string) $data['first_taxable_on'],
            'fuel' => (string) $data['fuel'],
            'new_car_price_ore' => $this->amount('new_car_price', $data['new_car_price'] ?? null),
            'extra_equipment_ore' => $this->amount('extra_equipment', $data['extra_equipment'] ?? null),
            'vehicle_tax_ore' => $this->amount('vehicle_tax', $data['vehicle_tax'] ?? null),
            'long_business_mileage' => (bool) ($data['long_business_mileage'] ?? false),
            'own_payment_monthly_ore' => $this->amount('own_payment_monthly', $data['own_payment_monthly'] ?? null),
        ];

        if ($carData['new_car_price_ore'] <= 0) {
            throw ValidationException::withMessages(['new_car_price' => __('Ange bilens nybilspris.')]);
        }

        $from = CarbonImmutable::parse((string) $data['valid_from']);
        $to = blank($data['valid_to'] ?? null) ? null : CarbonImmutable::parse((string) $data['valid_to']);

        return DB::transaction(function () use ($user, $company, $car, $employee, $carData, $from, $to): Benefit {
            Employee::query()->whereKey($employee->id)->lockForUpdate()->first();

            if ($car instanceof Benefit) {
                $car = Benefit::query()->whereKey($car->id)->where('type', Benefit::CAR)->lockForUpdate()->firstOrFail();
                $this->ensureChangeable($car, $employee, $carData, $from, $to);
                $this->ensureNoNewAttestedMonths($from, $to, $car->valid_from, $car->valid_to);
                $this->outdate($car->valid_from, $car->valid_to);
                $car->forceFill(['employee_id' => $employee->id, 'valid_from' => $from->toDateString(), 'valid_to' => $to?->toDateString(), 'car_data' => $carData])->save();
                $action = 'payroll.car_benefit_updated';
            } else {
                $this->ensureNoNewAttestedMonths($from, $to, null, null);
                $car = Benefit::create([
                    'employee_id' => $employee->id,
                    'type' => Benefit::CAR,
                    'value_rule' => [],
                    'valid_from' => $from->toDateString(),
                    'valid_to' => $to?->toDateString(),
                    'car_data' => $carData,
                ]);
                $action = 'payroll.car_benefit_created';
            }

            $this->ensureOneCarAtATime($car);
            $this->outdate($from, $to);
            Audit::record($action, $car, ['employee_id' => $employee->id, 'valid_from' => $from->toDateString(), 'valid_to' => $to?->toDateString(), 'fields' => array_keys($carData)], $company, $user);

            return $car;
        });
    }

    public function delete(User $user, Company $company, Benefit $car): void
    {
        $this->authorize($user, $company);

        DB::transaction(function () use ($user, $company, $car): void {
            $car = Benefit::query()->whereKey($car->id)->where('type', Benefit::CAR)->lockForUpdate()->firstOrFail();

            if ($this->lastAttestedMonth($car) !== null) {
                throw ValidationException::withMessages(['car' => __('Bilen har funnits med i en attesterad lön och kan inte tas bort. Ange en sista dag i stället.')]);
            }

            Audit::record('payroll.car_benefit_deleted', $car, ['employee_id' => $car->employee_id, 'valid_from' => $car->valid_from->toDateString(), 'valid_to' => $car->valid_to?->toDateString()], $company, $user);
            $this->outdate($car->valid_from, $car->valid_to);
            $car->delete();
        });
    }

    /**
     * The last month with an attested (approved, paid or posted) regular run
     * the car was in, or null.
     */
    public function lastAttestedMonth(Benefit $car): ?CarbonImmutable
    {
        $period = PayrollRun::query()
            ->where('type', 'REGULAR')
            ->whereNotIn('status', [PayrollRun::DRAFT, PayrollRun::CALCULATED])
            ->where('period', '>=', $car->valid_from->format('Y-m'))
            ->when($car->valid_to !== null, fn ($query) => $query->where('period', '<=', $car->valid_to?->format('Y-m')))
            ->max('period');

        return $period === null ? null : (CarbonImmutable::createFromFormat('!Y-m', (string) $period) ?: null);
    }

    /**
     * @param  array<string, mixed>  $carData
     */
    private function ensureChangeable(Benefit $car, Employee $employee, array $carData, CarbonImmutable $from, ?CarbonImmutable $to): void
    {
        $attested = $this->lastAttestedMonth($car);

        if ($attested === null) {
            return;
        }

        $same = $car->employee_id === $employee->id
            && $car->valid_from->equalTo($from)
            && $carData == ($car->car_data ?? []);

        if (! $same) {
            throw ValidationException::withMessages(['car' => __('Bilen har funnits med i lönen för :month, som är attesterad. Bara en sista dag kan läggas till. Registrera en ny bil från en senare dag om uppgifterna har ändrats.', ['month' => $attested->translatedFormat('F Y')])]);
        }

        if ($to !== null && $to->lessThan($attested->endOfMonth()->startOfDay())) {
            throw ValidationException::withMessages(['valid_to' => __('Sista dagen kan inte ligga före slutet av :month, som redan är attesterad med bilförmån.', ['month' => $attested->translatedFormat('F Y')])]);
        }
    }

    /**
     * A month whose regular run is attested was paid without the car unless
     * the car already covered it: the new period may not take in such a
     * month, also not by a later or open end date.
     */
    private function ensureNoNewAttestedMonths(CarbonImmutable $from, ?CarbonImmutable $to, ?CarbonImmutable $oldFrom, ?CarbonImmutable $oldTo): void
    {
        $periods = PayrollRun::query()
            ->where('type', 'REGULAR')
            ->whereNotIn('status', [PayrollRun::DRAFT, PayrollRun::CALCULATED])
            ->where('period', '>=', $from->format('Y-m'))
            ->when($to !== null, fn ($query) => $query->where('period', '<=', $to?->format('Y-m')))
            ->orderBy('period')
            ->pluck('period');
        $covered = fn (string $period): bool => $oldFrom !== null
            && $period >= $oldFrom->format('Y-m')
            && ($oldTo === null || $period <= $oldTo->format('Y-m'));
        $new = $periods->first(fn (string $period): bool => ! $covered($period));

        if ($new !== null) {
            $month = CarbonImmutable::createFromFormat('!Y-m', (string) $new) ?: $from;

            throw ValidationException::withMessages(['valid_from' => __('Lönen för :month är redan attesterad utan bilen. Välj en period som inte omfattar den månaden och rätta den attesterade månaden för hand.', ['month' => $month->translatedFormat('F Y')])]);
        }
    }

    /** One car per employee and month: two cars in a month would give two benefits (TODO(legal-verify): a change of car within a month). */
    private function ensureOneCarAtATime(Benefit $car): void
    {
        $overlap = Benefit::query()
            ->where('employee_id', $car->employee_id)
            ->where('type', Benefit::CAR)
            ->whereKeyNot($car->id)
            // By month: the run gives one car per month, so a car may only follow another from the first of the next month.
            ->where(fn ($query) => $query->whereNull('valid_to')->orWhereDate('valid_to', '>=', $car->valid_from->startOfMonth()->toDateString()))
            ->when($car->valid_to !== null, fn ($query) => $query->whereDate('valid_from', '<=', $car->valid_to?->endOfMonth()->toDateString()))
            ->exists();

        if ($overlap) {
            throw ValidationException::withMessages(['valid_from' => __('Den anställda har redan en förmånsbil under samma månad. En ny bil kan gälla från den första dagen i månaden efter att den andra bilen slutar; ange en sista dag för den andra bilen först.')]);
        }
    }

    /** Calculated regular runs in the months no longer match. */
    private function outdate(CarbonImmutable $from, ?CarbonImmutable $to): void
    {
        PayrollRun::query()
            ->where('type', 'REGULAR')
            ->where('status', PayrollRun::CALCULATED)
            ->where('period', '>=', $from->format('Y-m'))
            ->when($to !== null, fn ($query) => $query->where('period', '<=', $to?->format('Y-m')))
            ->lockForUpdate()
            ->get()
            ->each(fn (PayrollRun $run) => $this->runs->backToDraft($run));
    }

    private function amount(string $field, mixed $value): int
    {
        if (blank($value)) {
            return 0;
        }

        try {
            $ore = Money::parse((string) $value)->ore;
        } catch (InvalidArgumentException) {
            throw ValidationException::withMessages([$field => __('Beloppet går inte att läsa.')]);
        }

        if ($ore < 0) {
            throw ValidationException::withMessages([$field => __('Beloppet kan inte vara negativt.')]);
        }

        return $ore;
    }

    private function authorize(User $user, Company $company): void
    {
        $this->modules->ensureEnabled(PayrollModule::KEY, $company);

        if (! $user->hasTeamPermission($company, 'payroll:manage')) {
            throw new AuthorizationException(__('Du saknar behörighet att ändra förmånsbilar.'));
        }
    }
}
