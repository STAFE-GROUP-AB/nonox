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
 * Paid comp time is vacation-pay basis like the overtime it was earned
 * from (semesterlagen 16 b §; an agreement that includes semesterlön in its
 * overtime pay, Visita–Unionen § 16 and Handelns tjänstemannaavtal § 9.4.1,
 * marks those codes as not basis): the minutes paid use up the earned
 * entries first in, first out (origins()), and the line carries the flag in
 * its explanation (vacation_basis), which the vacation ledger reads before
 * the pay code's own. Enligt AI-utredning 2026-11-02, juristgranskning
 * återstår.
 *
 * TODO(legal-verify): which hour value the agreement prescribes for paid
 * comp time (the agreement's divisor is not used here), and whether comp
 * time earned under Visita–Unionen or Handelns tjänstemannaavtal is covered
 * by their "semesterlön ingår i övertidsersättningen".
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
     * Which earned minutes each withdrawal (leave taken, paid out, or a
     * negative adjustment) uses up, first in, first out: per entry id, the
     * minutes from vacation-basis earnings (true) and from others (false).
     * An earning follows the vacation flag of the pay code it was measured
     * on; a positive adjustment, an earning without a code and minutes
     * beyond what was earned follow $default.
     *
     * @param  Collection<int, CompTimeEntry>  $history  the employee's entries up to a date
     * @param  array<string, bool>  $vacationByCode  pay code => vacation basis
     * @return array<int, array{true: int, false: int}>
     */
    public static function origins(Collection $history, array $vacationByCode, bool $default): array
    {
        $credits = [];
        $split = [];

        foreach ($history->sortBy(fn (CompTimeEntry $entry): string => $entry->date->toDateString().'|'.str_pad((string) $entry->id, 12, '0', STR_PAD_LEFT)) as $entry) {
            if ($entry->minutes > 0) {
                $credits[] = ['minutes' => $entry->minutes, 'vacation' => $entry->kind === CompTimeEntry::EARNED && $entry->pay_code !== null ? ($vacationByCode[$entry->pay_code] ?? $default) : $default];

                continue;
            }

            $need = -$entry->minutes;
            $parts = ['true' => 0, 'false' => 0];

            while ($need > 0 && $credits !== []) {
                $take = min($need, $credits[0]['minutes']);
                $parts[$credits[0]['vacation'] ? 'true' : 'false'] += $take;
                $credits[0]['minutes'] -= $take;
                $need -= $take;

                if ($credits[0]['minutes'] === 0) {
                    array_shift($credits);
                }
            }

            $parts[$default ? 'true' : 'false'] += $need;
            $split[$entry->id] = $parts;
        }

        return $split;
    }

    /**
     * The pay lines a run pays for comp time in its period, one per kind and
     * vacation flag of the overtime the minutes were earned from.
     *
     * @param  Collection<int, CompTimeEntry>  $entries  the employee's entries dated in the period
     * @param  array<int, array{true: int, false: int}>  $origins  from origins(); entries missing follow the pay code
     * @return list<PayInput>
     */
    public static function payInputs(Collection $entries, PayrollContext $context, ?PayCode $code, array $origins = []): array
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

            $byFlag = ['true' => 0, 'false' => 0];

            foreach ($own as $entry) {
                $parts = $origins[$entry->id] ?? [$code->is_vacation_basis ? 'true' : 'false' => -$entry->minutes];
                $byFlag['true'] += $parts['true'] ?? 0;
                $byFlag['false'] += $parts['false'] ?? 0;
            }

            foreach (array_filter($byFlag) as $flag => $minutes) {
                $vacation = $flag === 'true';
                $description = $kind === CompTimeEntry::PAID_OUT ? __('Komptid, utbetald') : __('Komptid, uttagen ledighet');
                $inputs[] = new PayInput(
                    $code->code,
                    $vacation || ! $code->is_vacation_basis ? $description : __(':item (inte semesterlönegrundande enligt avtalet)', ['item' => $description]),
                    $code->category,
                    $code->is_taxable,
                    $code->tax_method,
                    $code->is_contribution_basis,
                    $vacation,
                    quantity: (string) BigDecimal::of($minutes)->dividedBy(60, 2, RoundingMode::HalfUp),
                    rateOre: $rate,
                    explanation: [
                        'source' => 'comp_time',
                        'kind' => $kind,
                        'minutes' => $minutes,
                        'vacation_basis' => $vacation,
                        'comp_time_entry_ids' => $own->pluck('id')->values()->all(),
                        'hour_value_ore' => (string) HourlyValue::hour($context)->toScale(Units::SHOWN_DECIMALS, RoundingMode::HalfUp),
                    ],
                );
            }
        }

        return $inputs;
    }
}
