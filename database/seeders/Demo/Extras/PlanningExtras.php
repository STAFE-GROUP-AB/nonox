<?php

declare(strict_types=1);

namespace Database\Seeders\Demo\Extras;

use App\Models\TeamInvitation;
use App\Models\User;
use App\Modules\ModuleManager;
use App\Modules\Payroll\Actions\InviteEmployeeToPortal;
use App\Modules\Payroll\Models\Employee;
use App\Modules\Planning\Actions\ApplyTemplate;
use App\Modules\Planning\Actions\AttestDay;
use App\Modules\Planning\Actions\AttestPeriod;
use App\Modules\Planning\Actions\DecideRequest;
use App\Modules\Planning\Actions\IssueIcalToken;
use App\Modules\Planning\Actions\PublishPeriod;
use App\Modules\Planning\Actions\RequestShift;
use App\Modules\Planning\Actions\SaveActualTime;
use App\Modules\Planning\Actions\SaveArea;
use App\Modules\Planning\Actions\SaveAvailability;
use App\Modules\Planning\Actions\SaveShift;
use App\Modules\Planning\Actions\SaveSkill;
use App\Modules\Planning\Actions\SaveTemplate;
use App\Modules\Planning\Actions\SendToPayroll;
use App\Modules\Planning\Models\Shift;
use App\Modules\Planning\Models\ShiftRequest;
use App\Modules\Planning\PlanningModule;
use App\Modules\Planning\Support\LocalTimestamp;
use App\Modules\StaffLedger\Domain\LedgerQuery;
use App\Modules\StaffLedger\Models\Location;
use App\Modules\StaffLedger\Models\Person;
use App\Support\Tenancy\CurrentCompany;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Closure;
use Database\Seeders\Demo\DemoContext;
use Database\Seeders\Demo\DemoExtra;
use Laravel\Jetstream\Contracts\AddsTeamMembers;
use RuntimeException;

/**
 * NONOX Planering at the restaurant and the café, switched on once the
 * staff ledger has its three weeks: areas, skills, a "Normalvecka"
 * template, and the schedule.
 *
 * - The past weeks were planned before they happened: one published shift
 *   per staff-ledger shift, at the quarter hour (or the five minutes) the
 *   check-in fell on, so plan and actual mostly agree. A few days differ on
 *   purpose: a late start, overtime at closing, work without a shift and a
 *   planned shift nobody came to.
 * - This week and the next two are laid out from the template and
 *   published, with open shifts; the week after is drafts, and one shift
 *   this week was changed after publishing.
 * - At the restaurant Lina and Maja use Min sida: availability, Lina's
 *   claim of an open shift waiting, her swap with Maja accepted by Maja and
 *   waiting for the manager, Maja's swap with Lina waiting for Lina, Maja's
 *   claim approved, and Lina's calendar link.
 * - Attest: the clean days are attested, a few days with deviations are left
 *   open, and at the café a forgotten check-out is entered by the manager.
 *   At the restaurant only the first week is attested: the staff ledger's
 *   time reports (StaffLedgerExtras) already cover the last two weeks, so
 *   those days cannot be sent again. At the café this month's attested days
 *   are sent to payroll; October is not sent.
 *
 * Runs in afterAll, when the staff ledger and payroll exist.
 */
final class PlanningExtras extends DemoExtra
{
    private const string RESTAURANT = 'bokforing-for-restauranger';

    /** The days the staff ledger has, back from yesterday (StaffLedgerExtras). */
    private const int DAYS = 20;

