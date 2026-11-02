<?php

declare(strict_types=1);

namespace App\Modules\Planning\Actions;

use App\Models\Company;
use App\Models\User;
use App\Modules\Planning\Actions\Concerns\GuardsPlanning;
use App\Modules\Planning\Models\Shift;
use App\Modules\Planning\Support\ShiftCoster;
use App\Modules\Planning\Support\ShiftQuery;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Recalculates the cost estimates of a period's shifts, for example after a
 * salary change or a newly verified rule. Only the estimate changes, so the
 * shifts keep their status and nothing is audited.
 */
final class RecalculateCosts
{
    use GuardsPlanning;

    public function __construct(private readonly ShiftCoster $coster) {}

    /** @return int the number of shifts recalculated */
    public function __invoke(User $user, Company $company, CarbonImmutable $from, CarbonImmutable $to): int
    {
        $this->guard($user, $company);
        $shifts = ShiftQuery::startingOn($from, $to)->where('status', '<>', Shift::CANCELLED)->get();

        DB::transaction(function () use ($company, $shifts): void {
            $this->coster->apply($company, $shifts);
            $shifts->each(fn (Shift $shift): bool => $shift->save());
        });

        return $shifts->count();
    }
}
