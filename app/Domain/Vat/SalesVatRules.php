<?php

declare(strict_types=1);

namespace App\Domain\Vat;

use App\Models\Account;

/**
 * Which VAT a sales account expects, so the editors can warn when a row's
 * VAT rate or a voucher's output VAT does not fit the revenue account (#62).
 * The VAT return itself does not depend on this: a sale on a box 05 account
 * without any output VAT on its voucher is reported in box 42 (VatReturn).
 *
 * The rates of 3001–3003, 3004 and 3401–3403 follow their BAS 2026 names
 * ("Försäljning inom Sverige, 25 % moms", "Egna uttag momspliktiga, 25 %"
 * and so on); any other account that feeds box 05, 06 or 08 takes 25, 12 or
 * 6 %, and one that feeds box 42 takes 0 %. Withdrawals expect their VAT on
 * 2612/2622/2632 and letting on 2613/2623/2633.
 */
final class SalesVatRules
{
    /** BAS 2026 sales and withdrawal accounts named after their rate. */
    public const array RATES = [3001 => 2500, 3002 => 1200, 3003 => 600, 3004 => 0, 3401 => 2500, 3402 => 1200, 3403 => 600];

    /** The output VAT accounts (group account and sales account) for each rate. */
    public const array OUTPUT_VAT = [2500 => [2610, 2611], 1200 => [2620, 2621], 600 => [2630, 2631]];

    /** Output VAT on own withdrawals (box 06) and on letting (box 08), BAS 2026. */
    public const array OUTPUT_VAT_BY_BOX = [6 => [2500 => [2612], 1200 => [2622], 600 => [2632]], 8 => [2500 => [2613], 1200 => [2623], 600 => [2633]]];

    /**
     * The VAT rates (basis points) a sale on the account may carry, or null
     * when the account says nothing about VAT.
     *
     * @return ?list<int>
     */
    public static function rates(int $account, ?int $override = null): ?array
    {
        if ($override === null && array_key_exists($account, self::RATES)) {
            return [self::RATES[$account]];
        }

        return match (VatBoxes::boxFor($account, $override)) {
            VatBoxes::TAXABLE_SALES, 6, 8 => [2500, 1200, 600],
            VatBoxes::EXEMPT_SALES => [0],
            default => null,
        };
    }

    /**
     * The output VAT accounts of which a voucher with a sale on the account
     * needs at least one, or null when the account needs none.
     *
     * @return ?list<int>
     */
    public static function outputVat(int $account, ?int $override = null): ?array
    {
        $rates = self::rates($account, $override);

        if ($rates === null || $rates === [0]) {
            return null;
        }

        $byRate = self::OUTPUT_VAT_BY_BOX[VatBoxes::boxFor($account, $override)] ?? self::OUTPUT_VAT;

        return array_merge(...array_map(fn (int $rate): array => $byRate[$rate], $rates));
    }

    /**
     * The rules for the editors' browser code, per account number.
     *
     * @param  iterable<Account>  $accounts
     * @return array<int, array{rates: list<int>, vat: ?list<int>}>
     */
    public static function forAccounts(iterable $accounts): array
    {
        $rules = [];

        foreach ($accounts as $account) {
            $rates = self::rates($account->number, $account->vat_box);

            if ($rates !== null) {
                $rules[$account->number] = ['rates' => $rates, 'vat' => self::outputVat($account->number, $account->vat_box)];
            }
        }

        return $rules;
    }

    /** A Swedish warning when the rate does not fit the account, otherwise null. */
    public static function mismatch(int $account, int $rate, ?int $override = null): ?string
    {
        $rates = self::rates($account, $override);

        if ($rates === null || in_array($rate, $rates, true)) {
            return null;
        }

        return __('Konto :account är för försäljning med :expected moms, men momssatsen är :rate. Momsdeklarationen följer momsen på verifikationen, så kontrollera att kontot är rätt.', [
            'account' => $account,
            'expected' => implode(', ', array_map(fn (int $bp): string => (intdiv($bp, 100)).' %', $rates)),
            'rate' => intdiv($rate, 100).' %',
        ]);
    }
}
