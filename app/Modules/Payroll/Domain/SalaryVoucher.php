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

                $amount = $line->amount_ore->ore;
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
}
