<?php

declare(strict_types=1);

namespace App\Modules\Planning\Actions;

use App\Models\Company;
use App\Models\User;
use App\Modules\Payroll\Models\Employee;
use App\Modules\Planning\Actions\Concerns\GuardsPlanning;
use App\Modules\Planning\Models\Attestation;
use App\Modules\Planning\Support\LocalTimestamp;
use App\Support\Audit\Audit;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

/**
 * The manager's own actual time for an employee's day (PLANNING_MODULE.md
 * §5): used instead of the staff ledger (or the plan, when the ledger is
 * off) for that day only. The staff ledger itself is never changed; a
 * wrong check-in is corrected there. Up to two intervals, so a break on OB
 * time can be placed; none means the employee did not work. "clear" goes
 * back to the ledger. Only an open day can be changed.
 */
final class SaveActualTime
{
    use GuardsPlanning;

    public const int MAX_INTERVALS = 2;

    /**
     * @param  array<string, mixed>  $input  employee_id, date, intervals: list of {start, end, break_minutes}, note, clear
     */
    public function __invoke(User $user, Company $company, array $input): Attestation
    {
        $this->guard($user, $company, 'attest');

        $data = Validator::make($input, [
            'employee_id' => ['required', 'integer'],
            'date' => ['required', 'date_format:Y-m-d', 'before_or_equal:today'],
            'clear' => ['boolean'],
            'intervals' => ['array', 'max:'.self::MAX_INTERVALS],
            'intervals.*.start' => ['required', 'date_format:H:i'],
            'intervals.*.end' => ['required', 'date_format:H:i'],
            'intervals.*.break_minutes' => ['nullable', 'integer', 'between:0,600'],
            'note' => ['nullable', 'string', 'max:500'],
        ], [], [
            'date' => __('Datum'),
            'intervals.*.start' => __('Från'),
            'intervals.*.end' => __('Till'),
            'intervals.*.break_minutes' => __('Rast'),
        ])->validate();

        $employee = Employee::query()->findOrFail((int) $data['employee_id']);
        $date = CarbonImmutable::parse((string) $data['date'], LocalTimestamp::ZONE)->startOfDay();
        $override = ($data['clear'] ?? false) ? null : self::intervals($date, (array) ($data['intervals'] ?? []));

        return DB::transaction(function () use ($user, $company, $employee, $date, $override, $data): Attestation {
            $attestation = Attestation::query()
                ->where('employee_id', $employee->id)
                ->whereDate('date', $date->toDateString())
                ->lockForUpdate()
                ->first() ?? new Attestation(['employee_id' => $employee->id, 'date' => $date->toDateString()]);

            if ($attestation->isLocked()) {
                throw ValidationException::withMessages(['day' => __('Dagen är attesterad. Öppna den igen för att ändra tiden.')]);
            }

            $before = $attestation->actual_override;
            $attestation->fill(['actual_override' => $override, 'note' => $data['note'] ?? null, 'updated_by' => $user->id])->save();

            Audit::record('planning.actual_time_saved', $attestation, [
                'employee_id' => $employee->id,
                'date' => $date->toDateString(),
                'before' => $before,
                'after' => $override,
            ], $company, $user);

            return $attestation;
        });
    }

    /**
     * The intervals as instants: an end at or before the start is the next day.
     *
     * @param  array<int, mixed>  $intervals
     * @return list<array{start: string, end: string, break_minutes: int}>
     */
    private static function intervals(CarbonImmutable $date, array $intervals): array
    {
        $result = [];

        foreach (array_values($intervals) as $index => $interval) {
            $interval = (array) $interval;
            [$startHour, $startMinute] = array_map('intval', explode(':', (string) $interval['start']));
            [$endHour, $endMinute] = array_map('intval', explode(':', (string) $interval['end']));
            $start = $date->setTime($startHour, $startMinute);
            $end = $date->setTime($endHour, $endMinute);

            if ($end->lessThanOrEqualTo($start)) {
                $end = $date->addDay()->setTime($endHour, $endMinute);
            }

            $break = (int) ($interval['break_minutes'] ?? 0);

            if ($break >= intdiv($end->getTimestamp() - $start->getTimestamp(), 60)) {
                throw ValidationException::withMessages(["intervals.{$index}.break_minutes" => __('Rasten måste vara kortare än passet.')]);
            }

            $result[] = ['start' => $start->toIso8601String(), 'end' => $end->toIso8601String(), 'break_minutes' => $break];
        }

        usort($result, fn (array $a, array $b): int => strcmp($a['start'], $b['start']));

        return $result;
    }
}
