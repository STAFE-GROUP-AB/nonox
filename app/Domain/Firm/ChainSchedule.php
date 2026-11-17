<?php

declare(strict_types=1);

namespace App\Domain\Firm;

use App\Models\CompanyModule;
use App\Models\User;
use App\Modules\Payroll\Models\Employee;
use App\Modules\Planning\Models\Area;
use App\Modules\Planning\Models\Shift;
use App\Modules\Planning\PlanningModule;
use App\Modules\Planning\PlanningSettings;
use App\Modules\Planning\Support\ShiftQuery;
use App\Support\Tenancy\CompanyScope;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * Planering across companies (ADR-037 amendment, gap 8): one week of shifts
 * for the companies where the user holds planning:view, read only. There is
 * no chain entity and no shared data; this is the firm workspace's pattern
 * (ADR-028): the user's own memberships decide what is read, every read is
 * one grouped query across the ids, and each company is checked against the
 * user's permission there.
 *
 * The same person in two companies is recognised only through the same
 * login (employees.user_id) and only among the companies read here, which
 * the user may all see. Overlapping shifts and a daily rest that is too
 * short then span the companies. A company the user may not see is never
 * read, so it can neither show a shift nor reveal that a conflict exists.
 * The chain view never writes: a change is made in one company after a
 * logged company switch.
 *
 * Fixed number of queries whatever the number of companies and shifts. The
 * budget column (P6) is filled into each company block's "extra" only where
 * the user holds planning:budget in that company; it is empty until then.
 *
 * @phpstan-type Chip array{
 *     id: string, company_id: int, date: string, start: string, end: string, person: ?string,
 *     area: ?string, draft: bool, open: bool, warnings: list<string>
 * }
 * @phpstan-type CompanyBlock array{
 *     id: int, name: string, chips: array<string, list<Chip>>, minutes: int, cost: int,
 *     incomplete: bool, shifts: int, open: int, drafts: int, warnings: int, extra: array<string, mixed>
 * }
 * @phpstan-type Conflict array{
 *     key: string, kind: string, message: string, date: string, company_ids: list<int>, shift_ids: list<string>
 * }
 */
final class ChainSchedule
{
    public const string PERMISSION = 'planning:view';

    /**
     * @param  list<int>  $requested  company ids the user chose; empty means every company the user may see
     * @return array{
     *     eligible: array<int, string>,
     *     selected: list<int>,
     *     days: list<CarbonImmutable>,
     *     companies: list<CompanyBlock>,
     *     conflicts: list<Conflict>,
     *     totals: array{minutes: int, cost: int, shifts: int, open: int, incomplete: bool}
     * }
     */
    public function for(User $user, CarbonImmutable $monday, array $requested = []): array
    {
        $memberships = Memberships::of($user, active: true);
        $eligibleIds = $memberships->where(self::PERMISSION);
        $names = $memberships->companies->mapWithKeys(fn ($company): array => [(int) $company->id => (string) $company->name])->all();
        $eligible = [];

        foreach ($eligibleIds as $id) {
            $eligible[$id] = $names[$id];
        }

        // A saved or requested selection is always cut down to what the user may see now.
        $selected = $requested === [] ? $eligibleIds : array_values(array_filter($eligibleIds, fn (int $id): bool => in_array($id, $requested, true)));
        $selected = $selected === [] ? $eligibleIds : $selected;
        $days = [];

        for ($day = $monday; $day->lessThanOrEqualTo($monday->addDays(6)); $day = $day->addDay()) {
            $days[] = $day;
        }

        if ($selected === []) {
            return ['eligible' => $eligible, 'selected' => [], 'days' => $days, 'companies' => [], 'conflicts' => [], 'totals' => $this->emptyTotals()];
        }

        // Read across the user's own companies at once; every id comes from the user's memberships and holds planning:view.
        $read = CompanyScope::bypass(fn (): array => $this->read($selected, $monday));

        return $this->build($selected, $eligible, $days, $monday, $read);
    }

