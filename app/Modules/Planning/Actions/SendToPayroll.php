<?php

declare(strict_types=1);

namespace App\Modules\Planning\Actions;

use App\Models\Company;
use App\Models\User;
use App\Modules\ModuleManager;
use App\Modules\Payroll\Actions\PayrollRuns;
use App\Modules\Payroll\Models\PayCode;
use App\Modules\Payroll\Models\PayrollRun;
use App\Modules\Payroll\Models\TimeReport;
use App\Modules\Payroll\PayrollModule;
use App\Modules\Planning\Actions\Concerns\GuardsPlanning;
use App\Modules\Planning\Models\Attestation;
use App\Modules\Planning\Support\AttestData;
use App\Modules\Planning\Support\AttestRow;
use App\Support\Audit\Audit;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * "Skicka till lönen" (PLANNING_MODULE.md §8): the attested days of a
 * period become approved time reports, one per day and pay code (hours with
 * two decimals), the same rows the staff ledger's proposals and the payroll
 * files feed, so the payroll run picks them up unchanged. The day is then
 * sent and locked. A day is skipped, with the reason, when its month's run
 * is already attested, when another time report or a pending ledger
 * proposal covers it, or when a pay code is missing. Sending a sent day
 * again changes nothing. A calculated run for the month goes back to draft.
 */
final class SendToPayroll
{
    use GuardsPlanning;

    public function __construct(private readonly AttestData $data, private readonly PayrollRuns $runs) {}

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

                foreach ($attestation->basis ?? [] as $code => $minutes) {
                    $ids[] = TimeReport::create([
                        'employee_id' => $attestation->employee_id,
                        'date' => $attestation->date->toDateString(),
                        'hours' => (string) BigDecimal::of($minutes)->dividedBy(60, 2, RoundingMode::HalfUp),
                        'pay_code_id' => $codes->get($code),
                        'status' => 'APPROVED',
                    ])->id;
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

            foreach ($all->filter(fn (AttestRow $row): bool => $row->week !== null && $row->week->minutes !== []) as $row) {
                $result = $this->sendWeek($user, $row, $codes);

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
    private function sendWeek(User $user, AttestRow $row, Collection $codes): int|string
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

        $missing = array_diff(array_keys($week->minutes), $codes->keys()->all());

        if ($missing !== []) {
            return __('Lönearten :code finns inte i lönen.', ['code' => implode(', ', $missing)]);
        }

        $ids = [];
        $basis = $attestation->basis ?? [];

        foreach ($week->minutes as $code => $minutes) {
            $ids[] = TimeReport::create([
                'employee_id' => $attestation->employee_id,
                'date' => $attestation->date->toDateString(),
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
            ]],
            'updated_by' => $user->id,
        ])->save();

        return count($ids);
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
