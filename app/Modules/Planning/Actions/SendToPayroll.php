<?php

declare(strict_types=1);

namespace App\Modules\Planning\Actions;

use App\Models\Company;
use App\Models\User;
use App\Modules\Agreements\Domain\AgreementRules;
use App\Modules\ModuleManager;
use App\Modules\Payroll\Actions\CompTimeEntries;
use App\Modules\Payroll\Actions\PayrollRuns;
use App\Modules\Payroll\Domain\CostCenterShares;
use App\Modules\Payroll\Models\CompTimeEntry;
use App\Modules\Payroll\Models\PayCode;
use App\Modules\Payroll\Models\PayrollRun;
use App\Modules\Payroll\Models\TimeReport;
use App\Modules\Payroll\PayrollModule;
use App\Modules\Planning\Actions\Concerns\GuardsPlanning;
use App\Modules\Planning\Domain\ObSplitter;
use App\Modules\Planning\Domain\OvertimeKinds;
use App\Modules\Planning\Domain\WeekBasis;
use App\Modules\Planning\Domain\WorkInterval;
use App\Modules\Planning\Models\Attestation;
use App\Modules\Planning\Models\Shift;
use App\Modules\Planning\Support\AttestData;
use App\Modules\Planning\Support\AttestRow;
use App\Modules\Planning\Support\EmploymentAgreements;
use App\Modules\Planning\Support\LocalTimestamp;
use App\Modules\Planning\Support\ShiftQuery;
use App\Support\Audit\Audit;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * "Skicka till lönen" (PLANNING_MODULE.md §8): the attested days of a
 * period become approved time reports, one per day, pay code and cost
 * centre (hours with two decimals), the same rows the staff ledger's
 * proposals and the payroll files feed, so the payroll run picks them up
 * unchanged. A day's cost centre is that of its shifts (ADR-037, gap 7;
 * none: the employment's); a day whose shifts are on several cost centres
 * has hourly pay split by the shifts' planned minutes and each OB code by
 * the OB the shifts' planned times give (weights()), in hundredths of an
 * hour by the largest remainder, so the hours add up to exactly what one
 * report would have held. The day's overtime and mertid follow the planned
 * minutes like hourly pay; a week's or period's stay on the employment's
 * cost centre. The day is then
 * sent and locked. A day is skipped, with the reason, when its month's run
 * is already attested, when another time report or a pending ledger
 * proposal covers it, or when a pay code is missing. Sending a sent day
 * again changes nothing. A calculated run for the month goes back to draft.
 */
final class SendToPayroll
{
    use GuardsPlanning;

    public function __construct(private readonly AttestData $data, private readonly PayrollRuns $runs, private readonly CompTimeEntries $compTime) {}

