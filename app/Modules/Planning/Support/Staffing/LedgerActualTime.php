<?php

declare(strict_types=1);

namespace App\Modules\Planning\Support\Staffing;

use App\Models\Company;
use App\Modules\ModuleManager;
use App\Modules\Planning\Support\LocalTimestamp;
use App\Modules\StaffLedger\Domain\LedgerQuery;
use App\Modules\StaffLedger\Models\Person;
use App\Modules\StaffLedger\StaffLedgerModule;
use Carbon\CarbonImmutable;

/**
 * Actual time from the staff ledger's shifts (check-in to check-out, with
 * corrections applied), read only (PLANNING_MODULE.md §1). The ledger
 * records presence, so breaks are not deducted; a check-in without a
 * check-out is left out and counted. Covers the days up to today while the
 * ledger is on. Three queries.
 */
final class LedgerActualTime implements ActualTime
{
    public function __construct(private readonly ModuleManager $modules) {}

    public function key(): string
    {
        return 'ledger';
    }

    public function between(Company $company, CarbonImmutable $from, CarbonImmutable $to): ?ActualPeriod
    {
        if (! $this->modules->isEnabled(StaffLedgerModule::KEY, $company)) {
            return null;
        }

        $today = CarbonImmutable::today(LocalTimestamp::ZONE);
        $last = $to->min($today);

        if ($last->lessThan($from)) {
            return new ActualPeriod([], []);
        }

        $days = [];

        for ($day = $from; $day->lessThanOrEqualTo($last); $day = $day->addDay()) {
            $days[] = $day->toDateString();
        }

        $start = $from->setTimezone(LocalTimestamp::ZONE)->startOfDay();
        $end = $last->setTimezone(LocalTimestamp::ZONE)->endOfDay();
        $shifts = LedgerQuery::shifts(null, $start, $end);
        $personIds = array_values(array_unique(array_filter(array_map(fn ($shift): ?int => $shift->personId, $shifts))));
        $employees = $personIds === [] ? collect() : Person::query()->whereKey($personIds)->pluck('employee_id', 'id');
        $work = [];
        $open = 0;

        foreach ($shifts as $shift) {
            if ($shift->start === null) {
                continue;
            }

            $begins = $shift->start->setTimezone(LocalTimestamp::ZONE);

            if ($begins->lessThan($start) || $begins->greaterThan($end)) {
                continue;
            }

            if ($shift->end === null) {
                $open++;

                continue;
            }

            $employeeId = $shift->personId === null ? null : $employees->get($shift->personId);

            $work[] = new ActualWork(
                day: $begins->toDateString(),
                employeeId: is_numeric($employeeId) ? (int) $employeeId : null,
                minutes: max(0, intdiv($shift->end->getTimestamp() - $shift->start->getTimestamp(), 60)),
                start: $begins,
                end: $shift->end->setTimezone(LocalTimestamp::ZONE),
                locationId: $shift->locationId,
            );
        }

        return new ActualPeriod($days, $work, $open);
    }
}
