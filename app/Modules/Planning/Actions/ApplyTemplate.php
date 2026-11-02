<?php

declare(strict_types=1);

namespace App\Modules\Planning\Actions;

use App\Models\Company;
use App\Models\User;
use App\Modules\Planning\Actions\Concerns\GuardsPlanning;
use App\Modules\Planning\Models\Template;
use App\Modules\Planning\Support\LocalTimestamp;
use App\Modules\Planning\Support\ShiftFactory;
use App\Support\Audit\Audit;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Lays a template onto the schedule from a given week, once or repeated,
 * with or without its people. Creates drafts; a shift that already exists
 * is not created again. Audited.
 */
final class ApplyTemplate
{
    use GuardsPlanning;

    public const int MAX_TIMES = 8;

    public function __construct(private readonly ShiftFactory $factory) {}

    /** @return int the number of shifts created */
    public function __invoke(User $user, Company $company, Template $template, CarbonImmutable $firstWeek, int $times = 1, bool $withPeople = true): int
    {
        $this->guard($user, $company);

        if ($times < 1 || $times > self::MAX_TIMES) {
            throw ValidationException::withMessages(['times' => __('Lägg ut mallen mellan 1 och :max gånger.', ['max' => self::MAX_TIMES])]);
        }

        $monday = $firstWeek->setTimezone(LocalTimestamp::ZONE)->startOfWeek(CarbonImmutable::MONDAY)->startOfDay();
        $planned = [];

        for ($repeat = 0; $repeat < $times; $repeat++) {
            foreach ($template->shifts as $shift) {
                $day = $monday->addWeeks($repeat * $template->weeks + $shift->week_index)->addDays($shift->weekday - 1)->toDateString();
                $start = CarbonImmutable::parse("{$day} {$shift->start}", LocalTimestamp::ZONE);
                $end = CarbonImmutable::parse("{$day} {$shift->end}", LocalTimestamp::ZONE);

                $planned[] = [
                    'employee_id' => $withPeople ? $shift->employee_id : null,
                    'area_id' => $shift->area_id,
                    'skill_id' => $shift->skill_id,
                    'starts_at' => $start,
                    'ends_at' => $end->lessThanOrEqualTo($start) ? CarbonImmutable::parse($end->addDay()->toDateString()." {$shift->end}", LocalTimestamp::ZONE) : $end,
                    'break_minutes' => $shift->break_minutes,
                    'note' => $shift->note,
                    'template_id' => $template->id,
                ];
            }
        }

        return DB::transaction(function () use ($user, $company, $template, $planned, $monday, $times, $withPeople): int {
            $created = $this->factory->create($user, $company, $planned, 'TEMPLATE');
            Audit::record('planning.template_applied', $template, ['from' => $monday->toDateString(), 'times' => $times, 'with_people' => $withPeople, 'created' => count($created)], $company, $user);

            return count($created);
        });
    }
}
