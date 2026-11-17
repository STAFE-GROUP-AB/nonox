<?php

declare(strict_types=1);

use App\Models\AuditLog;
use App\Modules\ModuleManager;
use App\Modules\Planning\Actions\DeleteShift;
use App\Modules\Planning\Actions\PublishPeriod;
use App\Modules\Planning\Actions\SaveArea;
use App\Modules\Planning\Actions\SaveShift;
use App\Modules\Planning\Actions\SaveShiftTask;
use App\Modules\Planning\Actions\SaveSkill;
use App\Modules\Planning\Actions\SaveTaskTemplate;
use App\Modules\Planning\Actions\TickShiftTask;
use App\Modules\Planning\Livewire\MySchedule;
use App\Modules\Planning\Livewire\SchedulePage;
use App\Modules\Planning\Livewire\TaskTemplatesPage;
use App\Modules\Planning\Models\Shift;
use App\Modules\Planning\Models\ShiftTask;
use App\Modules\Planning\Models\TaskTemplate;
use App\Modules\Planning\PlanningModule;
use App\Modules\StaffLedger\Models\Entry;
use App\Support\Privacy\DataSubjectExport;
use App\Support\Tenancy\CurrentCompany;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Tests\Support\Bookkeeping;
use Tests\Support\Planning;

/*
 * Planering: tasks in shifts (ADR-037 gap 4). Templates put tasks on shifts
 * when a shift is saved or a period is published; the employee on the
 * published shift ticks them off in Min sida and a planner sees and may tick
 * them on the schedule. A work aid: nothing here touches the staff ledger,
 * and a tick is shown without a name and audited with ids only.
 */

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-11-04 10:00:00', 'Europe/Stockholm'));
    [$this->owner, $this->company] = Bookkeeping::company();
    Planning::setUp($this->company, $this->owner);
    $this->worker = Bookkeeping::member($this->company, 'employee');
    $this->colleague = Bookkeeping::member($this->company, 'employee');
    $this->scheduler = Bookkeeping::member($this->company, 'scheduler');
    CurrentCompany::set($this->company);
    $this->anna = Planning::employee($this->owner, $this->company, 'Anna', '900615123', ['user_id' => $this->worker->id]);
    $this->bertil = Planning::employee($this->owner, $this->company, 'Bertil', '850301234', ['user_id' => $this->colleague->id]);
    $this->kitchen = app(SaveArea::class)($this->owner, $this->company, ['name' => 'Kök', 'colour' => '#E4572E']);
    $this->hall = app(SaveArea::class)($this->owner, $this->company, ['name' => 'Servering', 'colour' => '#2E86AB']);
    $this->monday = CarbonImmutable::parse('2026-11-09', 'Europe/Stockholm');
});

/** @param  array<string, mixed>  $input */
function taskTemplate(array $input = []): TaskTemplate
{
    $test = test();

    return CurrentCompany::run($test->company, fn (): TaskTemplate => app(SaveTaskTemplate::class)($test->owner, $test->company, [
        'title' => 'Kontrollera kylen',
        'weekdays' => [2],
        'area_id' => $test->kitchen->id,
        ...$input,
    ])['template']);
}

/** @param  array<string, mixed>  $input */
function taskShift(array $input = []): Shift
{
    return Planning::shift(test()->owner, test()->company, [
        'employee_id' => test()->anna->id,
        'area_id' => test()->kitchen->id,
        'date' => '2026-11-10',
        'start' => '08:00',
        'end' => '16:00',
        ...$input,
    ]);
}

function publishTasksWeek(): void
{
    $test = test();
    CurrentCompany::run($test->company, fn () => app(PublishPeriod::class)($test->owner, $test->company, $test->monday, $test->monday->addDays(6)));
}

it('puts a template\'s task on the shifts that match when a shift is saved', function () {
    taskTemplate();

    $matching = taskShift();
    $otherDay = taskShift(['date' => '2026-11-11']);
    $otherArea = taskShift(['area_id' => $this->hall->id]);

    expect($matching->tasks()->pluck('title')->all())->toBe(['Kontrollera kylen'])
        ->and($otherDay->tasks()->count())->toBe(0)
        ->and($otherArea->tasks()->count())->toBe(0)
        ->and(ShiftTask::query()->where('shift_id', $matching->id)->value('template_id'))->not->toBeNull();
});

