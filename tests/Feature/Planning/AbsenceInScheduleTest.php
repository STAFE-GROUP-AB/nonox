<?php

declare(strict_types=1);

use App\Models\AuditLog;
use App\Modules\Payroll\Models\Absence;
use App\Modules\Planning\Actions\DecideRequest;
use App\Modules\Planning\Actions\PublishPeriod;
use App\Modules\Planning\Actions\RequestShift;
use App\Modules\Planning\Actions\SaveShift;
use App\Modules\Planning\Livewire\MySchedule;
use App\Modules\Planning\Livewire\SchedulePage;
use App\Modules\Planning\Models\Shift;
use App\Modules\Planning\Support\AbsenceDays;
use App\Modules\Planning\Support\PlanningTodos;
use App\Modules\Planning\Support\ScheduleData;
use App\Support\Tenancy\CurrentCompany;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;
use Tests\Support\Bookkeeping;
use Tests\Support\Planning;

/*
 * ADR-037 amendment, gap 6: approved absence in the schedule. The schedule
 * shows "Frånvarande" and "Delvis frånvarande" (and "Frånvaro begärd" to
 * planners only), warns on save, publish and request decisions, and puts
 * shifts of absent staff in the to-do list. Planering never reads the type,
 * the note or the doctor's certificate of an absence.
 */

beforeEach(function () {
    Notification::fake();
    $this->travelTo(CarbonImmutable::parse('2026-11-04 10:00:00', 'Europe/Stockholm'));
    [$this->owner, $this->company] = Bookkeeping::company();
    Planning::setUp($this->company, $this->owner);
    $this->worker = Bookkeeping::member($this->company, 'employee');
    $this->colleague = Bookkeeping::member($this->company, 'employee');
    CurrentCompany::set($this->company);
    $this->actingAs($this->owner);
    $this->anna = Planning::employee($this->owner, $this->company, 'Anna', '900615123', ['user_id' => $this->worker->id]);
    $this->bertil = Planning::employee($this->owner, $this->company, 'Bertil', '850301234', ['user_id' => $this->colleague->id]);
    $this->monday = CarbonImmutable::parse('2026-11-09', 'Europe/Stockholm');
});

function absent(int $employeeId, string $from, string $to, string $status = 'APPROVED', int $extent = 10000, string $type = 'SICK', ?string $note = null): Absence
{
    return CurrentCompany::run(test()->company, fn (): Absence => Absence::create([
        'employee_id' => $employeeId,
        'type' => $type,
        'date_from' => $from,
        'date_to' => $to,
        'extent_bp' => $extent,
        'hours' => '8',
        'status' => $status,
        'note' => $note,
        'doctor_certificate_received' => true,
    ]));
}

function absenceShift(int $employeeId, string $date, string $start = '08:00', string $end = '16:00'): Shift
{
    return Planning::shift(test()->owner, test()->company, ['employee_id' => $employeeId, 'date' => $date, 'start' => $start, 'end' => $end]);
}

function publishAbsenceWeek(): void
{
    $test = test();
    CurrentCompany::run($test->company, fn () => app(PublishPeriod::class)($test->owner, $test->company, $test->monday, $test->monday->addDays(6)));
}

it('marks approved absence across the day, partial absence separately, and names no cause', function () {
    absent($this->anna->id, '2026-11-10', '2026-11-11', note: 'Hemlig anteckning');
    absent($this->bertil->id, '2026-11-12', '2026-11-12', extent: 5000, type: 'VAB');

    Livewire::withQueryParams(['vecka' => '2026-11-09'])->test(SchedulePage::class)
        ->assertSeeHtml('data-absence="FULL"')
        ->assertSeeHtml('data-absence="PARTIAL"')
        ->assertSee('Frånvarande')
        ->assertSee('Delvis frånvarande')
        ->assertDontSee('Hemlig anteckning')
        ->assertDontSee('Sjuk')
        ->assertDontSee('VAB');
});

