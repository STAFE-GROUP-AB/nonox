<?php

declare(strict_types=1);

namespace App\Modules\Manufacturing\Actions\Planning;

use App\Models\Company;
use App\Models\User;
use App\Modules\Manufacturing\Actions\Concerns\GuardsManufacturing;
use App\Modules\Manufacturing\Domain\DeviationType;
use App\Modules\Manufacturing\Domain\DowntimeReason;
use App\Modules\Manufacturing\Domain\PlanTrigger;
use App\Modules\Manufacturing\Models\Downtime;
use App\Modules\Manufacturing\Models\Workstation;
use App\Modules\Manufacturing\Planning\Engine\Clock;
use App\Modules\Manufacturing\Planning\PlanTriggers;
use App\Support\Audit\Audit;
use App\Support\Tenancy\CurrentCompany;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * Records that a workstation is out of use (ADR-071 §13.2, §13.5): from a
 * start to an expected end, or until further notice. Raises a
 * WORKSTATION_DOWN deviation and a new plan shows what it does to the
 * deliveries. Ending it early is a change of the expected end.
 */
class SaveDowntime
{
    use GuardsManufacturing;

    /**
     * @param  array<string, mixed>  $input  starts_at, ends_at ("Y-m-d H:i", local; ends_at optional), reason
     */
    public function __invoke(User $user, Company $company, Workstation $workstation, array $input, string $source = Downtime::MANUAL): Downtime
    {
        $this->guard($user, $company, 'plan');

        $start = self::moment($input['starts_at'] ?? null, 'starts_at') ?? throw ValidationException::withMessages(['starts_at' => __('Ange när stoppet börjar.')]);
        $end = self::moment($input['ends_at'] ?? null, 'ends_at');
        $reason = DowntimeReason::tryFrom((string) ($input['reason'] ?? '')) ?? DowntimeReason::Breakdown;

        if ($end !== null && $end->lessThanOrEqualTo($start)) {
            throw ValidationException::withMessages(['ends_at' => __('Stoppet måste sluta efter att det börjat.')]);
        }

        $downtime = CurrentCompany::run($company, fn (): Downtime => DB::transaction(function () use ($user, $company, $workstation, $start, $end, $reason, $source): Downtime {
            $workstation = Workstation::query()->findOrFail($workstation->id);

            $downtime = Downtime::create([
                'workstation_id' => $workstation->id,
                'starts_at' => $start,
                'ends_at' => $end,
                'reason' => $reason,
                'source' => $source,
                'created_by' => $user->id,
            ]);

            Audit::record('manufacturing.downtime_recorded', $downtime, ['workstation_id' => $workstation->id, 'reason' => $reason->value, 'source' => $source], $company, $user);

            return $downtime;
        }));

        PlanTriggers::deviation($company->id, DeviationType::WorkstationDown, $downtime, [
            'starts_on' => $start->toDateString(),
            'ends_on' => $end?->toDateString(),
            'details' => ['workstation_id' => $workstation->id, 'reason' => $reason->value],
        ], PlanTrigger::Downtime);

        return $downtime;
    }

    /** The workstation works again from now. */
    public function end(User $user, Company $company, Downtime $downtime): Downtime
    {
        $this->guard($user, $company, 'plan');

        return CurrentCompany::run($company, function () use ($user, $company, $downtime): Downtime {
            $downtime = Downtime::query()->findOrFail($downtime->id);
            $now = CarbonImmutable::now(Clock::ZONE)->startOfMinute();
            $downtime->forceFill(['ends_at' => $now->greaterThan($downtime->starts_at) ? $now : $downtime->starts_at->addMinute()])->save();

            Audit::record('manufacturing.downtime_ended', $downtime, ['workstation_id' => $downtime->workstation_id], $company, $user);
            PlanTriggers::recompute($company->id, PlanTrigger::Downtime);

            return $downtime;
        });
    }

    private static function moment(mixed $value, string $field): ?CarbonImmutable
    {
        $text = str_replace('T', ' ', trim((string) $value));

        if ($text === '') {
            return null;
        }

        try {
            return CarbonImmutable::createFromFormat('Y-m-d H:i', $text, Clock::ZONE) ?: throw ValidationException::withMessages([$field => __('Skriv datum och tid, till exempel 2026-11-18 07:00.')]);
        } catch (Throwable) {
            throw ValidationException::withMessages([$field => __('Skriv datum och tid, till exempel 2026-11-18 07:00.')]);
        }
    }
}
