<?php

declare(strict_types=1);

namespace App\Modules\Payroll\Domain;

use App\Models\Account;
use App\Modules\Payroll\Models\PayCode;
use App\Modules\Payroll\Models\Payslip;
use App\Modules\Payroll\Models\PayslipLine;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

/**
 * The rows of a salary voucher (PAYROLL_MODULE.md B.12) for a paid run:
 * each payslip line on its pay code's account (cost lines debit, with the
 * line's cost centre when it was priced from a time report on one, else
 * the employment's cost centre and project when the payroll setting
 * "Kostnadsställe och projekt från anställningen" and the pay code say so;
 * deductions after tax credit their account), withheld
 * tax credit 2710, employer contributions debit 7510 split over the cost
 * centres in proportion to each payslip's contribution basis on them
 * (ADR-037, gap 7; CostCenterShares) and credit 2730, and net pay credit the bank
 * account it was paid from. The voucher balances by construction: gross −
 * tax ± items after tax = net, and the contribution parts sum to the
 * contributions to the öre.
 */
final class SalaryVoucher
{
    public const int TAX = 2710;

    public const int CONTRIBUTIONS_COST = 7510;

    public const int CONTRIBUTIONS_LIABILITY = 2730;

    public const int BANK = 1930;

    public const int VACATION_LIABILITY_CHANGE = 7090;

    public const int VACATION_LIABILITY = 2920;

    public const int VACATION_CONTRIBUTIONS_CHANGE = 7519;

    public const int VACATION_CONTRIBUTIONS_LIABILITY = 2940;

    /**
     * @param  Collection<int, Payslip>  $payslips  with lines.costCenter, employment.costCenter and employment.project
     * @param  Collection<int, PayCode>  $payCodes  by id
     * @param  Collection<int, Account>  $accounts  by id
     * @param  bool  $useEmploymentDimensions  the payroll setting "Kostnadsställe och projekt från anställningen"
     * @return list<array{account: int, amount: int, description?: ?string, cost_center?: ?string, project?: ?string}>
     */
    public static function rows(Collection $payslips, Collection $payCodes, Collection $accounts, bool $useEmploymentDimensions = true): array
    {
        $rows = [];
        $missing = [];
        $tax = 0;
        $contributions = 0;
        $contributionsByDimension = [];
        $net = 0;

        foreach ($payslips as $payslip) {
            $employmentCostCenter = $payslip->employment?->costCenter;
            $employmentProject = $payslip->employment?->project?->code;
            $costCenterOf = CostCenterShares::resolver($employmentCostCenter?->id, $useEmploymentDimensions, $payCodes);
            $codes = [CostCenterShares::key($employmentCostCenter?->id) => $employmentCostCenter?->code];

            foreach ($payslip->lines as $line) {
                /** @var PayslipLine $line */
                if ($line->costCenter !== null) {
                    $codes[CostCenterShares::key($line->costCenter->id)] = $line->costCenter->code;
                }
            }

            foreach ($payslip->lines as $line) {
                /** @var PayslipLine $line */
                $payCode = $payCodes->get($line->pay_code_id);

                if (! $payCode instanceof PayCode) {
                    $missing[] = (string) $line->pay_code_id;

                    continue;
                }

                // A benefit's value is not a cost of its own: what it is worth was booked when it was bought.
                // Only the contributions on it cost, and those are in the employer contributions below.
                if ((bool) ($line->explanation['benefit'] ?? false)) {
                    continue;
                }

                $amount = $line->amount_ore->ore;
                $costCenter = $codes[CostCenterShares::key($costCenterOf($line))] ?? null;
                $project = CostCenterShares::usesEmployment($useEmploymentDimensions, $payCode) ? $employmentProject : null;

                // Pension and the special payroll tax on it: the employer's cost against its liability.
                if ($line->tax_effect === 'EMPLOYER') {
                    $debit = $payCode->debit_account_id === null ? null : $accounts->get($payCode->debit_account_id);
                    $credit = $payCode->credit_account_id === null ? null : $accounts->get($payCode->credit_account_id);

                    if ($debit === null || $credit === null) {
                        $missing[] = $payCode->name_sv;

                        continue;
                    }

                    $rows[] = ['account' => $debit->number, 'amount' => $amount, 'description' => $line->description, 'cost_center' => $costCenter, 'project' => $project];
                    $rows[] = ['account' => $credit->number, 'amount' => -$amount, 'description' => $line->description, 'cost_center' => null, 'project' => null];

                    continue;
                }

                $isNet = $line->tax_effect === 'NET';
                $accountId = $isNet && $amount < 0 ? $payCode->credit_account_id : ($payCode->debit_account_id ?? $payCode->credit_account_id);
                $account = $accountId === null ? null : $accounts->get($accountId);

                if ($account === null) {
                    $missing[] = $payCode->name_sv;

                    continue;
                }

                $rows[] = ['account' => $account->number, 'amount' => $amount, 'description' => $line->description, 'cost_center' => $isNet ? null : $costCenter, 'project' => $isNet ? null : $project];
            }

            $tax += $payslip->tax_ore->ore;
            $contributions += $payslip->employer_contribution_ore->ore;
            $net += $payslip->net_ore->ore;
            $contributionProject = $useEmploymentDimensions ? $employmentProject : null;
            $fallback = $useEmploymentDimensions ? $employmentCostCenter?->id : null;

            foreach (CostCenterShares::contributions($payslip, $payCodes, $costCenterOf, $fallback) as $key => $part) {
                $code = $codes[(string) $key] ?? null;
                $dimension = $code.'|'.$contributionProject;
                $contributionsByDimension[$dimension] ??= ['cost_center' => $code, 'project' => $contributionProject, 'amount' => 0];
                $contributionsByDimension[$dimension]['amount'] += $part;
            }
        }

        if ($missing !== []) {
            throw ValidationException::withMessages(['run' => [__('Lönearterna :codes saknar konto. Lägg till kontona under Lön → Kom igång.', ['codes' => implode(', ', array_unique($missing))])]]);
        }

        return [
            ...self::summarise($rows),
            ...self::contributionRows($contributionsByDimension, $contributions),
            ['account' => self::TAX, 'amount' => -$tax, 'description' => __('Personalskatt')],
            ['account' => self::CONTRIBUTIONS_LIABILITY, 'amount' => -$contributions, 'description' => __('Arbetsgivaravgifter att betala')],
            ['account' => self::BANK, 'amount' => -$net, 'description' => __('Utbetalda nettolöner')],
        ];
    }