    /**
     * @return array{sent: int, weeks: int, reports: int, skipped: list<string>}
     */
    public function __invoke(User $user, Company $company, CarbonImmutable $from, CarbonImmutable $to, ?int $employeeId = null): array
    {
        $this->guard($user, $company, 'attest');
        app(ModuleManager::class)->ensureEnabled(PayrollModule::KEY, $company);

        if ($to->lessThan($from) || $from->diffInDays($to) > AttestPeriod::MAX_DAYS) {
            throw ValidationException::withMessages(['period' => __('Välj en period på högst två månader.')]);
        }

        return DB::transaction(function () use ($user, $company, $from, $to, $employeeId): array {
            $all = collect($this->data->load($company, $from, $to, $employeeId)['rows']);
            $rows = $all->filter(fn (AttestRow $row): bool => $row->status() === Attestation::ATTESTED);
            $codes = PayCode::query()->pluck('id', 'code');
            $sent = [];
            $reports = 0;
            $skipped = [];
            $months = [];
            $weeks = 0;
            $costCenters = $this->costCenterShifts($from, $to, array_values(array_unique($rows->map(fn (AttestRow $row): int => $row->employee->id)->all())));
            $agreements = new EmploymentAgreements(app(ModuleManager::class), $company, $rows->flatMap(fn (AttestRow $row): Collection => $row->employee->employments)->values());

            foreach ($rows as $row) {
                /** @var Attestation $attestation */
                $attestation = Attestation::query()->whereKey($row->attestation?->id)->lockForUpdate()->firstOrFail();
                $name = "{$row->employee->first_name} {$row->employee->last_name} {$row->date->toDateString()}";

                if ($attestation->status !== Attestation::ATTESTED) {
                    continue;
                }

                if ($row->lockReason !== null) {
                    $skipped[] = "{$name}: {$row->lockReason}";

                    continue;
                }

                $conflict = $this->conflict($attestation);

                if ($conflict !== null) {
                    $skipped[] = "{$name}: {$conflict}";

                    continue;
                }

                $missing = array_diff(array_keys($attestation->basis ?? []), $codes->keys()->all());

                if ($missing !== []) {
                    $skipped[] = "{$name}: ".__('Lönearten :code finns inte i lönen.', ['code' => implode(', ', $missing)]);

                    continue;
                }

                $ids = [];

                $dayShifts = $costCenters["{$attestation->employee_id}|{$attestation->date->toDateString()}"] ?? [];

                foreach ($attestation->basis ?? [] as $code => $minutes) {
                    $weights = self::weights((string) $code, $dayShifts, array_keys((array) ($attestation->details['ob'] ?? [])), fn (): ?AgreementRules => $agreements->on(AttestData::employmentOn($row->employee, $row->date), $row->date)[0]);

                    foreach (self::hoursPerCostCenter($minutes, $weights) as [$costCenterId, $hours]) {
                        $ids[] = TimeReport::create([
                            'employee_id' => $attestation->employee_id,
                            'date' => $attestation->date->toDateString(),
                            'hours' => $hours,
                            'pay_code_id' => $codes->get($code),
                            'cost_center_id' => $costCenterId,
                            'status' => 'APPROVED',
                        ])->id;
                    }
                }

                $attestation->forceFill([
                    'status' => Attestation::SENT,
                    'time_report_ids' => $ids,
                    'sent_by' => $user->id,
                    'sent_at' => CarbonImmutable::now(),
                    'updated_by' => $user->id,
                ])->save();
                $sent[] = $attestation->id;
                $reports += count($ids);
                $months[$attestation->date->format('Y-m')] = true;
            }

            foreach ($all->filter(fn (AttestRow $row): bool => $row->week !== null && ($row->week->minutes !== [] || ($row->week->choice === WeekBasis::COMP_TIME && $row->week->canTakeCompTime()))) as $row) {
                $result = $this->sendWeek($user, $company, $row, $codes);

                if (is_string($result)) {
                    $skipped[] = "{$row->employee->first_name} {$row->employee->last_name} {$row->date->toDateString()}: {$result}";

                    continue;
                }

                if ($result > 0) {
                    $weeks++;
                    $reports += $result;
                    $months[$row->date->format('Y-m')] = true;
                }
            }

            // New approved time makes a calculated run for the month out of date.
            PayrollRun::query()
                ->where('type', 'REGULAR')
                ->where('status', PayrollRun::CALCULATED)
                ->whereIn('period', array_keys($months))
                ->lockForUpdate()
                ->get()
                ->each(fn (PayrollRun $run) => $this->runs->backToDraft($run));

            if ($sent !== [] || $weeks > 0 || $skipped !== []) {
                Audit::record('planning.sent_to_payroll', null, [
                    'from' => $from->toDateString(),
                    'to' => $to->toDateString(),
                    'employee_id' => $employeeId,
                    'attestation_ids' => $sent,
                    'time_reports' => $reports,
                    'weeks' => $weeks,
                    'skipped' => count($skipped),
                ], $company, $user);
            }

            return ['sent' => count($sent), 'weeks' => $weeks, 'reports' => $reports, 'skipped' => $skipped];
        });
    }

