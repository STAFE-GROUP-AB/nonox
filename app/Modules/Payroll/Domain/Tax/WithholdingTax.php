<?php

declare(strict_types=1);

namespace App\Modules\Payroll\Domain\Tax;

use App\Modules\Payroll\Domain\Rules\MissingPayrollRuleException;
use App\Modules\Payroll\Domain\Rules\RuleSet;
use App\Modules\Payroll\Support\Tax\TaxTableLookup;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use InvalidArgumentException;

/**
 * Preliminary tax on regular pay (PAYROLL_MODULE.md B.7): the tax table and
 * column, a tax adjustment decision (jämkning) or SINK. Percentages and the
 * rounding of a percentage result come from verified rules; nothing here is
 * a rate.
 *
 * Tax computed as a percentage is stated in whole kronor with öre dropped
 * (rule tax.rounding: skatteförfarandeförordningen 22 kap. 1 §).
 *
 * TODO(legal-verify): how a period income with öre is placed in the table's
 * whole-krona intervals (here: the whole kronor, öre dropped).
 */
final class WithholdingTax
{
    public function __construct(private readonly TaxTableLookup $lookup) {}

    /**
     * Tax from the monthly table for a taxable income in öre.
     */
    public function fromTable(int $year, int $table, int $column, int $taxableOre, RuleSet $rules): Withholding
    {
        $income = intdiv(max($taxableOre, 0), 100);
        $row = $this->lookup->row($year, $table, $column, $income);

        if ($row['value_type'] === 'AMOUNT') {
            return new Withholding($row['value'] * 100, [
                'method' => 'table',
                'table' => $table,
                'column' => $column,
                'year' => $year,
                'income_kronor' => $income,
                'interval' => [$row['income_from'], $row['income_to']],
                'amount_kronor' => $row['value'],
            ]);
        }

        return new Withholding($this->percent($taxableOre, $row['value'] * 100, $rules), [
            'method' => 'table_percent',
            'table' => $table,
            'column' => $column,
            'year' => $year,
            'income_kronor' => $income,
            'interval' => [$row['income_from'], $row['income_to']],
            'percent' => $row['value'],
        ]);
    }

    /**
     * SINK: a flat rate on the taxable income, no table (rule sink.rate).
     */
    public function sink(int $taxableOre, RuleSet $rules): Withholding
    {
        return $this->flat($taxableOre, 'sink.rate', 'sink', $rules);
    }

    /**
     * A flat rate from a rule: SINK, A-SINK (a_sink.rate) or a one-off
     * payment without regular salary (tax.one_off_without_regular_salary).
     */
    public function flat(int $taxableOre, string $ruleKey, string $method, RuleSet $rules): Withholding
    {
        $bp = (int) ((array) $rules->get($ruleKey))['bp'];

        return new Withholding($this->percent($taxableOre, $bp, $rules), ['method' => $method, 'rate_bp' => $bp, 'rule' => $rules->version($ruleKey)->id]);
    }

    /**
     * A tax adjustment decision (jämkning) stating a percentage.
     */
    public function adjustedPercent(int $taxableOre, int $basisPoints, RuleSet $rules): Withholding
    {
        if ($basisPoints < 0 || $basisPoints > 10000) {
            throw new InvalidArgumentException('A percentage must be between 0 and 100.');
        }

        return new Withholding($this->percent($taxableOre, $basisPoints, $rules), ['method' => 'adjustment_percent', 'rate_bp' => $basisPoints]);
    }

    /**
     * Tax on a one-off payment (engångsbelopp: bonus, retroactive pay,
     * vacation payout) from the percentage for the expected annual income and
     * column (rule tax.one_off).
     */
    public function oneOff(int $amountOre, int $expectedAnnualIncomeOre, int $column, RuleSet $rules): Withholding
    {
        /** @var array<int, list<array{0: int, 1: ?int, 2: int}>> $columns JSON object keys "1"…"6" become integer keys */
        $columns = (array) ((array) $rules->get('tax.one_off'))['columns'];

        if (! array_key_exists($column, $columns)) {
            throw new MissingPayrollRuleException("tax.one_off.columns.{$column}", $rules->date, false);
        }

        $brackets = $columns[$column];
        $income = intdiv(max($expectedAnnualIncomeOre, 0), 100);

        foreach ($brackets as [$from, $to, $bp]) {
            if ($income >= $from && ($to === null || $income <= $to)) {
                return new Withholding($this->percent($amountOre, $bp, $rules), [
                    'method' => 'one_off',
                    'column' => $column,
                    'expected_annual_income_kronor' => $income,
                    'rate_bp' => $bp,
                    'rule' => $rules->version('tax.one_off')->id,
                ]);
            }
        }

        throw new MissingPayrollRuleException('tax.one_off', $rules->date, false);
    }

    /**
     * Rounds a percentage of the income as the verified rule says.
     */
    private function percent(int $taxableOre, int $basisPoints, RuleSet $rules): int
    {
        /** @var array{mode?: string} $rounding */
        $rounding = (array) $rules->get('tax.rounding');
        $mode = match ($rounding['mode'] ?? null) {
            'DOWN_TO_KRONA' => RoundingMode::Down,
            'HALF_UP_TO_KRONA' => RoundingMode::HalfUp,
            default => throw new InvalidArgumentException('The rule tax.rounding has no known mode.'),
        };

        return BigDecimal::of(max($taxableOre, 0))
            ->multipliedBy($basisPoints)
            ->dividedBy(1_000_000, 0, $mode)
            ->multipliedBy(100)
            ->toInt();
    }
}
