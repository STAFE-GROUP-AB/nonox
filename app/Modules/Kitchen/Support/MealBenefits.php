<?php

declare(strict_types=1);

namespace App\Modules\Kitchen\Support;

use App\Models\Company;
use App\Modules\Inventory\Domain\Quantity;
use App\Modules\Kitchen\Domain\MealReason;
use App\Modules\Kitchen\Domain\MealType;
use App\Modules\Kitchen\Models\StaffMeal;
use App\Modules\Payroll\Domain\Rules\MissingPayrollRuleException;
use App\Modules\Payroll\Domain\Rules\RuleSet;
use App\Support\Tenancy\CurrentCompany;
use Carbon\CarbonImmutable;
use Illuminate\Validation\ValidationException;

/**
 * The meal benefits of a month per employee, from the staff-meal log
 * (ADR-073 §7, rule sheet RS-R12, fixture F-RS-4), as the lines payroll
 * needs: KOSTFORMAN_FRUKOST and KOSTFORMAN_LUNCH (lunch or dinner) per
 * meal, KOSTFORMAN_HEL for a day with at least a breakfast and two lunches
 * or dinners, and FORMAN_EGENBETALNING with what the employee paid.
 *
 * The payment counts against each meal's own value and never above it
 * (IL 61 kap. 4 §: each benefit is reduced by what was paid for it). Payroll
 * sets an own payment against all of an employee's benefits together, so
 * an uncapped overpayment for lunches would lower an unrelated car
 * benefit; the excess is shown apart and not sent. Only PERSONALMAT;
 * representation is tax-free and the owner is no employee. Values from
 * the verified payroll rules on the first day of the month.
 *
 * TODO(legal-verify): that "helt fri kost" (310 kr per day, at least three
 * meals) means a breakfast and two lunches or dinners on the same day.
 */
final class MealBenefits
{
    public const string FULL_DAY = 'KOSTFORMAN_HEL';

    public const string OWN_PAYMENT = 'FORMAN_EGENBETALNING';

    /**
     * @return array<int, array{employee_id: int, lines: list<array{pay_code: string, quantity: ?int, amount_ore: ?int}>, meals: int, paid_ore: int, capped_ore: int, excess_ore: int, days: list<array{date: string, full_day: bool, breakfasts: int, lunches: int, paid_ore: int, capped_ore: int}>}>
     *
     * @throws ValidationException when a meal value is not a verified payroll rule
     */
    public static function month(Company $company, string $period): array
    {
        $first = CarbonImmutable::createFromFormat('!Y-m', $period) ?: throw ValidationException::withMessages(['period' => __('Välj en månad.')]);

        return CurrentCompany::run($company, function () use ($first): array {
            try {
                $rules = RuleSet::for($first);
                $values = [
                    MealType::Breakfast->payCode() => (int) ((array) $rules->get('benefit.meal.breakfast'))['ore'],
                    MealType::Lunch->payCode() => (int) ((array) $rules->get('benefit.meal.lunch_or_dinner'))['ore'],
                    self::FULL_DAY => (int) ((array) $rules->get('benefit.meal.free_day'))['ore'],
                ];
            } catch (MissingPayrollRuleException $exception) {
                throw ValidationException::withMessages(['period' => __('Kostförmånens värde för :date är inte verifierat i lönens regler (:key).', ['date' => $first->toDateString(), 'key' => $exception->key])]);
            }

            $meals = StaffMeal::query()
                ->whereNotNull('employee_id')
                ->where('reason', MealReason::Staff->value)
                ->whereDate('served_on', '>=', $first->toDateString())
                ->whereDate('served_on', '<=', $first->endOfMonth()->toDateString())
                ->orderBy('served_on')->orderBy('id')
                ->get(['id', 'employee_id', 'served_on', 'meal', 'quantity_milli', 'paid_per_meal_ore']);

            $result = [];

            foreach ($meals->groupBy('employee_id') as $employeeId => $rows) {
                $result[(int) $employeeId] = self::employee((int) $employeeId, $rows->groupBy(fn (StaffMeal $meal): string => $meal->served_on->toDateString())->all(), $values);
            }

            return $result;
        });
    }