    /**
     * A fully attested week's overtime and mertid, written as time reports
     * on its last day once that day is sent, and recorded on it
     * (details.week), so it is sent once. The number of reports written, or
     * why the week waits.
     *
     * @param  Collection<string, int>  $codes  pay code => id
     */
    private function sendWeek(User $user, Company $company, AttestRow $row, Collection $codes): int|string
    {
        /** @var Attestation $attestation */
        $attestation = Attestation::query()->whereKey($row->attestation?->id)->lockForUpdate()->firstOrFail();
        $week = $row->week;

        if ($week === null || $attestation->status !== Attestation::SENT || isset($attestation->details['week'])) {
            return 0;
        }

        $closed = PayrollRun::query()
            ->where('type', 'REGULAR')
            ->where('period', $attestation->date->format('Y-m'))
            ->whereNotIn('status', [PayrollRun::DRAFT, PayrollRun::CALCULATED])
            ->exists();

        if ($closed) {
            return __('Veckans övertid och mertid skickas inte: lönen för månaden är redan attesterad. Rättelser läggs i nästa lönekörning.');
        }

        if ($week->choice === WeekBasis::COMP_TIME && $week->canTakeCompTime()) {
            return $this->earnCompTime($user, $company, $attestation, $week);
        }

        [$parts, $dropped] = $this->takeBacks($attestation, $week->parts, $codes);
        $missing = array_diff($parts !== [] ? array_column($parts, 'code') : array_keys($week->minutes), $codes->keys()->all());

        if ($missing !== []) {
            return __('Lönearten :code finns inte i lönen.', ['code' => implode(', ', array_unique($missing))]);
        }

        $ids = [];
        $basis = $attestation->basis ?? [];

        // Split by agreement kind (ADR-033 H.1): one report per code and day worked, paid with the period's last day but priced
        // on the day worked (priced_on), so an hour before a new rate step keeps the old rate. Otherwise one report per code.
        $reports = $parts !== []
            ? array_map(fn (array $part): array => [$part['code'], $part['minutes'], $part['date'] === $attestation->date->toDateString() ? null : $part['date']], $parts)
            : array_map(fn (string $code, int $minutes): array => [$code, $minutes, null], array_keys($week->minutes), array_values($week->minutes));

        foreach ($reports as [$code, $minutes, $pricedOn]) {
            if ($minutes === 0) {
                continue;
            }

            $ids[] = TimeReport::create([
                'employee_id' => $attestation->employee_id,
                'date' => $attestation->date->toDateString(),
                'priced_on' => $pricedOn,
                'hours' => (string) BigDecimal::of($minutes)->dividedBy(60, 2, RoundingMode::HalfUp),
                'pay_code_id' => $codes->get($code),
                'status' => 'APPROVED',
            ])->id;
            $basis[$code] = ($basis[$code] ?? 0) + $minutes;
        }

        ksort($basis);
        $attestation->forceFill([
            'basis' => $basis,
            'time_report_ids' => [...($attestation->time_report_ids ?? []), ...$ids],
            'details' => [...($attestation->details ?? []), 'week' => [
                'from' => $attestation->date->startOfWeek(CarbonImmutable::MONDAY)->toDateString(),
                'overtime_minutes' => $week->overtimeMinutes,
                'extra_time_minutes' => $week->extraTimeMinutes,
                'minutes' => $week->minutes,
                'time_report_ids' => $ids,
                'take_backs_not_sent' => $dropped === [] ? null : $dropped,
            ]],
            'updated_by' => $user->id,
        ])->save();

        return count($ids);
    }

    /**
     * The period's parts without the OB take-backs (OvertimeKinds::OB_OVERLAP)
     * that cannot be sent safely: a take-back for a day whose own time
     * reports (its OB) were not sent, because the day was skipped, or on a
     * pay code the company does not have. Leaving one out can only pay
     * more, never less than the higher of OB and overtime. The dropped
     * ones are returned for the record.
     *
     * @param  list<array{type: string, code: string, date: string, minutes: int, minimum: bool}>  $parts
     * @param  Collection<string, int>  $codes
     * @return array{list<array{type: string, code: string, date: string, minutes: int, minimum: bool}>, list<array{code: string, date: string, minutes: int, reason: string}>}
     */
    private function takeBacks(Attestation $attestation, array $parts, Collection $codes): array
    {
        $dates = array_values(array_unique(array_column(array_filter($parts, fn (array $part): bool => $part['type'] === OvertimeKinds::OB_OVERLAP), 'date')));

        if ($dates === []) {
            return [$parts, []];
        }

        $sent = Attestation::query()
            ->where('employee_id', $attestation->employee_id)
            ->where('status', Attestation::SENT)
            ->whereIn('date', $dates)
            ->get(['date'])
            ->map(fn (Attestation $day): string => $day->date->toDateString())
            ->flip();
        $kept = [];
        $dropped = [];

        foreach ($parts as $part) {
            $reason = match (true) {
                $part['type'] !== OvertimeKinds::OB_OVERLAP => null,
                ! $sent->has($part['date']) => 'DAY_NOT_SENT',
                ! $codes->has($part['code']) => 'NO_PAY_CODE',
                default => null,
            };

            if ($reason === null) {
                $kept[] = $part;

                continue;
            }

            $dropped[] = ['code' => $part['code'], 'date' => $part['date'], 'minutes' => $part['minutes'], 'reason' => $reason];
        }

        return [$kept, $dropped];
    }