    /**
     * Per company: areas (name, colour), skills, each employee's area and
     * skills, and the normal week as [employee, ISO weekdays, start, end,
     * break, skill].
     *
     * @var array<string, array{areas: array<string, string>, skills: list<string>, people: array<string, array{string, list<string>}>, week: list<array{string, list<int>, string, string, int, ?string}>}>
     */
    private const array PLANS = [
        self::RESTAURANT => [
            'areas' => ['Kök' => '#b45309', 'Servering' => '#0f766e', 'Bar' => '#6d28d9'],
            'skills' => ['Kassa', 'Bartender', 'Kallskänk', 'Grill'],
            'people' => [
                'Sara' => ['Kök', ['Grill', 'Kallskänk']],
                'Ali' => ['Kök', ['Grill']],
                'Nils' => ['Kök', ['Kallskänk']],
                'Lina' => ['Servering', ['Kassa']],
                'Johan' => ['Servering', ['Kassa', 'Bartender']],
                'Maja' => ['Servering', ['Kassa']],
                'Elin' => ['Bar', ['Bartender', 'Kassa']],
            ],
            // Closed on Mondays; lunch from 11, dinner to 23, the bar open to 01 on Friday and Saturday.
            'week' => [
                ['Sara', [2, 3, 4, 5, 6], '09:30', '17:30', 30, null],
                ['Ali', [3, 4, 5, 6, 7], '14:00', '22:30', 30, 'Grill'],
                ['Nils', [5, 6], '16:00', '22:00', 30, 'Kallskänk'],
                ['Nils', [7], '11:00', '16:00', 0, 'Kallskänk'],
                ['Lina', [2, 3, 4], '10:30', '18:30', 30, null],
                ['Lina', [5, 6], '16:30', '00:30', 30, null],
                ['Johan', [3, 4, 7], '15:30', '23:30', 30, null],
                ['Johan', [5, 6], '16:00', '01:00', 30, null],
                ['Maja', [5, 6], '17:00', '00:00', 30, null],
                ['Maja', [7], '11:00', '16:00', 0, null],
                ['Elin', [4], '17:00', '23:00', 30, 'Bartender'],
                ['Elin', [5, 6], '18:00', '01:30', 30, 'Bartender'],
            ],
        ],
        'bokforing-for-cafeer' => [
            'areas' => ['Disk' => '#0e7490', 'Bageri' => '#a16207'],
            'skills' => ['Kassa', 'Barista', 'Bakning'],
            'people' => [
                'Ida' => ['Disk', ['Barista', 'Kassa']],
                'Leo' => ['Bageri', ['Bakning']],
                'Wilma' => ['Disk', ['Kassa']],
                'Hugo' => ['Disk', ['Kassa', 'Barista']],
            ],
            // Open every day; the bakery starts before the doors open.
            'week' => [
                ['Ida', [1, 2, 3, 4, 5], '07:00', '15:30', 30, 'Barista'],
                ['Leo', [1, 2, 3, 4, 5], '05:30', '13:30', 30, 'Bakning'],
                ['Leo', [6], '06:00', '12:00', 30, 'Bakning'],
                ['Wilma', [3], '14:00', '18:00', 0, null],
                ['Wilma', [6, 7], '09:00', '16:00', 30, null],
                ['Hugo', [1, 2], '12:00', '18:00', 30, null],
                ['Hugo', [6, 7], '10:00', '17:00', 30, 'Barista'],
            ],
        ],
    ];

    /**
     * Open shifts, published: [week from this one, ISO weekday, start, end,
     * area, skill ('' for none), note].
     *
     * @var array<string, list<array{int, int, string, string, string, string, string}>>
     */
    private const array OPEN = [
        self::RESTAURANT => [
            [0, 7, '11:00', '16:00', 'Servering', '', 'Extra lunchpass, sällskap på 40 personer.'],
            [1, 4, '17:00', '23:00', 'Servering', '', 'Julbordspremiär, extra servering.'],
            [1, 6, '19:00', '01:30', 'Bar', 'Bartender', 'Privat fest i baren.'],
        ],
        'bokforing-for-cafeer' => [
            [0, 6, '09:00', '14:00', 'Disk', '', 'Julmarknad på torget, extra i disken.'],
            [1, 7, '06:00', '12:00', 'Bageri', 'Bakning', 'Lussebullar till skolans beställning.'],
        ],
    ];

    /**
     * Days back from yesterday with a planned difference: late start,
     * overtime at closing (attested), work without a shift and a planned
     * shift nobody came to. Clear of the staff ledger's mishaps (0, 3, 5, 8).
     *
     * @var array<string, array<string, int>>
     */
    private const array DEVIATIONS = [
        self::RESTAURANT => ['late_start' => 18, 'late_finish' => 16],
        'bokforing-for-cafeer' => ['late_start' => 17, 'late_finish' => 12, 'unplanned' => 10, 'no_show' => 7],
    ];

    /** Maja Lund, hourly waitress at the restaurant, also uses Min sida. */
    private const string SECOND_EMPLOYEE = 'Maja';