it('shows a requested absence to planners only', function () {
    absent($this->anna->id, '2026-11-10', '2026-11-10', status: 'REQUESTED');

    Livewire::withQueryParams(['vecka' => '2026-11-09'])->test(SchedulePage::class)
        ->assertSeeHtml('data-absence="REQUESTED"')
        ->assertSee('Frånvaro begärd');

    expect(AbsenceDays::between($this->monday, $this->monday->addDays(6)))->toBe([])
        ->and(AbsenceDays::between($this->monday, $this->monday->addDays(6), true))->toBe([$this->anna->id => ['2026-11-10' => 'REQUESTED']]);

    $week = app(ScheduleData::class)->load($this->company, $this->monday, $this->monday->addDays(6));
    expect($week['absence'])->toBe([]);
});

it('never rejects, only warns: a shift on an absent day saves with a warning', function () {
    absent($this->anna->id, '2026-11-10', '2026-11-10');

    $page = Livewire::withQueryParams(['vecka' => '2026-11-09'])->test(SchedulePage::class)
        ->call('create', $this->anna->id, '2026-11-10')
        ->call('save')
        ->assertHasNoErrors()
        ->assertDispatched('toast', message: 'Passet är tillagt som utkast. Personen är frånvarande den här dagen.');

    $shift = Shift::query()->sole();
    $week = app(ScheduleData::class)->load($this->company, $this->monday, $this->monday->addDays(6));

    expect(array_map(fn ($warning): string => $warning->message, $week['warnings'][$shift->id]))->toContain('Personen är frånvarande den här dagen.');

    // The warning is on the shift's chip in the schedule (its hover text and mark), with the editor closed.
    $page->call('$refresh')->assertSet('showEditor', false)->assertSee('Personen är frånvarande den här dagen.')->assertSeeHtml('data-shift="'.$shift->id.'"');
});

it('warns on a shift that runs past midnight into an absent day, and on a partial absence', function () {
    absent($this->anna->id, '2026-11-11', '2026-11-11', extent: 2500);
    $night = absenceShift($this->anna->id, '2026-11-10', '20:00', '02:00');

    expect(app(SaveShift::class)->absenceWarning($night))->toBe('Personen är frånvarande den här dagen.');
    expect(app(SaveShift::class)->absenceWarning(absenceShift($this->bertil->id, '2026-11-10')))->toBeNull();
});

it('counts shifts on absent days in the publish summary and the toast, and audits the count only', function () {
    absent($this->anna->id, '2026-11-10', '2026-11-10');
    absenceShift($this->anna->id, '2026-11-10');
    absenceShift($this->bertil->id, '2026-11-10');

    expect(PublishPeriod::summary($this->monday, $this->monday->addDays(6))['absent'])->toBe(1);

    Livewire::withQueryParams(['vecka' => '2026-11-09'])->test(SchedulePage::class)
        ->call('openPublish')
        ->assertSee('1 pass ligger på en dag då personen är frånvarande.')
        ->call('publish')
        ->assertDispatched('toast', message: '2 pass är publicerade. 2 personer har fått besked. 1 pass ligger på en dag då personen är frånvarande.');

    $payload = AuditLog::query()->where('action', 'planning.period_published')->sole()->payload;
    expect($payload['absent_shifts'])->toBe(1)
        ->and(json_encode($payload))->not->toContain('SICK');
});

it('warns when a request is decided for a person who is absent', function () {
    $open = Planning::shift($this->owner, $this->company, ['date' => '2026-11-10', 'start' => '08:00', 'end' => '16:00']);
    publishAbsenceWeek();
    absent($this->anna->id, '2026-11-10', '2026-11-10');
    $request = app(RequestShift::class)($this->worker, $this->company, ['kind' => 'CLAIM', 'shift_id' => $open->id]);

    $warnings = app(DecideRequest::class)($this->owner, $this->company, $request, true);

    expect(array_column($warnings, 'message'))->toContain('Personen är frånvarande den här dagen.')
        ->and(array_column($warnings, 'code'))->toContain('absent')
        ->and($open->fresh()->employee_id)->toBe($this->anna->id);

    // The audit log holds rule codes only: no text, no name, no mention of absence.
    $payload = AuditLog::query()->where('action', 'planning.request_approved')->sole()->payload;
    $json = json_encode($payload, JSON_UNESCAPED_UNICODE);
    expect($payload['warnings'])->toContain('absent')
        ->and($json)->not->toContain('frånvarande')
        ->and($json)->not->toContain('Anna')
        ->and($json)->not->toContain('Kock');
});

