<?php

declare(strict_types=1);

namespace App\Modules\Planning\Actions;

use App\Models\Company;
use App\Models\User;
use App\Modules\Payroll\Actions\PayrollRuns;
use App\Modules\Payroll\Models\PayrollRun;
use App\Modules\Payroll\Models\TimeReport;
use App\Modules\Planning\Actions\Concerns\GuardsPlanning;
use App\Modules\Planning\Models\Attestation;
use App\Support\Audit\Audit;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Opens an attested or sent day again (PLANNING_MODULE.md §6, §8). A sent
 * day's time reports are removed, which is allowed only while the payroll
 * run that pays them is not attested; a calculated run goes back to draft
 * first. Once the run is attested the day stays as it was and a correction
 * goes into the next run. The manager's own actual time is kept.
 */
final class ReopenAttestation
{
    use GuardsPlanning;

    public function __construct(private readonly PayrollRuns $runs) {}

    public function __invoke(User $user, Company $company, Attestation $attestation): Attestation
    {
        $this->guard($user, $company, 'attest');

        return DB::transaction(function () use ($user, $company, $attestation): Attestation {
            /** @var Attestation $attestation */
            $attestation = Attestation::query()->whereKey($attestation->id)->lockForUpdate()->firstOrFail();

            if ($attestation->status === Attestation::OPEN) {
                throw ValidationException::withMessages(['day' => __('Dagen är inte attesterad.')]);
            }

            $before = $attestation->only(['status', 'basis', 'time_report_ids']);
            $ids = $attestation->time_report_ids ?? [];
            $this->removeReports($ids);
            $this->withdrawWeek($attestation);

            $attestation->forceFill([
                'status' => Attestation::OPEN,
                'planned_minutes' => 0,
                'actual_minutes' => 0,
                'basis' => null,
                'deviations' => null,
                'details' => null,
                'time_report_ids' => null,
                'attested_by' => null,
                'attested_at' => null,
                'sent_by' => null,
                'sent_at' => null,
                'updated_by' => $user->id,
            ])->save();

            Audit::record('planning.attestation_reopened', $attestation, [
                'employee_id' => $attestation->employee_id,
                'date' => $attestation->date->toDateString(),
                'before' => $before,
                'time_reports_removed' => count($ids),
            ], $company, $user);

            return $attestation;
        });
    }

    /**
     * Removes time reports, only while no attested run pays them; a
     * calculated run goes back to draft first.
     *
     * @param  list<int>  $ids
     */
    private function removeReports(array $ids): void
    {
        if ($ids === []) {
            return;
        }

        $reports = TimeReport::query()->whereIn('id', $ids)->lockForUpdate()->get();
        $runs = PayrollRun::query()->whereIn('id', $reports->pluck('payroll_run_id')->filter()->unique()->values())->lockForUpdate()->get();
        $closed = $runs->first(fn (PayrollRun $run): bool => ! $run->isOpen());

        if ($closed instanceof PayrollRun) {
            throw ValidationException::withMessages(['day' => __('Tiden är betald i lönekörningen för :month, som är attesterad. Dagen kan inte öppnas igen; lägg en rättelse i nästa lönekörning.', ['month' => CarbonImmutable::parse("{$closed->period}-01")->translatedFormat('F Y')])]);
        }

        $runs->filter(fn (PayrollRun $run): bool => $run->status === PayrollRun::CALCULATED)->each(fn (PayrollRun $run) => $this->runs->backToDraft($run));
        TimeReport::query()->whereIn('id', $ids)->delete();
    }

    /**
     * A week's overtime and mertid was sent with another day of the same
     * week: reopening a day makes the week incomplete, so that is taken back
     * too and counted again when the week is attested again.
     */
    private function withdrawWeek(Attestation $attestation): void
    {
        $monday = $attestation->date->startOfWeek(CarbonImmutable::MONDAY);
        $carriers = Attestation::query()
            ->where('employee_id', $attestation->employee_id)
            ->whereKeyNot($attestation->id)
            ->whereDate('date', '>=', $monday->toDateString())
            ->whereDate('date', '<=', $monday->addDays(6)->toDateString())
            ->whereNotNull('details->week')
            ->lockForUpdate()
            ->get();

        foreach ($carriers as $carrier) {
            $week = $carrier->details['week'] ?? ['minutes' => [], 'time_report_ids' => []];
            $ids = $week['time_report_ids'];
            $this->removeReports($ids);
            $basis = $carrier->basis ?? [];

            foreach ($week['minutes'] as $code => $minutes) {
                $basis[$code] = ($basis[$code] ?? 0) - $minutes;
            }

            $details = $carrier->details ?? [];
            unset($details['week']);
            $carrier->forceFill([
                'basis' => array_filter($basis, fn (int $value): bool => $value > 0),
                'time_report_ids' => array_values(array_diff($carrier->time_report_ids ?? [], $ids)),
                'details' => $details,
            ])->save();
        }
    }
}
