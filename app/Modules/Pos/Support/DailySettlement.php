<?php

declare(strict_types=1);

namespace App\Modules\Pos\Support;

use App\Modules\Pos\Domain\PaymentMethod;
use App\Modules\Pos\PosModule;
use App\Support\Money\Money;

/**
 * The rows of a day's till voucher (ADR-042), from the day's totals and the
 * company's accounts:
 *
 * - credit the sales account per VAT rate with the net, and the output VAT
 *   account per rate with the VAT (refunds net into the same day);
 * - debit each payment method's account with what was paid that way;
 * - credit tips (included in card payments) to the tips account;
 * - debit card fees to the fee account against the card settlement account;
 * - a difference of at most one krona per sale goes to öresutjämning.
 *
 * A bigger difference gives no rows, only the difference, so nothing is
 * proposed that does not add up. TODO(legal-verify): the defaults and the
 * VAT treatment of card fees (normally an exempt financial service; the
 * provider may invoice from abroad) need an accountant's check.
 */
final class DailySettlement
{
    /**
     * @param  array<int, array{net: int, vat: int}>  $vatTotals
     * @param  array<string, int>  $payments
     * @param  array<string, mixed>  $settings  PosModule settings
     * @return array{rows: list<array{account: int, debit_ore: int, credit_ore: int, description: string}>, balanced: bool, difference: int, errors: list<string>}
     */
    public static function build(array $vatTotals, array $payments, int $tipsOre, int $feesOre, int $saleCount, array $settings): array
    {
        $rows = [];
        $errors = [];

        ksort($vatTotals);

        foreach (array_reverse($vatTotals, true) as $rate => $sums) {
            $percent = intdiv($rate, 100);
            $sales = PosModule::account($settings, "account_sales_{$rate}");

            if ($sales === null) {
                $errors[] = __('Det finns inget försäljningskonto för momssatsen :rate %.', ['rate' => $percent]);

                continue;
            }

            self::add($rows, $sales, -$sums['net'], __('Försäljning :rate % moms', ['rate' => $percent]));

            if ($sums['vat'] !== 0) {
                $vat = PosModule::account($settings, "account_vat_{$rate}");

                if ($vat === null) {
                    $errors[] = __('Det finns inget momskonto för momssatsen :rate %.', ['rate' => $percent]);

                    continue;
                }

                self::add($rows, $vat, -$sums['vat'], __('Utgående moms :rate %', ['rate' => $percent]));
            }
        }

        foreach (PaymentMethod::cases() as $method) {
            $amount = $payments[$method->value] ?? 0;

            if ($amount !== 0) {
                self::add($rows, (int) PosModule::account($settings, "account_payment_{$method->value}"), $amount, $method->label());
            }
        }

        if ($tipsOre !== 0) {
            self::add($rows, (int) PosModule::account($settings, 'account_tips'), -$tipsOre, __('Dricks'));
        }

        if ($feesOre !== 0) {
            self::add($rows, (int) PosModule::account($settings, 'account_fees'), $feesOre, __('Avgifter för kortbetalningar'));
            self::add($rows, (int) PosModule::account($settings, 'account_fee_settlement'), -$feesOre, __('Avgifter dragna av kassaleverantören'));
        }

        $difference = array_sum(array_column($rows, 'debit_ore')) - array_sum(array_column($rows, 'credit_ore'));

        if ($difference !== 0 && abs($difference) <= 100 * max(1, $saleCount)) {
            self::add($rows, (int) PosModule::account($settings, 'account_rounding'), -$difference, __('Öresutjämning'));
            $difference = 0;
        }

        if ($difference !== 0) {
            $errors[] = __('Kassans belopp går inte ihop: betalningarna och försäljningen skiljer :amount. Kontrollera dagens försäljning i kassan.', ['amount' => Money::ofOre(abs($difference))->format()]);
        }

        return [
            'rows' => $errors === [] ? $rows : [],
            'balanced' => $errors === [] && $rows !== [],
            'difference' => $difference,
            'errors' => $errors,
        ];
    }

    /**
     * Adds a row: a positive amount on the debit side, a negative one on the
     * credit side.
     *
     * @param  list<array{account: int, debit_ore: int, credit_ore: int, description: string}>  $rows
     */
    private static function add(array &$rows, int $account, int $amount, string $description): void
    {
        if ($amount === 0) {
            return;
        }

        $rows[] = [
            'account' => $account,
            'debit_ore' => $amount > 0 ? $amount : 0,
            'credit_ore' => $amount < 0 ? -$amount : 0,
            'description' => mb_substr($description, 0, 255),
        ];
    }
}