it('matches on skill and on a time of day inside the shift, also past midnight', function () {
    $till = app(SaveSkill::class)($this->owner, $this->company, 'Kassa');
    taskTemplate(['title' => 'Räkna kassan', 'area_id' => null, 'skill_id' => $till->id, 'weekdays' => [2]]);
    taskTemplate(['title' => 'Stäng ugnen', 'area_id' => null, 'due_time' => '22:30', 'weekdays' => [2]]);

    $day = taskShift(['area_id' => null]);
    $withSkill = taskShift(['area_id' => null, 'skill_id' => $till->id, 'start' => '08:00', 'end' => '12:00']);
    $evening = taskShift(['area_id' => null, 'start' => '18:00', 'end' => '23:00']);
    $overMidnight = taskShift(['area_id' => null, 'start' => '20:00', 'end' => '02:00', 'date' => '2026-11-10']);
    $monday = taskShift(['area_id' => null, 'date' => '2026-11-09', 'start' => '21:00', 'end' => '02:00']);

    expect($day->tasks()->count())->toBe(0)
        ->and($withSkill->tasks()->pluck('title')->all())->toBe(['Räkna kassan'])
        ->and($evening->tasks()->pluck('title')->all())->toBe(['Stäng ugnen'])
        ->and($overMidnight->tasks()->pluck('title')->all())->toBe(['Stäng ugnen'])
        ->and($monday->tasks()->count())->toBe(0);
});

it('respects the weekdays, the validity period and the active switch', function () {
    taskTemplate(['title' => 'Bara senare', 'valid_from' => '2026-11-11']);
    taskTemplate(['title' => 'Bara tidigare', 'valid_to' => '2026-11-09']);
    taskTemplate(['title' => 'Avstängd', 'is_active' => false]);
    taskTemplate(['title' => 'Alla dagar', 'weekdays' => [1, 2, 3, 4, 5, 6, 7], 'area_id' => null]);

    expect(taskShift()->tasks()->pluck('title')->all())->toBe(['Alla dagar']);
});

it('puts a new template on shifts that already exist and have not started, not on what has happened', function () {
    $future = taskShift();
    publishTasksWeek();
    $later = taskShift(['date' => '2026-11-17']);

    $result = app(SaveTaskTemplate::class)($this->owner, $this->company, ['title' => 'Kontrollera kylen', 'weekdays' => [2], 'area_id' => $this->kitchen->id]);

    expect($result['added'])->toBe(2)
        ->and($future->tasks()->count())->toBe(1)
        ->and($later->tasks()->count())->toBe(1);

    $this->travelTo(CarbonImmutable::parse('2026-11-18 10:00:00', 'Europe/Stockholm'));
    ShiftTask::query()->delete();
    $result = app(SaveTaskTemplate::class)($this->owner, $this->company, ['title' => 'Torka bänkarna', 'weekdays' => [2], 'area_id' => $this->kitchen->id]);

    expect($result['added'])->toBe(0)
        ->and(ShiftTask::query()->count())->toBe(0);
});

it('puts the tasks on shifts when the period is published', function () {
    $shift = taskShift();
    ShiftTask::query()->delete();
    expect($shift->tasks()->count())->toBe(0);

    taskTemplate();
    ShiftTask::query()->delete();
    publishTasksWeek();

    expect($shift->tasks()->pluck('title')->all())->toBe(['Kontrollera kylen']);
});