    public function __construct(private readonly PayrollExtras $payroll) {}

    public function industries(): array
    {
        return [self::RESTAURANT, 'bokforing-for-cafeer'];
    }

    public function afterAll(DemoContext $context): void
    {
        // Publishing and decisions notify the staff: to the log, never real mail.
        config(['mail.default' => 'log']);

        $plan = self::PLANS[$context->slug];
        $location = Location::query()->orderBy('id')->first();
        $monday = $context->today->startOfWeek(CarbonImmutable::MONDAY);

        [$areas, $skills, $homes] = $this->at($monday->subWeeks(4)->setTime(9, 0), function () use ($context, $plan, $location): array {
            app(ModuleManager::class)->enable(PlanningModule::KEY, $context->company, $context->user);

            return $this->setUp($context, $plan, $location);
        });
        $this->pastWeeks($context, $homes);
        $this->at($monday->subDays(4)->setTime(14, 0), fn () => $this->comingWeeks($context, $plan, $areas, $skills, $monday));
        $this->changeAfterPublishing($context, $monday);

        if ($context->slug === self::RESTAURANT) {
            $this->selfService($context, $monday);
        }

        $this->attest($context);
    }

    /**
     * Areas at the staff ledger's location, skills, and who has which.
     *
     * @param  array{areas: array<string, string>, skills: list<string>, people: array<string, array{string, list<string>}>}  $plan
     * @return array{array<string, int>, array<string, int>, array<int, int>} area ids and skill ids by name, area id by employee id
     */
    private function setUp(DemoContext $context, array $plan, ?Location $location): array
    {
        $areas = [];
        $skills = [];
        $homes = [];
        $sort = 0;

        foreach ($plan['areas'] as $name => $colour) {
            $areas[$name] = app(SaveArea::class)($context->user, $context->company, [
                'name' => $name,
                'colour' => $colour,
                'location_id' => $location?->id,
                'sort' => $sort += 10,
            ])->id;
        }

        foreach ($plan['skills'] as $name) {
            $skills[$name] = app(SaveSkill::class)($context->user, $context->company, $name)->id;
        }

        foreach ($plan['people'] as $firstName => [$area, $has]) {
            $employee = $this->employee($context, $firstName);
            $homes[$employee->id] = $areas[$area];
            app(SaveSkill::class)->assign($context->user, $context->company, $employee, array_map(fn (string $skill): int => $skills[$skill], $has));
        }

        return [$areas, $skills, $homes];
    }

    /**
     * One published shift per staff-ledger shift, created the week before
     * and published that Thursday. The ledger is only read.
     *
     * @param  array<int, int>  $homes  area id by employee id
     */
    private function pastWeeks(DemoContext $context, array $homes): void
    {
        $first = $context->end->subDays(self::DAYS)->startOfDay();
        $worked = $this->worked($first, $context->end);
        $deviations = self::DEVIATIONS[$context->slug];
        $targets = [];

        foreach (['late_start', 'late_finish', 'unplanned'] as $kind) {
            if (isset($deviations[$kind])) {
                $targets[$kind] = self::target($worked, $context->end->subDays($deviations[$kind]));
            }
        }

        $planned = [];

        foreach ($worked as $index => $shift) {
            if ($index === ($targets['unplanned'] ?? null)) {
                continue;
            }

            $start = self::snap($shift['start']);
            $end = self::snap($shift['end'] ?? $shift['start']->addHours(7));

            if ($index === ($targets['late_start'] ?? null)) {
                $start = $start->subMinutes(20);
            }

            if ($index === ($targets['late_finish'] ?? null)) {
                $end = $end->subMinutes(45);
            }

            $planned[] = self::shift($shift['employee_id'], $homes[$shift['employee_id']] ?? null, $start, $end);
        }

        if (isset($deviations['no_show'])) {
            $noShow = $this->noShow($worked, $homes, $context->end->subDays($deviations['no_show']));

            if ($noShow !== null) {
                $planned[] = $noShow;
            }
        }

        $weeks = [];

        foreach ($planned as $shift) {
            $weeks[$shift['starts_at']->startOfWeek(CarbonImmutable::MONDAY)->toDateString()][] = $shift;
        }

        foreach ($weeks as $week => $shifts) {
            $week = CarbonImmutable::parse($week, LocalTimestamp::ZONE);

            $this->at($week->subDays(5)->setTime(10, 0), function () use ($context, $shifts): void {
                foreach ($shifts as $shift) {
                    app(SaveShift::class)($context->user, $context->company, [
                        ...$shift,
                        'starts_at' => $shift['starts_at']->toIso8601String(),
                        'ends_at' => $shift['ends_at']->toIso8601String(),
                    ]);
                }
            });

            $this->at($week->subDays(4)->setTime(15, 0), fn () => app(PublishPeriod::class)($context->user, $context->company, $week, $week->addDays(6)));
        }
    }