    /**
     * The period's overtime and mertid taken as comp time: one balance entry
     * per kind on the period's last day instead of time reports, recorded on
     * the day (details.week) so it is earned once. Counted as one "report".
     */
    private function earnCompTime(User $user, Company $company, Attestation $attestation, WeekBasis $week): int
    {
        $ids = [];
        $total = 0;

        foreach ($week->compTime as $option) {
            $entry = $this->compTime->earn($user, $company, $attestation->employee_id, CarbonImmutable::parse($attestation->date->toDateString()), $option['worked'], $option['factor'], $option['code'], CompTimeEntry::SOURCE_PLANNING, $attestation->id);
            $ids[] = $entry->id;
            $total += $entry->minutes;
        }

        $attestation->forceFill([
            'details' => [...($attestation->details ?? []), 'week' => [
                'from' => $attestation->date->startOfWeek(CarbonImmutable::MONDAY)->toDateString(),
                'overtime_minutes' => $week->overtimeMinutes,
                'extra_time_minutes' => $week->extraTimeMinutes,
                'minutes' => [],
                'time_report_ids' => [],
                'comp_time_entry_ids' => $ids,
                'comp_time_minutes' => $total,
                'comp_time' => $week->compTime,
            ]],
            'updated_by' => $user->id,
        ])->save();

        return count($ids);
    }

    /**
     * Where a pay code's minutes of a day arose, as weights per cost centre
     * ('' for none, the employment's):
     *
     * - an OB code: the OB minutes of that code that each shift's planned
     *   time gives under the agreement (ObSplitter), so evening OB in the bar
     *   is not put on the morning's kitchen; when the shifts give none of
     *   it (the OB arose outside the plan), the planned minutes;
     * - anything else (hourly pay, and the day's overtime and mertid): the
     *   planned minutes of the day's shifts on each, so a day on one cost
     *   centre puts it all there. A week's or period's overtime and mertid
     *   (sendWeek) is written without a cost centre: the employment's.
     *
     * The agreement is read only when the day has shifts on more than one
     * cost centre.
     *
     * @param  list<array{cost_center: string, start: CarbonImmutable, end: CarbonImmutable, minutes: int}>  $shifts
     * @param  list<string>  $obCodes  the day's OB codes (details.ob)
     * @param  Closure(): ?AgreementRules  $rules
     * @return array<string, int>
     */
    public static function weights(string $code, array $shifts, array $obCodes, Closure $rules): array
    {
        $planned = [];

        foreach ($shifts as $shift) {
            $planned[$shift['cost_center']] = ($planned[$shift['cost_center']] ?? 0) + $shift['minutes'];
        }

        if (! in_array($code, $obCodes, true)) {
            return $planned;
        }

        if (count(array_filter($planned, fn (int $minutes): bool => $minutes > 0)) <= 1) {
            return $planned;
        }

        $agreement = $rules();

        if ($agreement === null) {
            return $planned;
        }

        $ob = [];

        foreach ($shifts as $shift) {
            $minutes = ObSplitter::split($agreement, $shift['start'], $shift['end'])->minutesFor($code);

            if ($minutes > 0) {
                $ob[$shift['cost_center']] = ($ob[$shift['cost_center']] ?? 0) + $minutes;
            }
        }

        return $ob === [] ? $planned : $ob;
    }