it('keeps a template task in step with its template until something is ticked', function () {
    $template = taskTemplate();
    $shift = taskShift();
    $other = taskShift(['date' => '2026-11-17']);
    publishTasksWeek();

    $this->travelTo(CarbonImmutable::parse('2026-11-10 09:00:00', 'Europe/Stockholm'));
    app(TickShiftTask::class)($this->owner, $this->company, $shift->tasks()->first(), true);
    $this->travelTo(CarbonImmutable::parse('2026-11-04 10:00:00', 'Europe/Stockholm'));

    app(SaveTaskTemplate::class)($this->owner, $this->company, ['title' => 'Kontrollera frysen', 'weekdays' => [2], 'area_id' => $this->kitchen->id], $template);

    // The shift where a task was ticked is left as it was; the other follows the template.
    expect($shift->tasks()->pluck('title')->all())->toBe(['Kontrollera kylen'])
        ->and($other->tasks()->pluck('title')->all())->toBe(['Kontrollera frysen']);

    app(SaveTaskTemplate::class)($this->owner, $this->company, ['title' => 'Kontrollera frysen', 'weekdays' => [3], 'area_id' => $this->kitchen->id], $template);
    expect($other->tasks()->count())->toBe(0);

    app(SaveTaskTemplate::class)->delete($this->owner, $this->company, $template);
    expect(TaskTemplate::query()->count())->toBe(0)
        ->and($shift->tasks()->pluck('title')->all())->toBe(['Kontrollera kylen']);
});

it('removes the task from a shift that stops matching, and never touches a task written for the shift', function () {
    taskTemplate();
    $shift = taskShift();
    app(SaveShiftTask::class)($this->owner, $this->company, $shift, ['title' => 'Ring leverantören']);

    app(SaveShift::class)($this->owner, $this->company, ['employee_id' => $this->anna->id, 'area_id' => $this->hall->id, 'date' => '2026-11-10', 'start' => '08:00', 'end' => '16:00'], $shift);

    expect($shift->tasks()->pluck('title')->all())->toBe(['Ring leverantören']);

    app(SaveShift::class)($this->owner, $this->company, ['employee_id' => $this->anna->id, 'area_id' => $this->kitchen->id, 'date' => '2026-11-10', 'start' => '08:00', 'end' => '16:00'], $shift->fresh());
    expect($shift->tasks()->pluck('title')->all())->toBe(['Ring leverantören', 'Kontrollera kylen']);
});

it('writes, limits and removes tasks on one shift, but leaves template tasks to the template', function () {
    taskTemplate();
    $shift = taskShift();
    $save = app(SaveShiftTask::class);

    $task = $save($this->owner, $this->company, $shift, ['title' => 'Ring leverantören', 'instructions' => 'Före lunch']);
    expect($task->position)->toBe(1)
        ->and(fn () => $save($this->owner, $this->company, $shift, ['title' => '']))->toThrow(ValidationException::class)
        ->and(fn () => $save($this->owner, $this->company, $shift, ['title' => str_repeat('x', 121)]))->toThrow(ValidationException::class)
        ->and(fn () => $save->delete($this->owner, $this->company, $shift->tasks()->whereNotNull('template_id')->first()))->toThrow(ValidationException::class, 'kommer från en mall');

    $save->delete($this->owner, $this->company, $task);
    expect($shift->tasks()->count())->toBe(1);

    foreach (range(1, SaveShiftTask::MAX_PER_SHIFT - 1) as $number) {
        $save($this->owner, $this->company, $shift, ['title' => "Uppgift {$number}"]);
    }

    expect(fn () => $save($this->owner, $this->company, $shift, ['title' => 'En för mycket']))->toThrow(ValidationException::class, 'högst 30');
});

it('lets the employee on the published shift tick a task off in Min sida and take it back the same day', function () {
    taskTemplate();
    $shift = taskShift();
    publishTasksWeek();
    $task = $shift->tasks()->firstOrFail();

    $this->travelTo(CarbonImmutable::parse('2026-11-10 09:00:00', 'Europe/Stockholm'));
    $this->actingAs($this->worker);
    Livewire::test(MySchedule::class)
        ->call('nextWeek')
        ->assertSee('Kontrollera kylen')
        ->assertSeeHtml('data-shift-tasks="'.$shift->id.'"')
        ->call('toggleTask', $task->id)
        ->assertDispatched('toast')
        ->assertSee('Klar 09:00');

    $ticked = $task->fresh();
    expect($ticked->done_at->format('Y-m-d H:i'))->toBe('2026-11-10 09:00')
        ->and($ticked->done_by)->toBe($this->worker->id);

    Livewire::test(MySchedule::class)->call('nextWeek')->call('toggleTask', $task->id)->assertDispatched('toast');
    expect($task->fresh()->done_at)->toBeNull()
        ->and($task->fresh()->done_by)->toBeNull();
});

