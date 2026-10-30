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
 * employment's cost centre; deductions after tax credit their account),
 * withheld tax credit 2710, employer contributions debit 7510 and credit
 * 2730, and net pay credit the bank account it was paid from. The voucher
 * balances by construction: gross − tax ± items after tax = net.
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
     * @param  Collection<int, Payslip>  $payslips  with lines and employment.costCenter
     * @param  Collection<int, PayCode>  $payCodes  by id
     * @param  Collection<int, Account>  $accounts  by id
     * @return list<array{account: int, amount: int, description?: ?string, cost_center?: ?string}>
     */
    public static function rows(Collection $payslips, Collection $payCodes, Collection $accounts): array
    {
        $rows = [];
        $missing = [];
        $tax = 0;
        $contributions = 0;
        $net = 0;

        foreach ($payslips as $payslip) {
            $costCenter = $payslip->employment?->costCenter?->code;

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

                // Pension and the special payroll tax on it: the employer's cost against its liability.
                if ($line->tax_effect === 'EMPLOYER') {
                    $debit = $payCode->debit_account_id === null ? null : $accounts->get($payCode->debit_account_id);
                    $credit = $payCode->credit_account_id === null ? null : $accounts->get($payCode->credit_account_id);

                    if ($debit === null || $credit === null) {
                        $missing[] = $payCode->name_sv;

                        continue;
                    }

                    $rows[] = ['account' => $debit->number, 'amount' => $amount, 'description' => $line->description, 'cost_center' => $costCenter];
                    $rows[] = ['account' => $credit->number, 'amount' => -$amount, 'description' => $line->description, 'cost_center' => null];

                    continue;
                }

                $isNet = $line->tax_effect === 'NET';
                $accountId = $isNet && $amount < 0 ? $payCode->credit_account_id : ($payCode->debit_account_id ?? $payCode->credit_account_id);
                $account = $accountId === null ? null : $accounts->get($accountId);

                if ($account === null) {
                    $missing[] = $payCode->name_sv;

                    continue;
                }

                $rows[] = ['account' => $account->number, 'amount' => $amount, 'description' => $line->description, 'cost_center' => $isNet ? null : $costCenter];
            }

            $tax += $payslip->tax_ore->ore;
            $contributions += $payslip->employer_contribution_ore->ore;
            $net += $payslip->net_ore->ore;
        }

        if ($missing !== []) {
            throw ValidationException::withMessages(['run' => [__('Lönearterna :codes saknar konto. Lägg till kontona under Lön → Kom igång.', ['codes' => implode(', ', array_unique($missing))])]]);
        }

        return [
            ...$rows,
            ['account' => self::CONTRIBUTIONS_COST, 'amount' => $contributions, 'description' => __('Arbetsgivaravgifter')],
            ['account' => self::TAX, 'amount' => -$tax, 'description' => __('Personalskatt')],
            ['account' => self::CONTRIBUTIONS_LIABILITY, 'amount' => -$contributions, 'description' => __('Arbetsgivaravgifter att betala')],
            ['account' => self::BANK, 'amount' => -$net, 'description' => __('Utbetalda nettolöner')],
        ];
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