it('puts published shifts of absent staff in the to-do list, for planners only', function () {
    $todos = fn (): array => app(PlanningTodos::class)->todos($this->owner, $this->company, CarbonImmutable::today('Europe/Stockholm'));
    $find = fn (): ?string => collect($todos())->firstWhere('key', 'planning-absent')?->title;

    absenceShift($this->anna->id, '2026-11-10');
    absenceShift($this->bertil->id, '2026-11-11');
    publishAbsenceWeek();

    expect($find())->toBeNull();

    // Approved absence appears after the shifts were published.
    absent($this->anna->id, '2026-11-10', '2026-11-12');
    expect($find())->toBe('1 pass hos frånvarande personal');

    // A requested absence is not a to-do; a draft is not either.
    absent($this->bertil->id, '2026-11-11', '2026-11-11', status: 'REQUESTED');
    absenceShift($this->anna->id, '2026-11-12');
    expect($find())->toBe('1 pass hos frånvarande personal');

    // Past shifts are not offered for reassignment.
    $this->travelTo(CarbonImmutable::parse('2026-11-11 07:00:00', 'Europe/Stockholm'));
    expect($find())->toBeNull();

    expect(app(PlanningTodos::class)->todos($this->worker, $this->company, CarbonImmutable::today('Europe/Stockholm')))->toBe([]);
});

it('shows colleagues nothing: Min sida has no absence marker and open shifts carry none', function () {
    absenceShift($this->anna->id, '2026-11-10');
    absenceShift($this->bertil->id, '2026-11-10', '12:00', '20:00');
    publishAbsenceWeek();
    absent($this->anna->id, '2026-11-10', '2026-11-10');

    $this->actingAs($this->colleague);
    Livewire::test(MySchedule::class)->call('nextWeek')
        ->assertDontSee('Frånvarande')
        ->assertDontSee('Delvis frånvarande')
        ->assertDontSee('Frånvaro begärd')
        ->assertDontSee('frånvarande');
});

it('reads only employee, dates, extent and status of an absence, never the type, the note or the certificate', function () {
    absent($this->anna->id, '2026-11-10', '2026-11-10', note: 'Hemlig anteckning');
    absenceShift($this->anna->id, '2026-11-10');
    publishAbsenceWeek();

    DB::flushQueryLog();
    DB::enableQueryLog();
    app(ScheduleData::class)->load($this->company, $this->monday, $this->monday->addDays(6), manager: true);
    PublishPeriod::summary($this->monday, $this->monday->addDays(6));
    app(PlanningTodos::class)->todos($this->owner, $this->company, CarbonImmutable::today('Europe/Stockholm'));
    $queries = collect(DB::getQueryLog())->pluck('query')->filter(fn (string $sql): bool => str_contains($sql, 'from "absences"') || str_contains($sql, 'from `absences`') || str_contains($sql, ' "absences"'));
    DB::disableQueryLog();

    expect($queries)->not->toBeEmpty();

    foreach ($queries as $sql) {
        expect($sql)->not->toContain('"type"')
            ->not->toContain('"note"')
            ->not->toContain('doctor_certificate_received')
            ->not->toContain('select *')
            ->not->toContain('"absences".*');
    }
});