it('shows the tasks on the next shift card too', function () {
    taskTemplate();
    $shift = taskShift();
    publishTasksWeek();
    $this->travelTo(CarbonImmutable::parse('2026-11-10 07:00:00', 'Europe/Stockholm'));

    $this->actingAs($this->worker);
    Livewire::test(MySchedule::class)->assertSeeHtml('data-next-shift')->assertSeeHtml('data-shift-tasks="'.$shift->id.'"');
});

it('does not let a tick be made before the day of the shift or long after it, or undone another day', function () {
    taskTemplate();
    $shift = taskShift();
    publishTasksWeek();
    $task = $shift->tasks()->firstOrFail();
    $tick = app(TickShiftTask::class);

    expect(fn () => $tick($this->worker, $this->company, $task, true))->toThrow(ValidationException::class, 'från passets dag');

    $this->travelTo(CarbonImmutable::parse('2026-11-10 20:00:00', 'Europe/Stockholm'));
    $tick($this->worker, $this->company, $task, true);

    $this->travelTo(CarbonImmutable::parse('2026-11-11 08:00:00', 'Europe/Stockholm'));
    expect(fn () => $tick($this->worker, $this->company, $task->fresh(), false))->toThrow(ValidationException::class, 'samma dag');

    $other = $shift->tasks()->create(['title' => 'En till', 'position' => 5]);
    $this->travelTo(CarbonImmutable::parse('2026-11-12 08:00:00', 'Europe/Stockholm'));
    expect(fn () => $tick($this->worker, $this->company, $other, true))->toThrow(ValidationException::class, 'dagen efter');
});

it('does not let anyone but the employee on the published shift, or a planner, tick a task', function () {
    taskTemplate();
    $shift = taskShift();
    $task = $shift->tasks()->firstOrFail();
    $tick = app(TickShiftTask::class);
    $this->travelTo(CarbonImmutable::parse('2026-11-10 09:00:00', 'Europe/Stockholm'));

    // Not published yet: the employee has not been shown the shift.
    expect(fn () => $tick($this->worker, $this->company, $task, true))->toThrow(AuthorizationException::class);

    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-11-04 10:00:00', 'Europe/Stockholm'));
    publishTasksWeek();
    $this->travelTo(CarbonImmutable::parse('2026-11-10 09:00:00', 'Europe/Stockholm'));

    expect(fn () => $tick($this->colleague, $this->company, $task, true))->toThrow(AuthorizationException::class)
        ->and($task->fresh()->done_at)->toBeNull();

    $tick($this->scheduler, $this->company, $task, true);
    expect($task->fresh()->done_by)->toBe($this->scheduler->id);

    $this->actingAs($this->colleague);
    Livewire::test(MySchedule::class)->call('toggleTask', $task->id)->assertNotFound();
});

it('refuses to tick a task on a cancelled shift and while Planering is off', function () {
    taskTemplate();
    $shift = taskShift();
    publishTasksWeek();
    $task = $shift->tasks()->firstOrFail();
    $this->travelTo(CarbonImmutable::parse('2026-11-10 09:00:00', 'Europe/Stockholm'));

    app(DeleteShift::class)($this->owner, $this->company, $shift->fresh());
    expect(fn () => app(TickShiftTask::class)($this->worker, $this->company, $task, true))->toThrow(ValidationException::class, 'inställt');

    app(ModuleManager::class)->disable(PlanningModule::KEY, $this->company, $this->owner, confirmed: true);
    expect(fn () => app(TickShiftTask::class)($this->owner, $this->company, $task, true))->toThrow(Exception::class);
});

