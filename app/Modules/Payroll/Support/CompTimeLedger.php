<?php

declare(strict_types=1);

namespace App\Modules\Payroll\Support;

use App\Modules\Payroll\Domain\Engine\HourlyValue;
use App\Modules\Payroll\Domain\Engine\PayInput;
use App\Modules\Payroll\Domain\Engine\PayrollBlockedException;
use App\Modules\Payroll\Domain\Engine\PayrollContext;
use App\Modules\Payroll\Domain\Units;
use App\Modules\Payroll\Models\CompTimeEntry;
use App\Modules\Payroll\Models\PayCode;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * Comp-time balances (komptid, gap G8) and what a payroll run pays for
 * them. The balance is the sum of the entries up to a date, in minutes of
 * leave. A payout, and leave taken by an hourly-paid employee, are paid by
 * the regular run of the entry's month on the pay code KOMPTID: the hourly
 * rate, or for a monthly salary the hour value (HourlyValue). Leave taken by
 * a monthly-paid employee is already paid by the salary.
 *
 * TODO(legal-verify): whether paid-out comp time earns vacation pay and
 * which hour value the agreement prescribes for it (the agreement's divisor
 * is not used here).
 */
final class CompTimeLedger
{
    public const string PAY_CODE = 'KOMPTID';

    /**
     * Balances in minutes per employee up to and including a date, in one query.
     *
     * @param  list<int>  $employeeIds
     * @return array<int, int>
     */
    public static function balances(array $employeeIds, ?CarbonImmutable $at = null): array
    {
        if ($employeeIds === []) {
            return [];
        }

        $sums = CompTimeEntry::query()
            ->whereIn('employee_id', $employeeIds)
            ->when($at !== null, fn ($query) => $query->whereDate('date', '<=', $at?->toDateString()))
            ->groupBy('employee_id')
            ->selectRaw('employee_id, SUM(minutes) AS balance')
            ->pluck('balance', 'employee_id');

        $balances = [];

        foreach ($sums as $employeeId => $balance) {
            $balances[(int) $employeeId] = (int) $balance;
        }

        return $balances;
    }

    public static function balance(int $employeeId, ?CarbonImmutable $at = null): int
    {
        return self::balances([$employeeId], $at)[$employeeId] ?? 0;
    }

    /** Whether the employee has ever had comp time. */
    public static function exists(int $employeeId): bool
    {
        return CompTimeEntry::query()->where('employee_id', $employeeId)->exists();
    }

    /** Minutes as hours with two decimals, for display ("7,50 h"). */
    public static function hours(int $minutes): string
    {
        return number_format($minutes / 60, 2, ',', ' ');
    }

    /**
     * The pay lines a run pays for comp time in its period.
     *
     * @param  Collection<int, CompTimeEntry>  $entries  the employee's entries dated in the period
     * @return list<PayInput>
     */
    public static function payInputs(Collection $entries, PayrollContext $context, ?PayCode $code): array
    {
        $paid = $entries->filter(fn (CompTimeEntry $entry): bool => $entry->kind === CompTimeEntry::PAID_OUT
            || ($entry->kind === CompTimeEntry::TAKEN && $context->salaryForm === 'HOURLY'));

        if ($paid->isEmpty()) {
            return [];
        }

        $code ??= throw new PayrollBlockedException(__('Lönearten Komptid saknas. Slå av och på lönemodulen för att lägga till systemets lönearter.'));
        $rate = HourlyValue::hour($context)->toScale(0, RoundingMode::HalfUp)->toInt();
        $inputs = [];

        foreach ([CompTimeEntry::PAID_OUT, CompTimeEntry::TAKEN] as $kind) {
            $own = $paid->filter(fn (CompTimeEntry $entry): bool => $entry->kind === $kind);

            if ($own->isEmpty()) {
                continue;
            }

            $minutes = -1 * (int) $own->sum('minutes');
            $inputs[] = new PayInput(
                $code->code,
                $kind === CompTimeEntry::PAID_OUT ? __('Komptid, utbetald') : __('Komptid, uttagen ledighet'),
                $code->category,
                $code->is_taxable,
                $code->tax_method,
                $code->is_contribution_basis,
                $code->is_vacation_basis,
                quantity: (string) BigDecimal::of($minutes)->dividedBy(60, 2, RoundingMode::HalfUp),
                rateOre: $rate,
                explanation: [
                    'source' => 'comp_time',
                    'kind' => $kind,
                    'minutes' => $minutes,
                    'comp_time_entry_ids' => $own->pluck('id')->values()->all(),
                    'hour_value_ore' => (string) HourlyValue::hour($context)->toScale(Units::SHOWN_DECIMALS, RoundingMode::HalfUp),
                ],
            );
        }

        return $inputs;
    }
}