    /**
     * "Normalvecka" laid out this week and the next two and published with
     * the open shifts; the fourth week laid out as drafts.
     *
     * @param  array{week: list<array{string, list<int>, string, string, int, ?string}>, people: array<string, array{string, list<string>}>}  $plan
     * @param  array<string, int>  $areas
     * @param  array<string, int>  $skills
     */
    private function comingWeeks(DemoContext $context, array $plan, array $areas, array $skills, CarbonImmutable $monday): void
    {
        $templates = app(SaveTemplate::class);
        $template = $templates($context->user, $context->company, [
            'name' => 'Normalvecka',
            'weeks' => 1,
            'notes' => $context->slug === self::RESTAURANT
                ? 'Stängt måndagar. Baren har öppet till 01 fredag och lördag.'
                : 'Bageriet börjar 05.30 på vardagar. Öppet alla dagar.',
        ]);

        foreach ($plan['week'] as [$firstName, $weekdays, $start, $end, $break, $skill]) {
            $employee = $this->employee($context, $firstName);

            foreach ($weekdays as $weekday) {
                $templates->saveShift($context->user, $context->company, $template, [
                    'week_index' => 0,
                    'weekday' => $weekday,
                    'start' => $start,
                    'end' => $end,
                    'break_minutes' => $break,
                    'employee_id' => $employee->id,
                    'area_id' => $areas[$plan['people'][$firstName][0]],
                    'skill_id' => $skill === null ? null : $skills[$skill],
                ]);
            }
        }

        $template = $template->fresh() ?? $template;
        app(ApplyTemplate::class)($context->user, $context->company, $template, $monday, 3);

        foreach (self::OPEN[$context->slug] as [$week, $weekday, $start, $end, $area, $skill, $note]) {
            app(SaveShift::class)($context->user, $context->company, [
                'employee_id' => null,
                'area_id' => $areas[$area],
                'skill_id' => $skills[$skill] ?? null,
                'date' => $monday->addWeeks($week)->addDays($weekday - 1)->toDateString(),
                'start' => $start,
                'end' => $end,
                'break_minutes' => self::breakFor($start, $end),
                'note' => $note,
            ]);
        }

        app(PublishPeriod::class)($context->user, $context->company, $monday, $monday->addWeeks(3)->subDay());
        app(ApplyTemplate::class)($context->user, $context->company, $template, $monday->addWeeks(3));
    }

    /** A published shift later this week moved by two hours and not yet published again. */
    private function changeAfterPublishing(DemoContext $context, CarbonImmutable $monday): void
    {
        $shift = Shift::query()
            ->where('status', Shift::PUBLISHED)
            ->whereNotNull('employee_id')
            ->where('starts_at', '>', $context->today->addDays(2)->toIso8601String())
            ->where('starts_at', '<', $monday->addWeek()->toIso8601String())
            ->orderBy('starts_at')
            ->first();

        if (! $shift instanceof Shift) {
            return;
        }

        app(SaveShift::class)($context->user, $context->company, [
            'employee_id' => $shift->employee_id,
            'area_id' => $shift->area_id,
            'skill_id' => $shift->skill_id,
            'starts_at' => $shift->starts_at->addHour()->toIso8601String(),
            'ends_at' => $shift->ends_at->addHour()->toIso8601String(),
            'break_minutes' => $shift->break_minutes,
            'note' => 'Flyttat en timme: leverans på morgonen.',
        ], $shift);
    }

