<?php

declare(strict_types=1);

namespace App\Modules\Planning\Actions;

use App\Models\Company;
use App\Models\User;
use App\Modules\Payroll\Models\Employee;
use App\Modules\Planning\Actions\Concerns\GuardsPlanning;
use App\Modules\Planning\Actions\Concerns\WritesAttestations;
use App\Modules\Planning\Domain\Deviation;
use App\Modules\Planning\Models\Attestation;
use App\Modules\Planning\Support\AttestData;
use App\Modules\Planning\Support\AttestRow;
use App\Modules\Planning\Support\PlanningWebhooks;
use App\Support\Audit\Audit;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Attests one employee's day (PLANNING_MODULE.md §6): the plan, the actual
 * time and the payroll basis are frozen as they are now. A day with a
 * blocking deviation (a missing check-out, time already reported) is
 * refused until it is fixed. Attesting an attested day changes nothing.
 * Sending the basis to payroll is SendToPayroll.
 */
final class AttestDay
{
    use GuardsPlanning;
    use WritesAttestations;

    public function __construct(private readonly AttestData $data) {}

    public function __invoke(User $user, Company $company, int $employeeId, CarbonImmutable $date): Attestation
    {
        $this->guard($user, $company, 'attest');
        Employee::query()->findOrFail($employeeId);

        return DB::transaction(function () use ($user, $company, $employeeId, $date): Attestation {
            $row = collect($this->data->load($company, $date, $date, $employeeId)['rows'])->first();

            if (! $row instanceof AttestRow) {
                throw ValidationException::withMessages(['day' => __('Det finns ingen planerad eller arbetad tid att attestera den dagen.')]);
            }

            if (! $row->isOpen() && $row->attestation !== null) {
                return $row->attestation;
            }

            foreach ($row->deviations as $deviation) {
                if ($deviation->blocking) {
                    throw ValidationException::withMessages(['day' => $deviation->message]);
                }
            }

            $attestation = $this->attestRow($user, $row);
            Audit::record('planning.day_attested', $attestation, [
                'employee_id' => $employeeId,
                'date' => $row->date->toDateString(),
                'basis' => $attestation->basis,
                'deviations' => array_map(fn (Deviation $deviation): string => $deviation->code, $row->deviations),
            ], $company, $user);
            PlanningWebhooks::attested($company, $row->date, $row->date, [$attestation->id], [$employeeId], $attestation->actual_minutes);

            return $attestation;
        });
    }
}
