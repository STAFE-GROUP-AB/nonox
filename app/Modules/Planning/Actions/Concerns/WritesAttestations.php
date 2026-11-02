<?php

declare(strict_types=1);

namespace App\Modules\Planning\Actions\Concerns;

use App\Models\User;
use App\Modules\Planning\Domain\Deviation;
use App\Modules\Planning\Domain\WorkInterval;
use App\Modules\Planning\Models\Attestation;
use App\Modules\Planning\Support\AttestRow;
use Carbon\CarbonImmutable;

/**
 * Freezes a day as the manager saw it on "Tid att attestera": planned and
 * actual minutes, the payroll basis, the deviations and the intervals behind
 * them. Call inside a transaction.
 */
trait WritesAttestations
{
    private function attestRow(User $user, AttestRow $row): Attestation
    {
        $attestation = Attestation::query()
            ->where('employee_id', $row->employee->id)
            ->whereDate('date', $row->date->toDateString())
            ->lockForUpdate()
            ->first() ?? new Attestation(['employee_id' => $row->employee->id, 'date' => $row->date->toDateString()]);

        $attestation->forceFill([
            'status' => Attestation::ATTESTED,
            'planned_minutes' => $row->comparison->plannedMinutes(),
            'actual_minutes' => $row->comparison->actualMinutes(),
            'basis' => $row->basis->minutes,
            'deviations' => array_map(fn (Deviation $deviation): array => $deviation->toArray(), $row->deviations),
            'details' => [
                'planned' => array_map(fn (WorkInterval $interval): array => $interval->toArray(), $row->comparison->planned),
                'actual' => array_map(fn (WorkInterval $interval): array => $interval->toArray(), $row->comparison->actual),
                'source' => $row->comparison->source,
                'ob' => $row->basis->ob,
            ],
            'attested_by' => $user->id,
            'attested_at' => CarbonImmutable::now(),
            'updated_by' => $user->id,
        ])->save();

        return $attestation;
    }
}