    /**
     * Lina and Maja in Min sida: availability, requests and Lina's calendar
     * link. Maja's claim is approved by the owner; Lina's claim waits, her
     * swap with Maja is accepted by Maja and waits for the manager, and
     * Maja's swap with Lina waits for Lina.
     */
    private function selfService(DemoContext $context, CarbonImmutable $monday): void
    {
        $lina = PortalExtras::user() ?? throw new RuntimeException('Lina Berg has no login.');
        $maja = $this->invite($context, self::SECOND_EMPLOYEE);
        $company = $context->company->fresh() ?? $context->company;
        $availability = app(SaveAvailability::class);
        $requests = app(RequestShift::class);

        $availability($lina, $company, ['kind' => 'UNAVAILABLE', 'repeat' => 'weekly', 'weekday' => 1, 'whole_day' => true, 'note' => 'Pluggar på måndagar.']);
        $availability($lina, $company, ['kind' => 'AVAILABLE', 'repeat' => 'date', 'date' => $monday->addDays(6)->toDateString(), 'whole_day' => true, 'note' => 'Kan jobba extra den här söndagen.']);
        $availability($maja, $company, ['kind' => 'PREFERRED', 'repeat' => 'weekly', 'weekday' => 6, 'whole_day' => false, 'from' => '16:00', 'to' => '23:00', 'note' => 'Helst kvällspass på helgen.']);
        $availability($maja, $company, ['kind' => 'UNAVAILABLE', 'repeat' => 'date', 'date' => $monday->addWeeks(2)->addDays(4)->toDateString(), 'whole_day' => true, 'note' => 'Tenta.']);

        [$sunday, $thursday] = [$this->openShift($monday->addDays(6)), $this->openShift($monday->addWeek()->addDays(3))];

        $approved = $requests($maja, $company, ['kind' => ShiftRequest::CLAIM, 'shift_id' => $thursday->id, 'note' => 'Tar gärna julbordspremiären.']);
        app(DecideRequest::class)($context->user, $company, $approved, true, 'Toppen, tack Maja!');

        // This Sunday's shift may already have started (the demo is built on a Sunday evening too): a claim of it is refused.
        if ($sunday->starts_at->isFuture()) {
            $requests($lina, $company, ['kind' => ShiftRequest::CLAIM, 'shift_id' => $sunday->id, 'note' => 'Jag kan ta lunchen på söndag.']);
        }

        $linaId = (int) $this->employee($context, PortalExtras::EMPLOYEE)->id;
        $majaId = (int) $this->employee($context, self::SECOND_EMPLOYEE)->id;
        $linaShift = $this->ownShift($linaId, $monday->addWeek()->addDays(5));
        $majaShift = $this->ownShift($majaId, $monday->addWeek()->addDays(6));
        $swap = $requests($lina, $company, [
            'kind' => ShiftRequest::SWAP,
            'shift_id' => $linaShift->id,
            'counterpart_shift_id' => $majaShift->id,
            'note' => 'Min syster gifter sig på lördag. Kan vi byta, Maja?',
        ]);
        // Maja says yes, so the swap waits for the manager.
        $requests->answer($maja, $company, $swap, true, 'Absolut, jag tar lördagen.');

        // And one Lina has not answered yet.
        $requests($maja, $company, [
            'kind' => ShiftRequest::SWAP,
            'shift_id' => $this->ownShift($majaId, $monday->addWeeks(2)->addDays(6))->id,
            'counterpart_shift_id' => $this->ownShift($linaId, $monday->addWeeks(2)->addDays(3))->id,
            'note' => 'Kan du ta min söndagslunch om jag tar din torsdag, Lina?',
        ]);

        app(IssueIcalToken::class)($lina, $company);
    }

    /**
     * Attests the clean days, a day with overtime one by one, and leaves the
     * other deviations open. At the café the manager enters the time of a
     * forgotten check-out, and this month's attested days go to payroll.
     */
    private function attest(DemoContext $context): void
    {
        $first = $context->end->subDays(self::DAYS)->startOfDay();
        $restaurant = $context->slug === self::RESTAURANT;
        // At the restaurant the staff ledger's time reports cover the last two weeks (StaffLedgerExtras).
        $to = $restaurant ? $context->end->subDays(14) : $context->end;

        app(AttestPeriod::class)($context->user, $context->company, $first, $to, null, true);

        $worked = $this->worked($first, $context->end);
        $overtime = self::target($worked, $context->end->subDays(self::DEVIATIONS[$context->slug]['late_finish']));

        if ($overtime !== null) {
            app(AttestDay::class)($context->user, $context->company, $worked[$overtime]['employee_id'], $worked[$overtime]['start']->startOfDay());
        }

        if ($restaurant) {
            return;
        }

        $this->forgottenCheckOut($context, $worked);

        $sendFrom = $context->today->startOfMonth()->max($first);

        if ($sendFrom->lessThanOrEqualTo($context->end)) {
            $result = app(SendToPayroll::class)($context->user, $context->company, $sendFrom, $context->end);

            if ($result['skipped'] !== []) {
                $context->warn('Planering skickade inte allt till lönen: '.implode(' ', $result['skipped']));
            }
        }
    }