it('shows a planner the ticks on the schedule, lets them tick, and shows no name', function () {
    taskTemplate();
    $shift = taskShift();
    publishTasksWeek();
    $task = $shift->tasks()->firstOrFail();
    $this->travelTo(CarbonImmutable::parse('2026-11-10 09:00:00', 'Europe/Stockholm'));
    app(TickShiftTask::class)($this->worker, $this->company, $task, true);

    $this->actingAs($this->scheduler);
    $page = Livewire::withQueryParams(['vecka' => '2026-11-09'])->test(SchedulePage::class)
        ->assertSeeHtml('data-task-count')
        ->assertSee('✓ 1/1')
        ->call('edit', $shift->id)
        ->assertSeeHtml('data-shift-task-editor')
        ->assertSee('Kontrollera kylen')
        ->assertSee('Klar 09:00');

    $page->call('toggleTask', $task->id)->assertDispatched('toast');
    expect($task->fresh()->done_at)->toBeNull();

    $page->set('newTask', 'Ring leverantören')->call('addTask')->assertDispatched('toast')->assertSee('Ring leverantören');
    expect($shift->tasks()->count())->toBe(2);
});

it('keeps the task templates from employees', function () {
    $this->actingAs($this->worker);
    Livewire::test(TaskTemplatesPage::class)->assertForbidden();

    expect(fn () => app(SaveTaskTemplate::class)($this->worker, $this->company, ['title' => 'Nej', 'weekdays' => [1]]))->toThrow(AuthorizationException::class);
});

it('creates, changes and removes a task template from the page with a toast each time', function () {
    taskShift();
    $this->actingAs($this->scheduler);

    $page = Livewire::test(TaskTemplatesPage::class)
        ->call('create')
        ->set('form.title', 'Kontrollera kylen')
        ->set('form.weekdays', [2])
        ->set('form.area_id', $this->kitchen->id)
        ->call('save')
        ->assertDispatched('toast')
        ->assertSee('Kontrollera kylen');

    $template = TaskTemplate::query()->firstOrFail();
    $page->call('edit', $template->id)->set('form.title', 'Torka bänkarna')->call('save')->assertDispatched('toast')->assertSee('Torka bänkarna');
    $page->call('create')->set('form.title', 'Utan dagar')->set('form.weekdays', [])->call('save')->assertHasErrors(['form.weekdays']);
    $page->call('delete', $template->id)->assertDispatched('toast');

    expect(TaskTemplate::query()->count())->toBe(0);
});

it('audits with ids and field names, never the task text, and never touches the staff ledger', function () {
    $template = taskTemplate(['title' => 'Hemlig titel', 'instructions' => 'Hemlig instruktion']);
    $shift = taskShift();
    $task = $shift->tasks()->firstOrFail();
    $this->travelTo(CarbonImmutable::parse('2026-11-10 09:00:00', 'Europe/Stockholm'));
    app(TickShiftTask::class)($this->owner, $this->company, $task, true);
    app(SaveShiftTask::class)($this->owner, $this->company, $shift, ['title' => 'Annan hemlig']);

    $audit = AuditLog::query()->where('action', 'like', 'planning.%task%')->get();

    expect($audit->pluck('action')->all())->toContain('planning.task_template_created', 'planning.shift_task_done', 'planning.shift_task_added')
        ->and(json_encode($audit->pluck('payload')->all()))->not->toContain('Hemlig')
        ->and($audit->firstWhere('action', 'planning.shift_task_done')->payload)->toMatchArray(['shift_id' => $shift->id, 'by' => 'PLANNER'])
        ->and($template->fresh()->title)->toBe('Hemlig titel')
        ->and(Entry::query()->count())->toBe(0);
});

it('removes tasks and their ticks a year after the shift ended, and keeps the rest', function () {
    taskTemplate(['weekdays' => [1, 2, 3, 4, 5, 6, 7], 'area_id' => null]);
    $old = taskShift(['date' => '2025-10-01']);
    $recent = taskShift(['date' => '2026-06-01']);

    expect(ShiftTask::query()->count())->toBe(2);

    $this->artisan('planning:prune-tasks')->assertSuccessful();

    expect($old->tasks()->count())->toBe(0)
        ->and($recent->tasks()->count())->toBe(1);
});

