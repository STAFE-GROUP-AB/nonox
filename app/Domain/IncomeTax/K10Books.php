<?php

declare(strict_types=1);

namespace App\Domain\IncomeTax;

use App\Domain\Accounting\VoucherStatus;
use App\Models\Company;
use App\Models\FiscalYear;
use App\Models\VoucherRow;
use App\Modules\ModuleManager;
use App\Modules\Payroll\Domain\Engine\PayslipLineResult;
use App\Modules\Payroll\Models\PayrollRun;
use App\Modules\Payroll\Models\PayslipLine;
use App\Modules\Payroll\PayrollModule;
use Carbon\CarbonImmutable;

/**
 * What a company's books can tell its owners' K10 for one inkomstår
 * (ADR-062), as proposals a person corrects:
 *
 * - the löneunderlag (IL 57 kap. 17 §): kontant ersättning taxed as tjänst,
 *   paid in the year before the inkomstår. From payroll when the payroll
 *   module has runs paid that year: taxable lines that are neither a benefit
 *   nor a taxable part of a kostnadsersättning, by payment date. Otherwise
 *   from the salary accounts of BAS 2026 (7000–7018, 7030–7038, 7080–7089,
 *   7200–7218, 7220–7228, 7230–7238, 7280–7289), by voucher date,
 *   leaving out the accrual accounts (x019, x029, x039, 7090, 7290–7292)
 *   7240 Styrelsearvoden and BAS group 73 (kostnadsersättningar och
 *   förmåner). When both exist, payroll is proposed only when its runs
 *   cover the whole year (a payment in January) or the ledger has no pay;
 *   a difference between the two is always shown with both figures;
 * - an owner's own cash pay, from the owner's payroll employee;
 * - the year's dividend: credits on 2898 Outtagen vinstutdelning in the
 *   inkomstår (decided), with the payments (debits) for information.
 *
 * Two queries for the ledger and the fiscal years, two more with payroll.
 * Runs in the current company's scope.
 *
 * TODO(legal-verify): that the BAS accounts above hold only kontant
 * ersättning in the sense of 17 §; that taxable kostnadsersättningar
 * (7322, 7324, 7332; payroll ALLOWANCE_TAXABLE) are "kostnadsersättning"
 * left out by 17 §; that 731x kontanta extraersättningar may be left out;
 * and that a dividend can be disposed of on the date the decision is booked.
 * TODO(legal-verify): board fees (7240) are left out, as 17 § counts pay
 * to "arbetstagarna"; whether fees to board members who are employees, or
 * board fees taxed as tjänst, belong in the löneunderlag.
 */
final class K10Books
{
    /** Inclusive BAS ranges of kontant ersättning to employees. */
    public const array CASH_PAY_ACCOUNTS = [
        [7000, 7018], [7030, 7038], [7080, 7089],
        [7200, 7218], [7220, 7228], [7230, 7238], [7280, 7289],
    ];

    public const array LEADER_PAY_ACCOUNTS = [7220, 7228];

    public const int DIVIDEND_LIABILITY = 2898;

    public const int PUBLIC_GRANTS_FOR_STAFF = 3988;

    public const string SOURCE_PAYROLL = 'payroll';

    public const string SOURCE_LEDGER = 'ledger';

    /** Payroll pay codes that are not kontant ersättning under 17 §. */
    private const array NOT_CASH_CATEGORIES = ['BENEFIT', 'ALLOWANCE_TAXABLE'];

    /** Runs whose payslips are final. */
    private const array FINAL_RUNS = [PayrollRun::APPROVED, PayrollRun::PAID, PayrollRun::POSTED, 'REPORTED'];

    /**
     * @param  array<string, array<string, int>>  $ledgerPay  cash pay in öre by date, by kind ('all', 'leaders')
     * @param  array<int, array<string, int>>  $payrollPay  cash pay in öre by employee id and payment date
     */
    private function __construct(
        public readonly int $incomeYear,
        public readonly string $source,
        private readonly array $ledgerPay,
        private readonly array $payrollPay,
        private readonly int $dividendDecidedOre,
        private readonly int $dividendPaidOre,
        private readonly int $grantsOre,
        private readonly bool $previousYearInBooks,
        private readonly int $openPayrollRuns,
        private readonly ?string $firstPayrollDay = null,
    ) {}