    /**
     * The published shifts (as the employee was shown them) in the period
     * per employee and day, with their cost centre and planned minutes, in
     * one query. The cost centre is the shift's current one: it is not
     * shown to the employee and does not unpublish the shift. Keyed
     * "employee|date"; the cost centre as CostCenterShares::key ('' for
     * none).
     *
     * @param  list<int>  $employeeIds
     * @return array<string, list<array{cost_center: string, start: CarbonImmutable, end: CarbonImmutable, minutes: int}>>
     */
    private function costCenterShifts(CarbonImmutable $from, CarbonImmutable $to, array $employeeIds): array
    {
        if ($employeeIds === []) {
            return [];
        }

        $days = [];
        $shifts = ShiftQuery::startingOn($from, $to)
            ->whereIn('status', [Shift::PUBLISHED, Shift::DRAFT])
            ->get(['id', 'employee_id', 'starts_at', 'ends_at', 'break_minutes', 'status', 'published_at', 'published_snapshot', 'cost_center_id']);

        foreach ($shifts as $shift) {
            if ($shift->status === Shift::PUBLISHED) {
                $employeeId = $shift->employee_id;
                $interval = new WorkInterval($shift->starts_at, $shift->ends_at, $shift->break_minutes);
            } else {
                $snapshot = $shift->published_snapshot;

                if ($shift->published_at === null || ! is_array($snapshot) || ! is_numeric($snapshot['employee_id'] ?? null)) {
                    continue;
                }

                $employeeId = (int) $snapshot['employee_id'];
                $interval = WorkInterval::fromArray(['start' => $snapshot['starts_at'] ?? null, 'end' => $snapshot['ends_at'] ?? null, 'break_minutes' => $snapshot['break_minutes'] ?? 0]);
            }

            if ($employeeId === null || ! in_array($employeeId, $employeeIds, true) || $interval->start === null || $interval->end === null || ! $interval->isComplete()) {
                continue;
            }

            $key = "{$employeeId}|{$interval->anchor()->setTimezone(LocalTimestamp::ZONE)->toDateString()}";
            $days[$key][] = ['cost_center' => CostCenterShares::key($shift->cost_center_id), 'start' => $interval->start, 'end' => $interval->end, 'minutes' => $interval->workedMinutes()];
        }

        return $days;
    }

    /**
     * A pay code's minutes of a day as hours per cost centre: all on the one
     * cost centre of the day's shifts, or split over several in proportion
     * to their planned minutes, in hundredths of an hour by the largest
     * remainder (CostCenterShares::split), so the parts sum to the hours one
     * report would have held. Parts of 0,00 hours are left out.
     *
     * @param  array<string, int>  $weights  planned minutes per cost centre ('' for none)
     * @return list<array{?int, string}> cost centre id (null: the employment's) and hours
     */
    public static function hoursPerCostCenter(int $minutes, array $weights): array
    {
        $hours = BigDecimal::of($minutes)->dividedBy(60, 2, RoundingMode::HalfUp);
        $weights = array_filter($weights, fn (int $weight): bool => $weight > 0);

        if (count($weights) <= 1) {
            $key = (string) array_key_first($weights);

            return [[$key === '' ? null : (int) $key, (string) $hours]];
        }

        $parts = [];

        foreach (CostCenterShares::split($hours->getUnscaledValue()->toInt(), $weights) as $key => $hundredths) {
            if ($hundredths === 0) {
                continue;
            }

            $parts[] = [(string) $key === '' ? null : (int) $key, (string) BigDecimal::ofUnscaledValue($hundredths, 2)];
        }

        return $parts;
    }

    /** Why the day cannot be sent: it is already in another time report or a pending ledger proposal. */
    private function conflict(Attestation $attestation): ?string
    {
        $reported = TimeReport::query()
            ->where('employee_id', $attestation->employee_id)
            ->whereDate('date', $attestation->date->toDateString())
            ->where('status', '<>', 'REJECTED')
            ->exists();

        if ($reported) {
            return __('Dagen finns redan i en tidrapport i lönen (till exempel godkänd från personalliggaren). Den skickas inte en gång till.');
        }

        return null;
    }
}
