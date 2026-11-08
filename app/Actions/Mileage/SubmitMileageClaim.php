<?php

declare(strict_types=1);

namespace App\Actions\Mileage;

use App\Domain\Mileage\CompanyCarBenefits;
use App\Domain\Mileage\Distance;
use App\Domain\Mileage\MileageLog;
use App\Domain\Mileage\MileagePayCode;
use App\Domain\Mileage\TripKind;
use App\Domain\Mileage\TripStatus;
use App\Domain\Mileage\VehicleKind;
use App\Models\Company;
use App\Models\User;
use App\Models\VehicleTrip;
use App\Modules\ModuleManager;
use App\Modules\Payroll\Domain\Benefits\FuelBenefit;
use App\Modules\Payroll\Domain\Rules\MissingPayrollRuleException;
use App\Modules\Payroll\Domain\Rules\RuleSet;
use App\Modules\Payroll\Models\Employee;
use App\Modules\Payroll\Models\Expense;
use App\Modules\Payroll\Models\PayrollInput;
use App\Modules\Payroll\PayrollModule;
use App\Support\Audit\Audit;
use App\Support\Money\Money;
use App\Support\Tenancy\CurrentCompany;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

/**
 * An employee sends a month's trips as one mileage claim (ADR-072 §4,
 * Lead decision Q5): one expenses row per kind of car, MILEAGE_OWN_CAR or
 * MILEAGE_COMPANY_CAR, with one line per trip in trip_data. It goes to the
 * same queue as outlays (Lön → Ansökningar) and is paid by the next payroll
 * run on the mileage pay codes; nothing is booked at approval.
 *
 * Which trips count, from the rule sheet:
 * - tjänsteresor in the employee's own car: MILERSATTNING (KJ-R1);
 * - tjänsteresor in a company car: tax-free MIL_FORMANSBIL(_EL) only when
 *   the driver confirms having paid all the fuel and the company paid no
 *   fuel benefit for the employee in the month (KJ-R3, F5), and, for a
 *   plug-in hybrid charged at the workplace, only from 2026-07-01 (KJ-R4,
 *   F10); otherwise the trip can only be paid as taxable pay;
 * - trips to and from work only when the company pays for them, and then
 *   always taxable (KJ-R6, F6); private trips are never claimed.
 *
 * The amounts come from the payroll rules for the trip's year; when a rule
 * is not published yet (2027 förmånsbil, Lead decision Q3) the claim can be
 * sent, the amount waits, and the payroll run is blocked until the rule is
 * published and checked.
 */
class SubmitMileageClaim
{
    /** Swedish month names, the same whatever the viewer's language: they go on the payslip. */
    private const array MONTHS = [1 => 'januari', 'februari', 'mars', 'april', 'maj', 'juni', 'juli', 'augusti', 'september', 'oktober', 'november', 'december'];

    public function __construct(private readonly ModuleManager $modules) {}

    /**
     * @param  string  $month  YYYY-MM
     * @return list<Expense> the claims made, one per kind of car
     */
    public function __invoke(User $user, Company $company, string $month, bool $paidAllFuel = false): array
    {
        if (! MileageLog::canLogAsEmployee($user, $company)) {
            throw new AuthorizationException;
        }

        if (preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $month) !== 1) {
            throw ValidationException::withMessages(['month' => __('Välj en månad.')]);
        }

        $monthStart = CarbonImmutable::parse("{$month}-01");

        if ($monthStart->greaterThan(CarbonImmutable::today())) {
            throw ValidationException::withMessages(['month' => __('Månaden har inte börjat än.')]);
        }

        $settings = $this->modules->settings(PayrollModule::KEY, $company);