    public static function for(Company $company, int $incomeYear): self
    {
        $previous = $incomeYear - 1;
        $from = "{$previous}-01-01";
        $to = "{$incomeYear}-12-31";
        $ranges = [...self::CASH_PAY_ACCOUNTS, [self::DIVIDEND_LIABILITY, self::DIVIDEND_LIABILITY], [self::PUBLIC_GRANTS_FOR_STAFF, self::PUBLIC_GRANTS_FOR_STAFF]];

        $rows = VoucherRow::query()
            ->join('vouchers', 'vouchers.id', '=', 'voucher_rows.voucher_id')
            ->join('accounts', 'accounts.id', '=', 'voucher_rows.account_id')
            ->where('vouchers.status', VoucherStatus::Posted->value)
            ->whereBetween('vouchers.voucher_date', [$from, $to])
            ->where(function ($query) use ($ranges): void {
                foreach ($ranges as [$low, $high]) {
                    $query->orWhereBetween('accounts.number', [$low, $high]);
                }
            })
            ->toBase()
            ->groupBy('accounts.number', 'vouchers.voucher_date')
            ->selectRaw('accounts.number AS number, vouchers.voucher_date AS day, SUM(voucher_rows.debit_ore) AS debit, SUM(voucher_rows.credit_ore) AS credit')
            ->get();

        $ledgerPay = ['all' => [], 'leaders' => []];
        $dividendDecided = 0;
        $dividendPaid = 0;
        $grants = 0;

        foreach ($rows as $row) {
            $number = (int) $row->number;
            $day = substr((string) $row->day, 0, 10);
            $year = (int) substr($day, 0, 4);
            $net = (int) $row->debit - (int) $row->credit;

            if ($number === self::DIVIDEND_LIABILITY) {
                if ($year === $incomeYear) {
                    $dividendDecided += (int) $row->credit;
                    $dividendPaid += (int) $row->debit;
                }

                continue;
            }

            if ($year !== $previous) {
                continue;
            }

            if ($number === self::PUBLIC_GRANTS_FOR_STAFF) {
                $grants -= $net;

                continue;
            }

            $ledgerPay['all'][$day] = ($ledgerPay['all'][$day] ?? 0) + $net;

            if ($number >= self::LEADER_PAY_ACCOUNTS[0] && $number <= self::LEADER_PAY_ACCOUNTS[1]) {
                $ledgerPay['leaders'][$day] = ($ledgerPay['leaders'][$day] ?? 0) + $net;
            }
        }

        // The books reach back to the start of the year before when a fiscal year starts on or before it.
        $previousYearInBooks = FiscalYear::query()->whereDate('start_date', '<=', $from)->exists();

        [$payrollPay, $openRuns] = app(ModuleManager::class)->isEnabled(PayrollModule::KEY, $company)
            ? self::payroll($previous)
            : [[], 0];

        $days = array_merge(...array_map(array_keys(...), array_values($payrollPay ?: [[]])));
        $firstPayrollDay = $days === [] ? null : min($days);
        // Payroll replaces the ledger only when its runs cover the whole year: a company that moved its payroll
        // into NONOX in the autumn still has the year's earlier pay on the salary accounts.
        $payrollCoversYear = $firstPayrollDay !== null && ($firstPayrollDay <= "{$previous}-01-31" || array_sum($ledgerPay['all']) === 0);

        return new self(
            $incomeYear,
            $payrollCoversYear ? self::SOURCE_PAYROLL : self::SOURCE_LEDGER,
            $ledgerPay,
            $payrollPay,
            $dividendDecided,
            $dividendPaid,
            max(0, $grants),
            $previousYearInBooks,
            $openRuns,
            $firstPayrollDay,
        );
    }

    public function previousYear(): int
    {
        return $this->incomeYear - 1;
    }

    /** The kontant ersättning to all employees paid in the year before, from a date (16 a §) or the whole year, in whole kronor. */
    public function companyPayKronor(?CarbonImmutable $from = null): int
    {
        return $this->source === self::SOURCE_PAYROLL ? ($this->payrollPayKronor($from) ?? 0) : $this->ledgerPayKronor($from);
    }

    /** The same from the payroll runs alone; null without payroll runs paid that year. */
    public function payrollPayKronor(?CarbonImmutable $from = null): ?int
    {
        if ($this->firstPayrollDay === null) {
            return null;
        }

        $start = $this->start($from);
        $total = 0;

        foreach ($this->payrollPay as $days) {
            $total += self::sumFrom($days, $start);
        }

        return self::kronor($total);
    }

    /** The same from the salary accounts alone. */
    public function ledgerPayKronor(?CarbonImmutable $from = null): int
    {
        return self::kronor(self::sumFrom($this->ledgerPay['all'], $this->start($from)));
    }

    /** The first payment date of a payroll run in the year before, or null. */
    public function firstPayrollDay(): ?string
    {
        return $this->firstPayrollDay;
    }

    /** One payroll employee's kontant ersättning in the year before, in whole kronor; null without payroll data. */
    public function employeePayKronor(?int $employeeId, ?CarbonImmutable $from = null): ?int
    {
        if ($employeeId === null || $this->firstPayrollDay === null) {
            return null;
        }

        return self::kronor(self::sumFrom($this->payrollPay[$employeeId] ?? [], $this->start($from)));
    }

    /** What the books hold on 7220–7228 Löner till företagsledare in the year before: a hint for the owner's own pay. */
    public function leaderPayKronor(): int
    {
        return self::kronor(array_sum($this->ledgerPay['leaders']));
    }

    public function dividendDecidedKronor(): int
    {
        return self::kronor($this->dividendDecidedOre);
    }

    public function dividendPaidKronor(): int
    {
        return self::kronor($this->dividendPaidOre);
    }

