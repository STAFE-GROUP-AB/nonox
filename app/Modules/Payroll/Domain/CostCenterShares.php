<?php

declare(strict_types=1);

namespace App\Modules\Payroll\Domain;

use App\Modules\Payroll\Domain\Engine\PayslipLineResult;
use App\Modules\Payroll\Models\PayCode;
use App\Modules\Payroll\Models\Payslip;
use App\Modules\Payroll\Models\PayslipLine;
use Brick\Math\BigInteger;
use Closure;
use Illuminate\Support\Collection;
use InvalidArgumentException;

/**
 * A payslip's pay and employer contributions per cost centre (ADR-037,
 * gap 7). A line priced from a time report carries the time report's cost
 * centre; every other line the employment's, when the company books pay on
 * the employment's dimensions and the pay code allows it
 * (`use_employment_dimensions`), else none. The employer contributions are
 * split over the cost centres in proportion to the contribution basis on
 * each (the lines whose pay code bears contributions, at the value that
 * bears them), whole öre by the largest remainder, so the parts always add
 * up to the payslip's contributions and the salary voucher balances.
 *
 * Keys are cost centre ids as strings, '' for no cost centre.
 */
final class CostCenterShares
{
    /**
     * Which cost centre a line is booked on: its own (from a time report),
     * else the employment's when the company and the pay code use the
     * employment's dimensions.
     *
     * @param  Collection<int, PayCode>  $payCodes  by id
     * @return Closure(PayslipLine): ?int
     */
    public static function resolver(?int $employmentCostCenterId, bool $useEmploymentDimensions, Collection $payCodes): Closure
    {
        return fn (PayslipLine $line): ?int => $line->cost_center_id
            ?? (self::usesEmployment($useEmploymentDimensions, $payCodes->get($line->pay_code_id)) ? $employmentCostCenterId : null);
    }

    /** Whether a line without its own cost centre takes the employment's dimensions. */
    public static function usesEmployment(bool $useEmploymentDimensions, ?PayCode $payCode): bool
    {
        return $useEmploymentDimensions && $payCode?->use_employment_dimensions !== false;
    }

    /**
     * Gross pay per cost centre: the lines that make up the payslip's gross
     * (everything but items after tax and the employer's own costs).
     *
     * @param  Closure(PayslipLine): ?int  $costCenterOf
     * @return array<string, int>
     */
    public static function gross(Payslip $payslip, Closure $costCenterOf): array
    {
        $gross = [];

        foreach ($payslip->lines as $line) {
            /** @var PayslipLine $line */
            if (in_array($line->tax_effect, [PayslipLineResult::NET, PayslipLineResult::EMPLOYER], true)) {
                continue;
            }

            $key = self::key($costCenterOf($line));
            $gross[$key] = ($gross[$key] ?? 0) + $line->amount_ore->ore;
        }

        return $gross;
    }

    /**
     * The contribution basis per cost centre: the lines whose pay code bears
     * employer contributions, at the value that bears them (a fuel benefit's
     * market value, explanation.contribution_basis_ore), else their amount.
     *
     * @param  Collection<int, PayCode>  $payCodes  by id
     * @param  Closure(PayslipLine): ?int  $costCenterOf
     * @return array<string, int>
     */
    public static function basis(Payslip $payslip, Collection $payCodes, Closure $costCenterOf): array
    {
        $basis = [];

        foreach ($payslip->lines as $line) {
            /** @var PayslipLine $line */
            $payCode = $payCodes->get($line->pay_code_id);

            if (! $payCode instanceof PayCode || ! $payCode->is_contribution_basis) {
                continue;
            }

            $value = $line->explanation['contribution_basis_ore'] ?? null;
            $key = self::key($costCenterOf($line));
            $basis[$key] = ($basis[$key] ?? 0) + (is_int($value) ? $value : $line->amount_ore->ore);
        }

        return $basis;
    }

    /**
     * The payslip's employer contributions per cost centre, in proportion to
     * the positive contribution basis per cost centre. Without a positive
     * basis anywhere everything is on $fallback (the employment's cost
     * centre, or none).
     *
     * @param  Collection<int, PayCode>  $payCodes  by id
     * @param  Closure(PayslipLine): ?int  $costCenterOf
     * @return array<string, int>
     */
    public static function contributions(Payslip $payslip, Collection $payCodes, Closure $costCenterOf, ?int $fallback): array
    {
        $total = $payslip->employer_contribution_ore->ore;
        $weights = array_filter(self::basis($payslip, $payCodes, $costCenterOf), fn (int $basis): bool => $basis > 0);

        if ($weights === []) {
            return [self::key($fallback) => $total];
        }

        return self::split($total, $weights);
    }

    /**
     * Splits a whole number of öre over the keys in proportion to their
     * weights, by the largest remainder: each key gets the floor of its
     * exact share, and the öre left over go one each to the keys with the
     * largest remainders (on a tie the larger weight, then the key that
     * comes first). Deterministic, and the parts always sum to $total. A
     * negative total is split as its absolute value and given back its sign.
     *
     * @template TKey of array-key
     *
     * @param  array<TKey, int>  $weights  all positive
     * @return array<TKey, int>
     */
    public static function split(int $total, array $weights): array
    {
        if ($weights === []) {
            throw new InvalidArgumentException('Nothing to split over.');
        }

        foreach ($weights as $weight) {
            if ($weight <= 0) {
                throw new InvalidArgumentException('Weights must be positive.');
            }
        }

        $sign = $total < 0 ? -1 : 1;
        $amount = BigInteger::of($total)->abs();
        $sum = BigInteger::of(array_sum($weights));
        $parts = [];
        $remainders = [];
        $order = 0;

        foreach ($weights as $key => $weight) {
            $exact = $amount->multipliedBy($weight);
            $parts[$key] = $exact->quotient($sum)->toInt();
            $remainders[] = ['key' => $key, 'remainder' => $exact->remainder($sum), 'weight' => $weight, 'order' => $order++];
        }

        $left = $amount->toInt() - array_sum($parts);

        usort($remainders, fn (array $a, array $b): int => $b['remainder']->compareTo($a['remainder']) ?: ($b['weight'] <=> $a['weight']) ?: ($a['order'] <=> $b['order']));

        for ($i = 0; $i < $left; $i++) {
            $parts[$remainders[$i]['key']]++;
        }

        return array_map(fn (int $part): int => $sign * $part, $parts);
    }

    public static function key(?int $costCenterId): string
    {
        return $costCenterId === null ? '' : (string) $costCenterId;
    }
}