    /**
     * One row per account, cost centre and project: the voucher is
     * räkenskapsinformation that bookkeepers and auditors read, so it shows
     * totals, not what each employee earned or was paid in sick pay (GDPR
     * art. 5.1 c). The payslips themselves keep the detail.
     *
     * @param  list<array{account: int, amount: int, description: ?string, cost_center: ?string, project: ?string}>  $rows
     * @return list<array{account: int, amount: int, description: string, cost_center: ?string, project: ?string}>
     */
    private static function summarise(array $rows): array
    {
        $totals = [];

        foreach ($rows as $row) {
            $key = $row['account'].'|'.$row['cost_center'].'|'.$row['project'];
            $totals[$key] ??= ['account' => $row['account'], 'amount' => 0, 'description' => __('Löner enligt lönekörningen'), 'cost_center' => $row['cost_center'], 'project' => $row['project']];
            $totals[$key]['amount'] += $row['amount'];
        }

        return array_values(array_filter($totals, fn (array $row): bool => $row['amount'] !== 0));
    }

    /**
     * Employer contributions on 7510, one row per cost centre and project
     * (by code, none last). Without any payslip, one row of the total
     * as before.
     *
     * @param  array<string, array{cost_center: ?string, project: ?string, amount: int}>  $byDimension
     * @return list<array{account: int, amount: int, description: string, cost_center: ?string, project: ?string}>
     */
    private static function contributionRows(array $byDimension, int $total): array
    {
        if ($byDimension === []) {
            return [['account' => self::CONTRIBUTIONS_COST, 'amount' => $total, 'description' => __('Arbetsgivaravgifter'), 'cost_center' => null, 'project' => null]];
        }

        ksort($byDimension, SORT_STRING);

        return array_values(array_map(fn (array $part): array => [
            'account' => self::CONTRIBUTIONS_COST,
            'amount' => $part['amount'],
            'description' => __('Arbetsgivaravgifter'),
            'cost_center' => $part['cost_center'],
            'project' => $part['project'],
        ], $byDimension));
    }

    /**
     * The change in the vacation liability since the last booking: an
     * increase is a cost (7090, 7519) against the liability (2920, 2940), a
     * decrease the reverse.
     *
     * @return list<array{account: int, amount: int, description: string}>
     */
    public static function vacationLiabilityRows(int $liabilityChange, int $contributionsChange): array
    {
        return array_values(array_filter([
            ['account' => self::VACATION_LIABILITY_CHANGE, 'amount' => $liabilityChange, 'description' => __('Förändring av semesterlöneskuld')],
            ['account' => self::VACATION_LIABILITY, 'amount' => -$liabilityChange, 'description' => __('Semesterlöneskuld')],
            ['account' => self::VACATION_CONTRIBUTIONS_CHANGE, 'amount' => $contributionsChange, 'description' => __('Sociala avgifter på semesterlöneskuld')],
            ['account' => self::VACATION_CONTRIBUTIONS_LIABILITY, 'amount' => -$contributionsChange, 'description' => __('Upplupna sociala avgifter på semesterlöneskuld')],
        ], fn (array $row): bool => $row['amount'] !== 0));
    }
}
