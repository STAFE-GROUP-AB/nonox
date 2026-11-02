<?php

declare(strict_types=1);

namespace App\Modules\Planning\Support;

use App\Models\Company;
use App\Models\User;
use App\Modules\Planning\Models\Shift;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Creates draft shifts in bulk for CopyPeriod and ApplyTemplate: skips a
 * shift that already exists (same person, area and times), estimates the
 * cost of all of them in one go, and saves them.
 */
final class ShiftFactory
{
    public function __construct(private readonly ShiftCoster $coster) {}

    /**
     * @param  list<array{employee_id: ?int, area_id: ?int, skill_id: ?int, starts_at: CarbonImmutable, ends_at: CarbonImmutable, break_minutes: int, note: ?string, template_id?: ?int}>  $planned
     * @return list<Shift> the shifts created
     */
    public function create(User $user, Company $company, array $planned, string $source): array
    {
        if ($planned === []) {
            return [];
        }

        $first = min(array_map(fn (array $shift): CarbonImmutable => $shift['starts_at'], $planned));
        $last = max(array_map(fn (array $shift): CarbonImmutable => $shift['starts_at'], $planned));
        $existing = ShiftQuery::startingOn($first, $last)->where('status', '<>', Shift::CANCELLED)->get(['employee_id', 'area_id', 'starts_at', 'ends_at'])
            ->map(fn (Shift $shift): string => self::key($shift->employee_id, $shift->area_id, $shift->starts_at, $shift->ends_at))
            ->flip();

        $shifts = [];

        foreach ($planned as $attributes) {
            $key = self::key($attributes['employee_id'], $attributes['area_id'], $attributes['starts_at'], $attributes['ends_at']);

            if ($existing->has($key) || $attributes['ends_at']->lessThanOrEqualTo($attributes['starts_at'])) {
                continue;
            }

            $existing->put($key, true);
            $shifts[] = new Shift([...$attributes, 'status' => Shift::DRAFT, 'source' => $source, 'created_by' => $user->id, 'updated_by' => $user->id]);
        }

        DB::transaction(function () use ($company, $shifts): void {
            $this->coster->apply($company, $shifts);

            foreach ($shifts as $shift) {
                $shift->save();
            }
        });

        return $shifts;
    }

    private static function key(?int $employeeId, ?int $areaId, CarbonImmutable $start, CarbonImmutable $end): string
    {
        return ($employeeId ?? 'open').'|'.($areaId ?? '-').'|'.$start->getTimestamp().'|'.$end->getTimestamp();
    }
}