        return CurrentCompany::run($company, fn (): array => DB::transaction(function () use ($user, $company, $monthStart, $paidAllFuel, $settings): array {
            $employee = Employee::query()->where('user_id', $user->id)->first()
                ?? throw ValidationException::withMessages(['month' => __('Ditt konto är inte kopplat till någon anställd. Be din arbetsgivare koppla det.')]);

            if (! $employee->isEmployedToday()) {
                throw ValidationException::withMessages(['month' => __('Din anställning har upphört, så du kan inte skicka in något här längre. Kontakta din tidigare arbetsgivare.')]);
            }

            $payCommute = (bool) ($settings['mileage_pay_commute'] ?? false);
            $trips = VehicleTrip::query()
                ->with('vehicle')
                ->where('driver_user_id', $user->id)
                ->whereIn('status', [TripStatus::Draft->value, TripStatus::Rejected->value])
                ->whereNull('voucher_id')
                ->whereDate('trip_date', '<=', $monthStart->endOfMonth()->toDateString())
                ->where(fn ($query) => $query->whereDate('trip_date', '>=', $monthStart->toDateString())->orWhereNotNull('corrects_trip_id'))
                ->orderBy('trip_date')
                ->lockForUpdate()
                ->get()
                ->filter(fn (VehicleTrip $trip): bool => $trip->kind === TripKind::Business || ($trip->kind === TripKind::Commute && $payCommute))
                ->values();

            if ($trips->isEmpty()) {
                throw ValidationException::withMessages(['month' => __('Det finns inga resor att skicka in för :month. Lägg in resorna med datum och sträcka först.', ['month' => self::monthName($monthStart)])]);
            }

            $fuelBenefit = $this->hasFuelBenefit($employee, $monthStart);
            $cars = CompanyCarBenefits::for($company);
            $claims = [];

            foreach ($trips->groupBy(fn (VehicleTrip $trip): string => $trip->vehicle->kind->value) as $kind => $group) {
                $claims[] = $this->claim($user, $company, $employee, $monthStart, VehicleKind::from((string) $kind), $group->values(), $paidAllFuel, $fuelBenefit, $cars, $settings);
            }

            return $claims;
        }));
    }

    /**
     * @param  Collection<int, VehicleTrip>  $trips
     * @param  array<string, mixed>  $settings
     */
    private function claim(User $user, Company $company, Employee $employee, CarbonImmutable $monthStart, VehicleKind $kind, Collection $trips, bool $paidAllFuel, bool $fuelBenefit, CompanyCarBenefits $cars, array $settings): Expense
    {
        $notes = [];
        $lines = [];
        $groups = [];

        if ($kind === VehicleKind::CompanyCar && ! $paidAllFuel) {
            $notes[] = __('Skattefri milersättning för förmånsbil kräver att du betalat allt drivmedel själv. Resorna kan bara ersättas som lön.');
        } elseif ($kind === VehicleKind::CompanyCar && $fuelBenefit) {
            $notes[] = __('Skattefri milersättning för förmånsbil kräver att du betalat allt drivmedel själv. Företaget har betalat drivmedel för bilen i månaden, så resorna kan bara ersättas som lön.');
        }

        foreach ($trips as $trip) {
            $fuel = $cars->fuel($trip->vehicle, $trip->trip_date);
            $code = MileagePayCode::forTrip($kind, $fuel, $trip->kind);
            $taxFreeAllowed = $kind === VehicleKind::PrivateCar || ($paidAllFuel && ! $fuelBenefit);

            if ($code !== MileagePayCode::TAXABLE && $trip->charged_at_workplace && ! MileagePayCode::workplaceChargingAllowed($fuel, $trip->trip_date)) {
                $taxFreeAllowed = false;
                $notes[] = __('Resan :date laddades på arbetsplatsen före den 1 juli 2026 och kan bara ersättas som lön.', ['date' => $trip->trip_date->toDateString()]);
            }

            // The rule the amount is measured against, also for a trip that can only be paid as pay.
            $rule = MileagePayCode::RULES[MileagePayCode::forTrip($kind, $fuel, TripKind::Business)];
            $code = $code !== MileagePayCode::TAXABLE && ! $taxFreeAllowed ? MileagePayCode::TAXABLE : $code;
            $year = $trip->trip_date->year;
            $key = "{$code}|{$rule}|{$year}";
            $groups[$key] ??= ['pay_code' => $code, 'rule' => $rule, 'year' => $year, 'hm' => 0, 'trips' => 0];
            $groups[$key]['hm'] += $trip->distance_hm;
            $groups[$key]['trips']++;
            $lines[] = [
                'trip_id' => $trip->id,
                'date' => $trip->trip_date->toDateString(),
                'kind' => $trip->kind->value,
                'vehicle_id' => $trip->vehicle_id,
                'hm' => $trip->distance_hm,
                'pay_code' => $code,
                'corrects_trip_id' => $trip->corrects_trip_id,
            ];
        }

        $companyRate = self::rate($settings[$kind === VehicleKind::PrivateCar ? 'mileage_own_car_rate' : 'mileage_company_car_rate'] ?? null);
        $pending = false;

        foreach ($groups as $key => $group) {
            if ($group['hm'] <= 0) {
                throw ValidationException::withMessages(['month' => __('Rättelserna är större än resorna i månaden. Kontakta den som sköter lönerna.')]);
            }

            try {
                $schablon = (int) ((array) RuleSet::for(CarbonImmutable::create($group['year'], 12, 31) ?? CarbonImmutable::today())->get($group['rule']))['ore'];
                $version = RuleSet::for(CarbonImmutable::create($group['year'], 12, 31) ?? CarbonImmutable::today())->version($group['rule'])->id;
            } catch (MissingPayrollRuleException) {
                $schablon = null;
                $version = null;
                $pending = true;
            }

            $rate = $companyRate ?? $schablon;
            $groups[$key] = [
                ...$group,
                'rate_ore' => $companyRate,
                'schablon_ore' => $schablon,
                'rule_version_id' => $version,
                'amount_ore' => $rate === null ? null : Distance::amountOre($group['hm'], $rate),
            ];
        }

        if ($pending) {
            $notes[] = __('Skatteverket har inte publicerat beloppet per mil för året än. Beloppet räknas när det är publicerat, och lönen med resorna kan inte göras förrän dess.');
        }

        $hm = array_sum(array_column($groups, 'hm'));
        $amount = array_sum(array_map(fn (array $group): int => (int) ($group['amount_ore'] ?? 0), $groups));
        $type = $kind === VehicleKind::PrivateCar ? 'MILEAGE_OWN_CAR' : 'MILEAGE_COMPANY_CAR';

        $expense = Expense::create([
            'employee_id' => $employee->id,
            'type' => $type,
            'date' => $monthStart->endOfMonth()->min(CarbonImmutable::today()),
            'amount_ore' => $amount,
            'vat_ore' => 0,
            'description' => self::description($monthStart, $hm),
            'trip_data' => [
                'month' => $monthStart->format('Y-m'),
                'vehicle_kind' => $kind->value,
                'paid_all_fuel' => $kind === VehicleKind::CompanyCar ? $paidAllFuel : null,
                'groups' => array_values($groups),
                'lines' => $lines,
                'notes' => array_values(array_unique($notes)),
                'rate_pending' => $pending,
            ],
            'status' => 'SUBMITTED',
            'requested_by' => $user->id,
        ]);

        VehicleTrip::query()->whereIn('id', $trips->pluck('id'))->update([
            'status' => TripStatus::Submitted->value,
            'expense_id' => $expense->id,
            'all_fuel_paid_by_driver' => $kind === VehicleKind::CompanyCar ? $paidAllFuel : null,
            'updated_at' => now(),
        ]);

        Audit::record('mileage.claim_submitted', $expense, ['employee_id' => $employee->id, 'type' => $type, 'trips' => $trips->count(), 'distance_hm' => $hm, 'amount_ore' => $amount], $company, $user);

        return $expense;
    }

    /**
     * A fuel benefit for the employee in a run for the month: the company paid fuel, so no tax-free
     * company-car mileage (KJ-R3). TODO(legal-verify): a fuel card that is not yet in a run is not seen here; the approver checks.
     */
    private function hasFuelBenefit(Employee $employee, CarbonImmutable $monthStart): bool
    {
        return PayrollInput::query()
            ->join('payroll_runs', 'payroll_runs.id', '=', 'payroll_inputs.payroll_run_id')
            ->join('pay_codes', 'pay_codes.id', '=', 'payroll_inputs.pay_code_id')
            ->where('payroll_inputs.employee_id', $employee->id)
            ->where('pay_codes.code', FuelBenefit::CODE)
            ->where('payroll_runs.period', $monthStart->format('Y-m'))
            ->exists();
    }

    /** The company's own rate in öre per mil, or null for Skatteverket's schablon. */
    public static function rate(mixed $setting): ?int
    {
        if (blank($setting)) {
            return null;
        }

        try {
            return Money::parse((string) $setting)->ore;
        } catch (InvalidArgumentException) {
            return null;
        }
    }

    /** "Milersättning oktober, 17,25 mil" */
    public static function description(CarbonImmutable $month, int $hm): string
    {
        return __('Milersättning :month, :distance', ['month' => self::MONTHS[$month->month], 'distance' => Distance::formatMil($hm)]);
    }

    public static function monthName(CarbonImmutable $month): string
    {
        return self::MONTHS[$month->month].' '.$month->year;
    }
}
