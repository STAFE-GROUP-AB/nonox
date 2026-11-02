<?php

declare(strict_types=1);

namespace App\Modules\Planning\Actions;

use App\Models\Company;
use App\Models\User;
use App\Modules\Planning\Actions\Concerns\GuardsPlanning;
use App\Modules\Planning\Actions\Concerns\WritesAttestations;
use App\Modules\Planning\Support\AttestData;
use App\Modules\Planning\Support\PlanningWebhooks;
use App\Support\Audit\Audit;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Attests the open days of a period at once (PLANNING_MODULE.md §6, §7):
 * every day, one person's days, or only the days without deviations ("alla
 * utan avvikelser"). Days with a blocking deviation are left open and
 * counted; attested and sent days are not touched.
 */
final class AttestPeriod
{
    use GuardsPlanning;
    use WritesAttestations;

    public const int MAX_DAYS = 62;

    public function __construct(private readonly AttestData $data) {}

    /**
     * @return array{attested: int, skipped: int}
     */
    public function __invoke(User $user, Company $company, CarbonImmutable $from, CarbonImmutable $to, ?int $employeeId = null, bool $onlyWithoutDeviations = false): array
    {
        $this->guard($user, $company, 'attest');

        if ($to->lessThan($from) || $from->diffInDays($to) > self::MAX_DAYS) {
            throw ValidationException::withMessages(['period' => __('Välj en period på högst två månader.')]);
        }

        return DB::transaction(function () use ($user, $company, $from, $to, $employeeId, $onlyWithoutDeviations): array {
            $attested = [];
            $skipped = 0;
            $minutes = 0;

            foreach ($this->data->load($company, $from, $to, $employeeId)['rows'] as $row) {
                if (! $row->isOpen()) {
                    continue;
                }

                if (! $row->canAttest() || ($onlyWithoutDeviations && $row->hasDeviations())) {
                    $skipped++;

                    continue;
                }

                $attestation = $this->attestRow($user, $row);
                $attested[$attestation->id] = $attestation->employee_id;
                $minutes += $attestation->actual_minutes;
            }

            if ($attested !== []) {
                Audit::record('planning.period_attested', null, [
                    'from' => $from->toDateString(),
                    'to' => $to->toDateString(),
                    'employee_id' => $employeeId,
                    'only_without_deviations' => $onlyWithoutDeviations,
                    'attestation_ids' => array_keys($attested),
                    'skipped' => $skipped,
                ], $company, $user);
                PlanningWebhooks::attested($company, $from, $to, array_keys($attested), array_values($attested), $minutes);
            }

            return ['attested' => count($attested), 'skipped' => $skipped];
        });
    }
}