    public function grantsKronor(): int
    {
        return self::kronor($this->grantsOre);
    }

    /** @return list<string> */
    public function warnings(): array
    {
        $previous = $this->previousYear();
        $warnings = [];

        if (! $this->previousYearInBooks) {
            $warnings[] = __('Bokföringen för :year finns inte i NONOX, så löneunderlaget kan inte hämtas därifrån. Fyll i lönerna för :year själv.', ['year' => $previous]);
        }

        if ($this->source === self::SOURCE_LEDGER) {
            $warnings[] = __('Lönerna för :year är hämtade från de bokförda lönekontona, inte från lönekörningar. Det som ska räknas är kontant ersättning som betalats ut under :year. Lön som bokförts ett år och betalats ut ett annat, förmåner och kostnadsersättningar ska inte med. Kontrollera beloppet.', ['year' => $previous]);
        }

        $payroll = $this->payrollPayKronor();
        $ledger = $this->ledgerPayKronor();

        if ($payroll !== null && $payroll !== $ledger) {
            $warnings[] = __('Lönekörningarna i NONOX ger :payroll kr i kontant ersättning för :year och de bokförda lönekontona :ledger kr. Förslaget använder :chosen. Kontrollera vilket belopp som stämmer med det som betalats ut under året.', [
                'payroll' => number_format($payroll, 0, ',', ' '),
                'ledger' => number_format($ledger, 0, ',', ' '),
                'year' => $previous,
                'chosen' => $this->source === self::SOURCE_PAYROLL ? __('lönekörningarna') : __('lönekontona'),
            ]);
        }

        if ($this->firstPayrollDay !== null && $this->firstPayrollDay > "{$previous}-01-31") {
            $warnings[] = __('Lönekörningarna i NONOX börjar :date. Lön som betalats ut tidigare under :year finns inte i dem; kontrollera ägarens egen lön för hela året.', ['date' => $this->firstPayrollDay, 'year' => $previous]);
        }

        if ($this->openPayrollRuns > 0) {
            $warnings[] = __('Det finns :count lönekörningar med utbetalning :year som inte är attesterade. De är inte med i löneunderlaget.', ['count' => $this->openPayrollRuns, 'year' => $previous]);
        }

        if ($this->grantsOre > 0) {
            $warnings[] = __('Företaget har bokfört offentliga bidrag för personal (konto 3988) under :year. Lön som täcks av statliga bidrag för lönekostnader ska inte räknas med i löneunderlaget (IL 57 kap. 18 §). Fyll i den lönen under Lön som täcks av statliga bidrag.', ['year' => $previous]);
        }

        return $warnings;
    }

    /**
     * Cash pay per employee and payment date in the year before, and the
     * number of runs paid that year that are not yet final.
     *
     * @return array{0: array<int, array<string, int>>, 1: int}
     */
    private static function payroll(int $year): array
    {
        $from = "{$year}-01-01";
        $to = "{$year}-12-31";

        $rows = PayslipLine::query()
            ->join('payslips', 'payslips.id', '=', 'payslip_lines.payslip_id')
            ->join('payroll_runs', 'payroll_runs.id', '=', 'payslips.payroll_run_id')
            ->join('pay_codes', 'pay_codes.id', '=', 'payslip_lines.pay_code_id')
            ->whereBetween('payroll_runs.payment_date', [$from, $to])
            ->whereIn('payroll_runs.status', self::FINAL_RUNS)
            ->whereIn('payslip_lines.tax_effect', [PayslipLineResult::TAX_TABLE, PayslipLineResult::TAX_ONE_OFF])
            ->whereNotIn('pay_codes.category', self::NOT_CASH_CATEGORIES)
            ->whereRaw("COALESCE((payslip_lines.explanation->>'benefit')::boolean, false) = false")
            ->toBase()
            ->groupBy('payslips.employee_id', 'payroll_runs.payment_date')
            ->selectRaw('payslips.employee_id AS employee, payroll_runs.payment_date AS day, SUM(payslip_lines.amount_ore) AS amount')
            ->get();

        $pay = [];

        foreach ($rows as $row) {
            $pay[(int) $row->employee][substr((string) $row->day, 0, 10)] = (int) $row->amount;
        }

        $open = PayrollRun::query()->whereBetween('payment_date', [$from, $to])->whereNotIn('status', self::FINAL_RUNS)->count();

        return [$pay, $open];
    }

    private function start(?CarbonImmutable $from): string
    {
        $first = CarbonImmutable::parse("{$this->previousYear()}-01-01");

        return ($from !== null && $from->greaterThan($first) ? $from : $first)->toDateString();
    }

    /** @param  array<string, int>  $days */
    private static function sumFrom(array $days, string $from): int
    {
        $total = 0;

        foreach ($days as $day => $ore) {
            if ($day >= $from) {
                $total += $ore;
            }
        }

        return $total;
    }

    /** Whole kronor, dropping the öre as the arbetsgivardeklaration does. */
    private static function kronor(int $ore): int
    {
        return intdiv($ore, 100);
    }
}
