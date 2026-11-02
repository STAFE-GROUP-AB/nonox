<?php

declare(strict_types=1);

namespace App\Modules\Payroll\Support\Agi;

use App\Modules\Payroll\Domain\Engine\PayslipLineResult;
use App\Modules\Payroll\Models\Employee;
use App\Modules\Payroll\Models\PayCode;
use App\Modules\Payroll\Models\PayrollRun;
use App\Modules\Payroll\Models\Payslip;
use App\Modules\Payroll\Models\PayslipLine;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

/**
 * What a company declares for one payment month (PAYROLL_MODULE.md B.13):
 * every approved run paid in the month, summed per employee into an
 * individuppgift, and the totals for the huvuduppgift. Runs the current
 * company's scope.
 *
 * Amounts are reported in whole kronor, dropping the öre.
 * TODO(legal-verify): Skatteverket's rounding of amounts with öre, and
 * whether the summed contributions may differ from Skatteverket's own
 * calculation per individual by the öre dropped.
 */
final class AgiDeclaration
{
    /** Runs whose payslips are fixed and paid out in the month. */
    public const array DECLARED_STATUSES = [PayrollRun::APPROVED, PayrollRun::PAID, PayrollRun::POSTED];

    private const array CAR_BENEFIT = ['BILFORMAN'];

    private const array FUEL_BENEFIT = ['DRIVMEDELSFORMAN'];

    private const array MILEAGE = ['MILERSATTNING'];

    private const array PER_DIEM = ['TRAKTAMENTE_HEL', 'TRAKTAMENTE_HALV', 'TRAKTAMENTE_NATT'];

    private const int ORE_PER_KRONA = 100;

    /**
     * @param  array<int, AgiIndividual>  $individuals  by employee id
     * @param  list<int>  $runIds
     */
    private function __construct(
        public readonly string $period,
        public readonly array $individuals,
        public readonly int $taxOre,
        public readonly int $contributionsOre,
        public readonly array $runIds,
        public readonly int $regionalSupportBasisOre = 0,
        public readonly int $regionalSupportOre = 0,
    ) {}

    /**
     * @throws ValidationException when a run paid in the month is not approved, or an amount would be negative
     */
    public static function for(string $period): self
    {
        $month = CarbonImmutable::parse("{$period}-01");
        $runs = PayrollRun::query()
            ->whereBetween('payment_date', [$month->startOfMonth()->toDateString(), $month->endOfMonth()->toDateString()])
            ->get();

        $open = $runs->reject(fn (PayrollRun $run): bool => in_array($run->status, self::DECLARED_STATUSES, true));

        if ($open->isNotEmpty()) {
            throw ValidationException::withMessages(['period' => [__('Lönekörningen :title betalas ut i månaden men är inte attesterad. Attestera den eller ändra utbetalningsdagen först.', ['title' => $open->first()->title()])]]);
        }

        $payslips = Payslip::query()->with(['employee', 'lines'])->whereIn('payroll_run_id', $runs->pluck('id'))->get();
        $codes = PayCode::query()->pluck('code', 'id');
        $individuals = [];

        foreach ($payslips->groupBy('employee_id') as $employeePayslips) {
            $employee = $employeePayslips->first()?->employee;

            if (! $employee instanceof Employee) {
                continue;
            }

            $individual = self::individual($employee, $employeePayslips, $codes);

            if ($individual !== null) {
                $individuals[$individual->employeeId] = $individual;
            }
        }

        ksort($individuals);

        return new self(
            $period,
            $individuals,
            (int) $payslips->sum(fn (Payslip $payslip): int => $payslip->tax_ore->ore),
            (int) $payslips->sum(fn (Payslip $payslip): int => $payslip->employer_contribution_ore->ore),
            array_values($runs->pluck('id')->map(fn (mixed $id): int => (int) $id)->all()),
            // Rutor 471 and 476: the basis and the deduction for regional support (#78).
            (int) $payslips->sum(fn (Payslip $payslip): int => (int) ($payslip->contribution_explanation['regional_support']['basis_ore'] ?? 0)),
            (int) $payslips->sum(fn (Payslip $payslip): int => (int) ($payslip->contribution_explanation['regional_support']['deduction_ore'] ?? 0)),
        );
    }

    public function taxKronor(): int
    {
        return intdiv($this->taxOre, self::ORE_PER_KRONA);
    }

    public function contributionsKronor(): int
    {
        return intdiv($this->contributionsOre, self::ORE_PER_KRONA);
    }

    /** @return array{basis: int, deduction: int}|null the regional support in whole kronor, or null when none was claimed */
    public function regionalSupportKronor(): ?array
    {
        if ($this->regionalSupportBasisOre <= 0) {
            return null;
        }

        return ['basis' => intdiv($this->regionalSupportBasisOre, self::ORE_PER_KRONA), 'deduction' => intdiv($this->regionalSupportOre, self::ORE_PER_KRONA)];
    }

    /**
     * The employee's own payment lowers the benefit values as one line among
     * the other benefits; when it pays for the car or fuel, the reduction is
     * moved there so no field goes below nothing.
     *
     * @param  array{cash: int, other: int, car: int, fuel: int}  $amounts
     * @return array{cash: int, other: int, car: int, fuel: int}
     */
    private static function ownPaymentAcrossBenefits(array $amounts): array
    {
        foreach (['car', 'fuel'] as $field) {
            if ($amounts['other'] >= 0) {
                break;
            }

            $moved = min($amounts[$field], -$amounts['other']);
            $amounts[$field] -= $moved;
            $amounts['other'] += $moved;
        }

        return $amounts;
    }