    /**
     * @param  array<string, iterable<StaffMeal>>  $days
     * @param  array<string, int>  $values
     * @return array{employee_id: int, lines: list<array{pay_code: string, quantity: ?int, amount_ore: ?int}>, meals: int, paid_ore: int, capped_ore: int, excess_ore: int, days: list<array{date: string, full_day: bool, breakfasts: int, lunches: int, paid_ore: int, capped_ore: int}>}
     */
    private static function employee(int $employeeId, array $days, array $values): array
    {
        $counts = [self::FULL_DAY => 0, MealType::Breakfast->payCode() => 0, MealType::Lunch->payCode() => 0];
        $paid = 0;
        $capped = 0;
        $trail = [];

        foreach ($days as $date => $rows) {
            // One payment per meal eaten; a correction takes the meals back with their payments.
            $breakfasts = [];
            $lunches = [];

            foreach ($rows as $meal) {
                $count = intdiv($meal->quantity_milli, Quantity::SCALE);

                for ($i = 0; $i < abs($count); $i++) {
                    if ($meal->meal === MealType::Breakfast) {
                        self::apply($breakfasts, $count > 0, $meal->paid_per_meal_ore);

                        continue;
                    }

                    self::apply($lunches, $count > 0, $meal->paid_per_meal_ore);
                }
            }

            $dayPaid = array_sum($breakfasts) + array_sum($lunches);
            $dayCapped = 0;
            $fullDay = count($breakfasts) >= 1 && count($lunches) >= 2;

            if ($fullDay) {
                $counts[self::FULL_DAY]++;
                $dayCapped += min(array_shift($breakfasts) + array_shift($lunches) + array_shift($lunches), $values[self::FULL_DAY]);
            }

            foreach ($breakfasts as $amount) {
                $counts[MealType::Breakfast->payCode()]++;
                $dayCapped += min($amount, $values[MealType::Breakfast->payCode()]);
            }

            foreach ($lunches as $amount) {
                $counts[MealType::Lunch->payCode()]++;
                $dayCapped += min($amount, $values[MealType::Lunch->payCode()]);
            }

            $paid += $dayPaid;
            $capped += $dayCapped;
            $trail[] = ['date' => (string) $date, 'full_day' => $fullDay, 'breakfasts' => count($breakfasts) + ($fullDay ? 1 : 0), 'lunches' => count($lunches) + ($fullDay ? 2 : 0), 'paid_ore' => $dayPaid, 'capped_ore' => $dayCapped];
        }

        $lines = [];

        foreach ($counts as $code => $quantity) {
            if ($quantity > 0) {
                $lines[] = ['pay_code' => $code, 'quantity' => $quantity, 'amount_ore' => null];
            }
        }

        if ($capped > 0) {
            $lines[] = ['pay_code' => self::OWN_PAYMENT, 'quantity' => null, 'amount_ore' => $capped];
        }

        return [
            'employee_id' => $employeeId,
            'lines' => $lines,
            'meals' => array_sum(array_map(fn (array $day): int => $day['breakfasts'] + $day['lunches'], $trail)),
            'paid_ore' => $paid,
            'capped_ore' => $capped,
            'excess_ore' => $paid - $capped,
            'days' => $trail,
        ];
    }

    /**
     * Adds a meal's payment, or takes one back for a correction.
     *
     * @param  list<int>  $list
     */
    private static function apply(array &$list, bool $add, int $amount): void
    {
        if ($add) {
            $list[] = $amount;

            return;
        }

        $index = array_search($amount, $list, true);

        if ($index === false) {
            array_pop($list);

            return;
        }

        array_splice($list, $index, 1);
    }
}