    /**
     * @param  list<int>  $ids
     * @return array{shifts: Collection<int, Shift>, employees: Collection<int, Employee>, areas: Collection<int, Area>, settings: Collection<int, array<string, mixed>>}
     */
    private function read(array $ids, CarbonImmutable $monday): array
    {
        // The day before the week is read too, for the rest between a late shift there and an early one here.
        $shifts = ShiftQuery::startingOn($monday->subDay(), $monday->addDays(6))
            ->whereIn('company_id', $ids)
            ->where('status', '<>', Shift::CANCELLED)
            ->orderBy('starts_at')
            ->get();

        $employees = Employee::query()
            ->whereIn('company_id', $ids)
            ->whereIn('id', $shifts->pluck('employee_id')->filter()->unique()->all())
            ->get(['id', 'company_id', 'user_id', 'first_name', 'last_name'])
            ->keyBy('id');

        $areas = Area::query()->whereIn('company_id', $ids)->get(['id', 'company_id', 'name'])->keyBy('id');

        $settings = CompanyModule::query()
            ->where('module_key', PlanningModule::KEY)
            ->whereIn('company_id', $ids)
            ->get(['company_id', 'settings'])
            ->mapWithKeys(fn (CompanyModule $row): array => [(int) $row->company_id => PlanningSettings::resolve((array) $row->settings)]);

        return ['shifts' => $shifts, 'employees' => $employees, 'areas' => $areas, 'settings' => $settings];
    }

    /**
     * @param  list<int>  $selected
     * @param  array<int, string>  $eligible
     * @param  list<CarbonImmutable>  $days
     * @param  array{shifts: Collection<int, Shift>, employees: Collection<int, Employee>, areas: Collection<int, Area>, settings: Collection<int, array<string, mixed>>}  $read
     * @return array{
     *     eligible: array<int, string>, selected: list<int>, days: list<CarbonImmutable>,
     *     companies: list<CompanyBlock>, conflicts: list<Conflict>,
     *     totals: array{minutes: int, cost: int, shifts: int, open: int, incomplete: bool}
     * }
     */
    private function build(array $selected, array $eligible, array $days, CarbonImmutable $monday, array $read): array
    {
        $conflicts = $this->conflicts($read['shifts'], $read['employees'], $read['settings'], $eligible);
        $warned = [];

        foreach ($conflicts as $conflict) {
            foreach ($conflict['shift_ids'] as $shiftId) {
                $warned[$shiftId][] = $conflict['message'];
            }
        }

        $weekEnd = $monday->addDays(7);
        $inWeek = $read['shifts']->filter(fn (Shift $shift): bool => $shift->starts_at->greaterThanOrEqualTo($monday) && $shift->starts_at->lessThan($weekEnd));
        $blocks = [];

        foreach ($selected as $id) {
            $shifts = $inWeek->where('company_id', $id);
            $chips = [];

            foreach ($shifts as $shift) {
                $person = $shift->employee_id === null ? null : $read['employees']->get($shift->employee_id);
                $chips[$shift->starts_at->toDateString()][] = [
                    'id' => (string) $shift->id,
                    'company_id' => $id,
                    'date' => $shift->starts_at->toDateString(),
                    'start' => $shift->starts_at->format('H:i'),
                    'end' => $shift->ends_at->format('H:i'),
                    'person' => $person instanceof Employee ? $person->first_name.' '.mb_substr((string) $person->last_name, 0, 1).'.' : null,
                    'area' => $shift->area_id === null ? null : $read['areas']->get($shift->area_id)?->name,
                    'draft' => $shift->status === Shift::DRAFT,
                    'open' => $shift->employee_id === null,
                    'warnings' => $warned[(string) $shift->id] ?? [],
                ];
            }

            $blocks[] = [
                'id' => $id,
                'name' => $eligible[$id],
                'chips' => $chips,
                'minutes' => (int) $shifts->sum(fn (Shift $shift): int => $shift->workedMinutes()),
                'cost' => (int) $shifts->sum(fn (Shift $shift): int => $shift->cost_estimate_ore ?? 0),
                'incomplete' => $shifts->contains(fn (Shift $shift): bool => $shift->employee_id !== null && ($shift->cost_estimate_ore === null || ((array) ($shift->cost_details['missing'] ?? [])) !== [])),
                'shifts' => $shifts->count(),
                'open' => $shifts->whereNull('employee_id')->count(),
                'drafts' => $shifts->where('status', Shift::DRAFT)->count(),
                'warnings' => $shifts->filter(fn (Shift $shift): bool => isset($warned[(string) $shift->id]))->count(),
                'extra' => [],
            ];
        }

        return [
            'eligible' => $eligible,
            'selected' => $selected,
            'days' => $days,
            'companies' => $blocks,
            'conflicts' => array_values(array_filter($conflicts, fn (array $conflict): bool => $conflict['date'] >= $monday->toDateString() && $conflict['date'] < $weekEnd->toDateString())),
            'totals' => [
                'minutes' => array_sum(array_column($blocks, 'minutes')),
                'cost' => array_sum(array_column($blocks, 'cost')),
                'shifts' => array_sum(array_column($blocks, 'shifts')),
                'open' => array_sum(array_column($blocks, 'open')),
                'incomplete' => in_array(true, array_column($blocks, 'incomplete'), true),
            ],
        ];
    }

