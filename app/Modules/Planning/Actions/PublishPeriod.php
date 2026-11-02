<?php

declare(strict_types=1);

namespace App\Modules\Planning\Actions;

use App\Models\Company;
use App\Models\User;
use App\Modules\ModuleManager;
use App\Modules\Payroll\Models\Employee;
use App\Modules\Planning\Actions\Concerns\GuardsPlanning;
use App\Modules\Planning\Models\Availability;
use App\Modules\Planning\Models\Publication;
use App\Modules\Planning\Models\Shift;
use App\Modules\Planning\Notifications\ScheduleNotice;
use App\Modules\Planning\PlanningModule;
use App\Modules\Planning\PlanningSettings;
use App\Modules\Planning\Support\LocalTimestamp;
use App\Modules\Planning\Support\PlanningWebhooks;
use App\Modules\Planning\Support\RequestChecks;
use App\Modules\Planning\Support\ScheduleNotifier;
use App\Modules\Planning\Support\ShiftQuery;
use App\Support\Audit\Audit;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Publishes the drafts of a period (PLANNING_MODULE.md §6): they become
 * published, what the employee is shown is kept, a publication row records
 * it, and every employee whose schedule changed is sent their shifts for the
 * period (when the company notifies on publish). Audited.
 */
final class PublishPeriod
{
    use GuardsPlanning;

    public function __construct(private readonly ScheduleNotifier $notifier) {}

    public function __invoke(User $user, Company $company, CarbonImmutable $from, CarbonImmutable $to): Publication
    {
        $this->guard($user, $company);
        [$from, $to] = self::days($from, $to);

        return DB::transaction(function () use ($user, $company, $from, $to): Publication {
            $drafts = self::drafts($from, $to)->lockForUpdate()->get();

            if ($drafts->isEmpty()) {
                throw ValidationException::withMessages(['period' => __('Det finns inga ändringar att publicera för perioden.')]);
            }

            $now = CarbonImmutable::now();
            $changes = [];
            $affected = [];

            foreach ($drafts as $shift) {
                $previous = $shift->published_snapshot['employee_id'] ?? null;
                $changes[$shift->id] = $shift->published_at === null ? __('nytt') : __('ändrat');

                foreach ([$shift->employee_id, $previous] as $employeeId) {
                    if (is_int($employeeId)) {
                        $affected[$employeeId] = true;
                    }
                }

                $shift->forceFill([
                    'status' => Shift::PUBLISHED,
                    'published_at' => $now,
                    'published_by' => $user->id,
                    'published_snapshot' => $shift->snapshot(),
                ])->save();
            }

            $notified = 0;
            $settings = PlanningSettings::resolve(app(ModuleManager::class)->settings(PlanningModule::KEY, $company));

            if ($affected !== [] && PlanningSettings::bool($settings, 'notify_on_publish')) {
                $schedule = ShiftQuery::startingOn($from, $to)
                    ->where('status', Shift::PUBLISHED)
                    ->whereIn('employee_id', array_keys($affected))
                    ->orderBy('starts_at')
                    ->get()
                    ->groupBy('employee_id');

                $byEmployee = [];

                foreach (array_keys($affected) as $employeeId) {
                    $byEmployee[$employeeId] = array_values($schedule->get($employeeId)?->all() ?? []);
                }

                $notified = $this->notifier->send($company, $user, ScheduleNotice::PUBLISHED, ScheduleNotifier::period($from, $to), $byEmployee, $changes);
            }

            $publication = Publication::create([
                'from' => $from->toDateString(),
                'to' => $to->toDateString(),
                'published_by' => $user->id,
                'published_at' => $now,
                'shift_count' => $drafts->count(),
                'notified_count' => $notified,
            ]);

            Audit::record('planning.period_published', $publication, [
                'from' => $from->toDateString(),
                'to' => $to->toDateString(),
                'shifts' => $drafts->pluck('id')->all(),
                'notified' => $notified,
            ], $company, $user);

            PlanningWebhooks::published($publication);

            return $publication;
        });
    }

    /**
     * What Publicera would do, for the confirmation: new and changed shifts,
     * open shifts, shifts at a time the person said does not suit them, and
     * who is told and who cannot be reached. Three queries.
     *
     * @return array{new: int, changed: int, open: int, unavailable: int, notify: list<string>, unreachable: list<string>}
     */
    public static function summary(CarbonImmutable $from, CarbonImmutable $to): array
    {
        [$from, $to] = self::days($from, $to);
        $drafts = self::drafts($from, $to)->get(['id', 'employee_id', 'starts_at', 'ends_at', 'published_at', 'published_snapshot']);
        $ids = $drafts->flatMap(fn (Shift $shift): array => [$shift->employee_id, $shift->published_snapshot['employee_id'] ?? null])->filter(fn (mixed $id): bool => is_int($id))->unique()->values();
        $employees = $ids->isEmpty() ? collect() : Employee::query()->whereKey($ids)->orderBy('last_name')->orderBy('first_name')->get(['id', 'first_name', 'last_name', 'email', 'user_id']);
        $name = fn (Employee $employee): string => "{$employee->first_name} {$employee->last_name}";
        $assigned = $drafts->pluck('employee_id')->filter()->unique()->values();
        $availability = $assigned->isEmpty() ? collect() : Availability::query()->whereIn('employee_id', $assigned)->get()->groupBy('employee_id');

        return [
            'new' => $drafts->whereNull('published_at')->count(),
            'changed' => $drafts->whereNotNull('published_at')->count(),
            'open' => $drafts->whereNull('employee_id')->count(),
            'unavailable' => $drafts->filter(fn (Shift $shift): bool => $shift->employee_id !== null && RequestChecks::isUnavailable($availability->get($shift->employee_id, collect()), $shift))->count(),
            'notify' => array_values($employees->filter(fn (Employee $employee): bool => $employee->user_id !== null || ($employee->email ?? '') !== '')->map($name)->all()),
            'unreachable' => array_values($employees->filter(fn (Employee $employee): bool => $employee->user_id === null && ($employee->email ?? '') === '')->map($name)->all()),
        ];
    }

    /** @return Builder<Shift> */
    private static function drafts(CarbonImmutable $from, CarbonImmutable $to): Builder
    {
        return ShiftQuery::startingOn($from, $to)->where('status', Shift::DRAFT)->orderBy('starts_at');
    }

    /** @return array{CarbonImmutable, CarbonImmutable} */
    private static function days(CarbonImmutable $from, CarbonImmutable $to): array
    {
        $from = $from->setTimezone(LocalTimestamp::ZONE)->startOfDay();
        $to = $to->setTimezone(LocalTimestamp::ZONE)->startOfDay();

        return $from->greaterThan($to) ? [$to, $from] : [$from, $to];
    }
}