it('loads the absence marks in one query however many absences and shifts there are', function () {
    $count = function (): int {
        DB::flushQueryLog();
        DB::enableQueryLog();
        Livewire::withQueryParams(['vecka' => '2026-11-09'])->test(SchedulePage::class)->call('$refresh');
        $queries = DB::getQueryLog();
        DB::disableQueryLog();

        return count($queries);
    };

    absent($this->anna->id, '2026-11-10', '2026-11-10');
    absenceShift($this->anna->id, '2026-11-10');
    $count();
    $few = $count();

    foreach (['880202345', '770303456', '660404567', '550505678'] as $index => $nine) {
        $person = Planning::employee($this->owner, $this->company, "Person{$index}", $nine);
        absent($person->id, '2026-11-09', '2026-11-13', status: $index % 2 === 0 ? 'APPROVED' : 'REQUESTED', extent: 5000);

        foreach (range(9, 13) as $day) {
            absenceShift($person->id, sprintf('2026-11-%02d', $day), '10:00', '14:00');
        }
    }

    expect($count())->toBe($few);

    $todo = function (): int {
        DB::flushQueryLog();
        DB::enableQueryLog();
        app(PlanningTodos::class)->todos(test()->owner, test()->company, CarbonImmutable::today('Europe/Stockholm'));
        $queries = count(DB::getQueryLog());
        DB::disableQueryLog();

        return $queries;
    };
    $many = $todo();
    absent($this->bertil->id, '2026-11-16', '2026-11-20');
    foreach (range(16, 20) as $day) {
        absenceShift($this->bertil->id, sprintf('2026-11-%02d', $day));
    }
    expect($todo())->toBe($many);
});

it('derives a part day from the hours against the employment\'s day, or the extent', function () {
    // Anna: 8 h a day by default. 4 h missed is half a day, 8 h the whole day; Bertil has an extent of 25 %.
    absent($this->anna->id, '2026-11-10', '2026-11-10')->forceFill(['hours' => '4'])->save();
    absent($this->anna->id, '2026-11-11', '2026-11-11');
    absent($this->bertil->id, '2026-11-12', '2026-11-12', extent: 2500);

    $marks = AbsenceDays::between($this->monday, $this->monday->addDays(6));

    expect($marks[$this->anna->id])->toBe(['2026-11-10' => AbsenceDays::PARTIAL, '2026-11-11' => AbsenceDays::FULL])
        ->and($marks[$this->bertil->id])->toBe(['2026-11-12' => AbsenceDays::PARTIAL]);
});

it('warns about a Sunday night shift that runs into an absent Monday', function () {
    absent($this->anna->id, '2026-11-16', '2026-11-16');
    $night = absenceShift($this->anna->id, '2026-11-15', '22:00', '06:00');

    $week = app(ScheduleData::class)->load($this->company, $this->monday->addWeek()->subWeek(), $this->monday->addDays(6));

    expect(array_map(fn ($warning): string => $warning->rule, $week['warnings'][$night->id] ?? []))->toContain('absent');
});

it('looks only eight weeks ahead and only at people with absence for the to-do list', function () {
    absenceShift($this->anna->id, '2026-11-10');
    absenceShift($this->bertil->id, '2026-11-10');
    absenceShift($this->anna->id, '2027-03-02');
    publishAbsenceWeek();
    $march = CarbonImmutable::parse('2027-03-01', 'Europe/Stockholm');
    CurrentCompany::run($this->company, fn () => app(PublishPeriod::class)($this->owner, $this->company, $march, $march->addDays(6)));
    absent($this->anna->id, '2026-11-10', '2027-03-31');

    expect(Shift::query()->where('status', Shift::PUBLISHED)->count())->toBe(3);

    DB::flushQueryLog();
    DB::enableQueryLog();
    $found = AbsenceDays::upcomingShiftsOfAbsent(CarbonImmutable::today('Europe/Stockholm'));
    $sql = collect(DB::getQueryLog())->pluck('query')->filter(fn (string $query): bool => str_contains($query, 'planning_shifts'));
    DB::disableQueryLog();

    // Only Anna's shift inside the eight weeks: not the March one (beyond the horizon), not Bertil's.
    expect($found)->toHaveCount(1)
        ->and($found->first()->employee_id)->toBe($this->anna->id)
        ->and($found->first()->starts_at->toDateString())->toBe('2026-11-10')
        // Only the shifts of people with absence are read at all.
        ->and($sql->first(fn (string $query): bool => str_contains($query, '"employee_id" in')))->not->toBeNull();
});