    /**
     * Overlap and too short a daily rest between shifts in different
     * companies for the same login. Within one company the schedule itself
     * warns. The rest is the stricter of the two companies' daily-rest rule,
     * where that rule is switched on.
     *
     * // TODO(legal-verify): how arbetstidslagen's limits apply to one person with several employers in the same group (ADR-037 gap 8); the check only warns.
     *
     * @param  Collection<int, Shift>  $shifts  sorted by start
     * @param  Collection<int, Employee>  $employees
     * @param  Collection<int, array<string, mixed>>  $settings  resolved planning settings by company
     * @param  array<int, string>  $names  company names the user may see
     * @return list<Conflict>
     */
    private function conflicts(Collection $shifts, Collection $employees, Collection $settings, array $names): array
    {
        $byLogin = $shifts->filter(fn (Shift $shift): bool => $shift->employee_id !== null && $employees->get($shift->employee_id)?->user_id !== null)
            ->groupBy(fn (Shift $shift): int => (int) $employees->get($shift->employee_id)?->user_id);
        $found = [];

        foreach ($byLogin as $loginShifts) {
            if ($loginShifts->pluck('company_id')->unique()->count() < 2) {
                continue;
            }

            $sorted = $loginShifts->sortBy(fn (Shift $shift): int => $shift->starts_at->getTimestamp())->values()->all();

            foreach ($sorted as $index => $later) {
                for ($i = 0; $i < $index; $i++) {
                    $earlier = $sorted[$i];

                    if ($earlier->company_id === $later->company_id) {
                        continue;
                    }

                    $gap = intdiv($later->starts_at->getTimestamp() - $earlier->ends_at->getTimestamp(), 60);
                    $rest = max($this->dailyRest($settings->get($earlier->company_id)), $this->dailyRest($settings->get($later->company_id)));

                    if ($gap < 0) {
                        $kind = 'overlap';
                        $message = __('Passet överlappar ett pass hos samma person i :company.', ['company' => $names[$earlier->company_id] ?? '']);
                    } elseif ($rest > 0 && $gap < $rest) {
                        $kind = 'rest';
                        $message = __('Bara :rest vila efter ett pass hos samma person i :company. Dygnsvilan ska vara minst :minimum.', [
                            'rest' => self::hours($gap),
                            'company' => $names[$earlier->company_id] ?? '',
                            'minimum' => self::hours($rest),
                        ]);
                    } else {
                        continue;
                    }

                    $found[] = [
                        'key' => $earlier->id.'|'.$later->id,
                        'kind' => $kind,
                        'message' => $message,
                        'date' => $later->starts_at->toDateString(),
                        'company_ids' => [(int) $earlier->company_id, (int) $later->company_id],
                        'shift_ids' => [(string) $earlier->id, (string) $later->id],
                    ];
                }
            }
        }

        return $found;
    }

    /** @param  ?array<string, mixed>  $settings */
    private function dailyRest(?array $settings): int
    {
        $rule = $settings['working_time_rules']['daily_rest'] ?? null;

        return is_array($rule) && ($rule['enabled'] ?? false) === true && isset($rule['value']) ? (int) $rule['value'] : 0;
    }

    private static function hours(int $minutes): string
    {
        $hours = $minutes / 60;

        return (floor($hours) === $hours ? (string) (int) $hours : str_replace('.', ',', (string) round($hours, 2))).' h';
    }

    /** @return array{minutes: int, cost: int, shifts: int, open: int, incomplete: bool} */
    private function emptyTotals(): array
    {
        return ['minutes' => 0, 'cost' => 0, 'shifts' => 0, 'open' => 0, 'incomplete' => false];
    }
}