    /**
     * The check-out the staff ledger is missing for yesterday, as the
     * manager remembers it; the day is left for attesting.
     *
     * @param  list<array{employee_id: int, manager: bool, start: CarbonImmutable, end: ?CarbonImmutable}>  $worked
     */
    private function forgottenCheckOut(DemoContext $context, array $worked): void
    {
        foreach ($worked as $shift) {
            if ($shift['end'] !== null) {
                continue;
            }

            $start = $shift['start']->setTimezone(LocalTimestamp::ZONE);
            $end = self::snap($start->addHours(7));

            app(SaveActualTime::class)($context->user, $context->company, [
                'employee_id' => $shift['employee_id'],
                'date' => $start->toDateString(),
                'intervals' => [['start' => $start->format('H:i'), 'end' => $end->format('H:i'), 'break_minutes' => 30]],
                'note' => "Glömde checka ut. Stängde kassan {$end->format('H.i')} enligt kassarapporten.",
            ]);
        }
    }

    /**
     * The staff ledger's shifts of the employees still employed, oldest first.
     *
     * @return list<array{employee_id: int, manager: bool, start: CarbonImmutable, end: ?CarbonImmutable}>
     */
    private function worked(CarbonImmutable $from, CarbonImmutable $to): array
    {
        $people = Person::query()->whereNotNull('employee_id')->get(['id', 'employee_id', 'is_manager'])->keyBy('id');
        $employed = Employee::query()->where('status', '<>', 'TERMINATED')->pluck('id')->flip();
        $worked = [];

        foreach (LedgerQuery::shifts(null, $from->startOfDay(), $to->endOfDay()) as $shift) {
            $person = $shift->personId === null ? null : $people->get($shift->personId);

            if (! $person instanceof Person || $shift->start === null || ! $employed->has($person->employee_id)) {
                continue;
            }

            $worked[] = [
                'employee_id' => (int) $person->employee_id,
                'manager' => (bool) $person->is_manager,
                'start' => $shift->start->setTimezone(LocalTimestamp::ZONE),
                'end' => $shift->end?->setTimezone(LocalTimestamp::ZONE),
            ];
        }

        usort($worked, fn (array $a, array $b): int => $a['start'] <=> $b['start']);

        return $worked;
    }

    /**
     * The first complete shift of someone other than the manager on the day,
     * or on the next day that has one.
     *
     * @param  list<array{employee_id: int, manager: bool, start: CarbonImmutable, end: ?CarbonImmutable}>  $worked
     */
    private static function target(array $worked, CarbonImmutable $day): ?int
    {
        for ($try = 0; $try < 3; $try++) {
            $date = $day->addDays($try)->toDateString();

            foreach ($worked as $index => $shift) {
                if (! $shift['manager'] && $shift['end'] !== null && $shift['start']->toDateString() === $date) {
                    return $index;
                }
            }
        }

        return null;
    }

    /**
     * A planned shift for someone who did not work that day, or the next day
     * someone was off.
     *
     * @param  list<array{employee_id: int, manager: bool, start: CarbonImmutable, end: ?CarbonImmutable}>  $worked
     * @param  array<int, int>  $homes
     * @return ?array{employee_id: int, area_id: ?int, starts_at: CarbonImmutable, ends_at: CarbonImmutable, break_minutes: int}
     */
    private function noShow(array $worked, array $homes, CarbonImmutable $day): ?array
    {
        for ($try = 0; $try < 3; $try++) {
            $date = $day->addDays($try)->toDateString();
            $working = array_column(array_filter($worked, fn (array $shift): bool => $shift['start']->toDateString() === $date), 'employee_id');

            foreach (array_keys($homes) as $employeeId) {
                if (! in_array($employeeId, $working, true)) {
                    $start = CarbonImmutable::parse("{$date} 10:00", LocalTimestamp::ZONE);

                    return self::shift($employeeId, $homes[$employeeId], $start, $start->addHours(6));
                }
            }
        }

        return null;
    }