it('loads Min sida in the same number of queries with 1 task and with 12', function () {
    taskTemplate(['weekdays' => [1, 2, 3, 4, 5, 6, 7], 'area_id' => null]);
    $count = function (): int {
        DB::flushQueryLog();
        DB::enableQueryLog();
        Livewire::test(MySchedule::class)->call('nextWeek');
        $queries = count(DB::getQueryLog());
        DB::disableQueryLog();

        return $queries;
    };

    taskShift();
    publishTasksWeek();
    $this->actingAs($this->worker);
    $count();
    $few = $count();

    foreach (range(1, 11) as $number) {
        taskTemplate(['title' => "Uppgift {$number}", 'weekdays' => [1, 2, 3, 4, 5, 6, 7], 'area_id' => null]);
    }

    foreach (['2026-11-11', '2026-11-12', '2026-11-13'] as $date) {
        taskShift(['date' => $date]);
    }

    publishTasksWeek();

    expect($count())->toBe($few);
});

it('loads the schedule and saves shifts in the same number of queries however many tasks there are', function () {
    $count = function (): int {
        DB::flushQueryLog();
        DB::enableQueryLog();
        Livewire::withQueryParams(['vecka' => '2026-11-09'])->test(SchedulePage::class)->call('$refresh');
        $queries = count(DB::getQueryLog());
        DB::disableQueryLog();

        return $queries;
    };
    // Publishing writes each shift (as it always has); the reads of the tasks stay a fixed number.
    $taskReads = function (): int {
        DB::flushQueryLog();
        DB::enableQueryLog();
        publishTasksWeek();
        $queries = collect(DB::getQueryLog())->filter(fn (array $query): bool => str_starts_with(strtolower($query['query']), 'select') && (str_contains($query['query'], 'planning_shift_tasks') || str_contains($query['query'], 'planning_task_templates')))->count();
        DB::disableQueryLog();

        return $queries;
    };

    taskTemplate(['weekdays' => [1, 2, 3, 4, 5, 6, 7], 'area_id' => null]);
    taskShift();
    $this->actingAs($this->scheduler);
    $count();
    $few = $count();
    $fewReads = $taskReads();

    foreach (range(1, 9) as $number) {
        taskTemplate(['title' => "Uppgift {$number}", 'weekdays' => [1, 2, 3, 4, 5, 6, 7], 'area_id' => null]);
    }

    foreach (['2026-11-09', '2026-11-11', '2026-11-12', '2026-11-13', '2026-11-14'] as $date) {
        taskShift(['date' => $date]);
    }

    expect($count())->toBe($few);

    ShiftTask::query()->delete();
    expect($taskReads())->toBe($fewReads);
});

it('answers 404 for another company\'s task', function () {
    [, $otherCompany] = Bookkeeping::company();
    $foreign = CurrentCompany::run($otherCompany, function (): ShiftTask {
        $shift = Shift::query()->create(['starts_at' => '2026-11-10 08:00', 'ends_at' => '2026-11-10 16:00', 'status' => Shift::DRAFT]);

        return ShiftTask::query()->create(['shift_id' => $shift->id, 'title' => 'Hemlig uppgift', 'position' => 0]);
    });

    $this->actingAs($this->scheduler);
    Livewire::test(SchedulePage::class)->call('toggleTask', $foreign->id)->assertNotFound();
    $this->actingAs($this->worker);
    Livewire::test(MySchedule::class)->call('toggleTask', $foreign->id)->assertNotFound();
});

it('includes the employee\'s own ticks, and only theirs, in the register extract', function () {
    taskTemplate(['weekdays' => [1, 2, 3, 4, 5, 6, 7], 'area_id' => null]);
    $shift = taskShift();
    publishTasksWeek();
    $this->travelTo(CarbonImmutable::parse('2026-11-10 09:00:00', 'Europe/Stockholm'));
    app(TickShiftTask::class)($this->worker, $this->company, $shift->tasks()->firstOrFail(), true);

    $annas = DataSubjectExport::employee($this->anna)['avbockade_uppgifter'];

    expect($annas)->toHaveCount(1)
        ->and($annas[0])->toMatchArray(['uppgift' => 'Kontrollera kylen', 'pass' => $shift->id])
        ->and(DataSubjectExport::employee($this->bertil)['avbockade_uppgifter'])->toBe([]);
});
