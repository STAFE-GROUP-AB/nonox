<?php

declare(strict_types=1);

namespace App\Modules\Planning\Actions;

use App\Models\Company;
use App\Models\User;
use App\Modules\Planning\Actions\Concerns\GuardsPlanning;
use App\Modules\Planning\Models\Shift;
use App\Modules\Planning\Support\ShiftFactory;
use App\Modules\Planning\Support\ShiftQuery;
use App\Support\Audit\Audit;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Copies one or more weeks of the schedule to later (or earlier) weeks,
 * repeated up to eight times, with or without the people. The copies are
 * drafts with the same wall-clock times; a shift that already exists is not
 * copied again. Cancelled shifts are not copied. Audited.
 */
final class CopyPeriod
{
    use GuardsPlanning;

    public const int MAX_WEEKS = 8;

    public function __construct(private readonly ShiftFactory $factory) {}

    /** @return int the number of shifts created */
    public function __invoke(User $user, Company $company, CarbonImmutable $sourceWeek, int $weeks, CarbonImmutable $targetWeek, int $times = 1, bool $withPeople = true): int
    {
        $this->guard($user, $company);
        $source = $sourceWeek->startOfWeek(CarbonImmutable::MONDAY)->startOfDay();
        $target = $targetWeek->startOfWeek(CarbonImmutable::MONDAY)->startOfDay();

        if ($weeks < 1 || $weeks > self::MAX_WEEKS || $times < 1 || $times > self::MAX_WEEKS) {
            throw ValidationException::withMessages(['weeks' => __('Välj mellan 1 och :max veckor.', ['max' => self::MAX_WEEKS])]);
        }

        $offset = (int) round($source->diffInDays($target) / 7);

        if ($offset === 0 || ($offset > 0 && $offset < $weeks) || ($offset < 0 && -$offset < $weeks * $times)) {
            throw ValidationException::withMessages(['target' => __('Kopian får inte hamna i veckorna som kopieras.')]);
        }

        $shifts = ShiftQuery::startingOn($source, $source->addWeeks($weeks)->subDay())->where('status', '<>', Shift::CANCELLED)->orderBy('starts_at')->get();
        $planned = [];

        for ($repeat = 0; $repeat < $times; $repeat++) {
            $add = $offset + $repeat * $weeks;

            foreach ($shifts as $shift) {
                $planned[] = [
                    'employee_id' => $withPeople ? $shift->employee_id : null,
                    'area_id' => $shift->area_id,
                    'skill_id' => $shift->skill_id,
                    'starts_at' => $shift->starts_at->addWeeks($add),
                    'ends_at' => $shift->ends_at->addWeeks($add),
                    'break_minutes' => $shift->break_minutes,
                    'note' => $shift->note,
                ];
            }
        }

        return DB::transaction(function () use ($user, $company, $planned, $source, $target, $weeks, $times, $withPeople): int {
            $created = $this->factory->create($user, $company, $planned, 'COPY');

            Audit::record('planning.period_copied', null, [
                'from' => $source->toDateString(),
                'weeks' => $weeks,
                'to' => $target->toDateString(),
                'times' => $times,
                'with_people' => $withPeople,
                'created' => count($created),
            ], $company, $user);

            return count($created);
        });
    }
}