    /** @return array{employee_id: int, area_id: ?int, starts_at: CarbonImmutable, ends_at: CarbonImmutable, break_minutes: int} */
    private static function shift(int $employeeId, ?int $areaId, CarbonImmutable $start, CarbonImmutable $end): array
    {
        $minutes = intdiv($end->getTimestamp() - $start->getTimestamp(), 60);

        return ['employee_id' => $employeeId, 'area_id' => $areaId, 'starts_at' => $start, 'ends_at' => $end, 'break_minutes' => $minutes >= 360 ? 30 : 0];
    }

    /**
     * A planned time near a check-in: the quarter hour when it is within the
     * attest tolerance, otherwise the nearest five minutes.
     */
    private static function snap(CarbonImmutable $moment): CarbonImmutable
    {
        $local = $moment->setTimezone(LocalTimestamp::ZONE)->startOfMinute();
        $minutes = $local->hour * 60 + $local->minute;

        foreach ([15, 5] as $step) {
            $snapped = (int) round($minutes / $step) * $step;

            if (abs($snapped - $minutes) < 5) {
                // Wall-clock time, so the day summer time ends is planned like any other.
                return $local->setTime(intdiv($snapped, 60), $snapped % 60);
            }
        }

        return $local;
    }

    private static function breakFor(string $start, string $end): int
    {
        $minutes = (strtotime("2000-01-01 {$end}") - strtotime("2000-01-01 {$start}")) / 60;

        return ($minutes <= 0 ? $minutes + 1440 : $minutes) >= 360 ? 30 : 0;
    }

    private function openShift(CarbonImmutable $day): Shift
    {
        return Shift::query()->whereNull('employee_id')->where('status', Shift::PUBLISHED)
            ->where('starts_at', '>=', $day->startOfDay()->toIso8601String())
            ->where('starts_at', '<', $day->addDay()->startOfDay()->toIso8601String())
            ->firstOrFail();
    }

    private function ownShift(int $employeeId, CarbonImmutable $day): Shift
    {
        return Shift::query()->where('employee_id', $employeeId)->where('status', Shift::PUBLISHED)
            ->where('starts_at', '>=', $day->startOfDay()->toIso8601String())
            ->where('starts_at', '<', $day->addDay()->startOfDay()->toIso8601String())
            ->firstOrFail();
    }

    private function employee(DemoContext $context, string $firstName): Employee
    {
        return $this->payroll->employeeOrFail($context, $firstName);
    }

    /**
     * The same flow as Lina's (PortalExtras): invited from the employee
     * card, signed in with the address and linked to the employee record.
     */
    private function invite(DemoContext $context, string $firstName): User
    {
        $employee = $this->employee($context, $firstName);
        $email = PayrollExtras::email($employee);

        if ($employee->email !== $email) {
            $employee = $this->payroll->saveEmployee($context, $employee, ['email' => $email]);
        }

        app(InviteEmployeeToPortal::class)($context->user, $context->company, $employee);

        $user = User::query()->firstOrCreate(['email' => $email], ['name' => "{$employee->first_name} {$employee->last_name}", 'email_verified_at' => now()]);
        $invitation = TeamInvitation::query()->where('team_id', $context->company->id)->where('email', $email)->firstOrFail();

        app(AddsTeamMembers::class)->add($context->user, $context->company, $invitation->email, $invitation->role);
        $invitation->delete();
        $context->company->unsetRelation('users');
        CurrentCompany::run($context->company, fn (): bool => $user->switchCompany($context->company));

        return $user;
    }

    /**
     * Runs $work with the clock at $moment, so what it writes carries the
     * time it happened.
     *
     * @template T
     *
     * @param  Closure(): T  $work
     * @return T
     */
    private function at(CarbonImmutable $moment, Closure $work): mixed
    {
        $previous = Carbon::getTestNow();
        Carbon::setTestNow($moment);

        try {
            return $work();
        } finally {
            Carbon::setTestNow($previous);
        }
    }
}