    /**
     * @param  Collection<int, Payslip>  $payslips
     * @param  Collection<int, string>  $codes
     */
    private static function individual(Employee $employee, Collection $payslips, Collection $codes): ?AgiIndividual
    {
        $amounts = ['cash' => 0, 'other' => 0, 'car' => 0, 'fuel' => 0];
        $free = $amounts;
        // Which benefits existed in the month, also when the employee's own payment takes the value to 0 (ruta 012/013, #79).
        $had = ['other' => false, 'car' => false];
        $mileage = false;
        $perDiem = false;
        // The payslip that took the year to the 1 000 kr threshold carried the earlier avgiftsfri pay into its basis (SAL 2 kap. 24 § fjärde stycket).
        $earlierFree = (int) $payslips->sum(fn (Payslip $payslip): int => (int) ($payslip->contribution_explanation['earlier_free_basis_ore'] ?? 0));
        $freeThisMonth = 0;

        foreach ($payslips as $payslip) {
            // Avgiftsfri pay (below the year's threshold) is declared outside the contribution basis, unless the threshold is passed this month.
            $isFree = array_key_exists('below_annual_threshold_ore', $payslip->contribution_explanation ?? []);

            if ($isFree && $earlierFree > 0) {
                $freeThisMonth += $payslip->contribution_basis_ore->ore;
                $isFree = false;
            }

            foreach ($payslip->lines as $line) {
                /** @var PayslipLine $line */
                $code = (string) ($codes[$line->pay_code_id] ?? '');
                $amount = $line->amount_ore->ore;

                // Ruta 050/051: a cross when a tax-free allowance was paid (#76).
                $mileage = $mileage || (in_array($code, self::MILEAGE, true) && $amount > 0);
                $perDiem = $perDiem || (in_array($code, self::PER_DIEM, true) && $amount > 0);

                if (! in_array($line->tax_effect, [PayslipLineResult::TAX_TABLE, PayslipLineResult::TAX_ONE_OFF], true)) {
                    continue;
                }

                $field = match (true) {
                    in_array($code, self::CAR_BENEFIT, true) => 'car',
                    in_array($code, self::FUEL_BENEFIT, true) => 'fuel',
                    ($line->explanation['benefit'] ?? false) === true => 'other',
                    default => 'cash',
                };

                if ($isFree) {
                    $free[$field] += $amount;

                    continue;
                }

                if (isset($had[$field]) && $amount > 0) {
                    $had[$field] = true;
                }

                $amounts[$field] += $amount;
            }
        }

        // Ruta 010: what earlier months declared as avgiftsfri is now contribution basis, added to 011 and stated in 010.
        // TODO(legal-verify): earlier avgiftsfri benefits are added to 011 as well, as Skatteverket's example only shows cash.
        $earlierMonths = max(0, $earlierFree - $freeThisMonth);
        $amounts['cash'] += $earlierMonths;
        $amounts = self::ownPaymentAcrossBenefits($amounts);
        $free = self::ownPaymentAcrossBenefits($free);
        $tax = (int) $payslips->sum(fn (Payslip $payslip): int => $payslip->tax_ore->ore);

        if (min($amounts) < 0 || min($free) < 0 || $tax < 0) {
            throw ValidationException::withMessages(['period' => [__('Beloppen för :name blir negativa för månaden. Arbetsgivardeklarationen tar bara positiva belopp; rätta en tidigare månad i stället.', ['name' => "{$employee->first_name} {$employee->last_name}"])]]);
        }

        if (array_sum($amounts) === 0 && array_sum($free) === 0 && $tax === 0 && ! $mileage && ! $perDiem) {
            return null;
        }

        // Ruta 275 needs ruta 112, verksamhetens art (AUD-LON-05, #73).
        if ($employee->tax_residence === 'NON_RESIDENT_A_SINK' && blank($employee->activity_type)) {
            throw ValidationException::withMessages(['period' => [__('Verksamhetens art saknas för :name, som beskattas med A-SINK. Skatteverket vill ha den i ruta 112. Fyll i den under Lön → Anställda.', ['name' => "{$employee->first_name} {$employee->last_name}"])]]);
        }

        return new AgiIndividual(
            employeeId: $employee->id,
            specificationNumber: max(1, (int) $employee->employee_number),
            cashKronor: intdiv($amounts['cash'], self::ORE_PER_KRONA),
            otherBenefitsKronor: intdiv($amounts['other'], self::ORE_PER_KRONA),
            carBenefitKronor: intdiv($amounts['car'], self::ORE_PER_KRONA),
            fuelBenefitKronor: intdiv($amounts['fuel'], self::ORE_PER_KRONA),
            taxKronor: intdiv($tax, self::ORE_PER_KRONA),
            taxForm: match ($employee->tax_residence) {
                'NON_RESIDENT_SINK' => AgiIndividual::TAX_SINK,
                'NON_RESIDENT_A_SINK' => AgiIndividual::TAX_A_SINK,
                default => AgiIndividual::TAX_PRELIMINARY,
            },
            mileage: $mileage,
            perDiem: $perDiem,
            freeCashKronor: intdiv($free['cash'], self::ORE_PER_KRONA),
            freeOtherBenefitsKronor: intdiv($free['other'], self::ORE_PER_KRONA),
            freeCarBenefitKronor: intdiv($free['car'], self::ORE_PER_KRONA),
            freeFuelBenefitKronor: intdiv($free['fuel'], self::ORE_PER_KRONA),
            earlierFreeKronor: intdiv($earlierMonths, self::ORE_PER_KRONA),
            activityType: $employee->tax_residence === 'NON_RESIDENT_A_SINK' ? $employee->activity_type : null,
            hadOtherBenefit: $had['other'],
            hadCarBenefit: $had['car'],
        );
    }
}
