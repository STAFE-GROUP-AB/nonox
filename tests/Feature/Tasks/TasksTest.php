<?php

declare(strict_types=1);

use App\Actions\Tasks\GenerateRecurringTasks;
use App\Actions\Tasks\SaveRecurrence;
use App\Actions\Tasks\SaveTask;
use App\Actions\Tasks\WorkOnTask;
use App\Domain\Tasks\TaskFrequency;
use App\Domain\Tasks\TaskPeople;
use App\Domain\Tasks\TaskStatus;
use App\Livewire\Tasks\TaskCenter;
use App\Livewire\Tasks\TaskDetail;
use App\Livewire\Tasks\TaskForm;
use App\Mail\TaskDigestMail;
use App\Models\AuditLog;
use App\Models\Company;
use App\Models\Task;
use App\Models\TaskRecurrence;
use App\Models\User;
use App\Notifications\TaskActivity;
use App\Support\Overview\TaskTodoProvider;
use App\Support\Tenancy\CompanyScope;
use App\Support\Tenancy\CurrentCompany;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Tests\Support\Bookkeeping;

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-11-04 09:00:00'));
    [$this->owner, $this->company] = Bookkeeping::company();
    $this->consultant = Bookkeeping::member($this->company, 'accountant');
});

function newTask(User $actor, Company $company, array $input = []): Task
{
    return app(SaveTask::class)($actor, $company, ['title' => 'Moms oktober', 'type' => 'VAT', ...$input]);
}

it('creates, edits and deletes a task with an audit trail', function () {
    $task = newTask($this->consultant, $this->company, [
        'description' => 'Stäm av 2650 först.',
        'due_date' => '2026-11-12',
        'priority' => 'HIGH',
        'assignee_id' => $this->consultant->id,
        'checklist' => ['Stäm av bank', '', 'Bokför momsen'],
    ]);

    expect($task->company_id)->toBe($this->company->id)
        ->and($task->status)->toBe(TaskStatus::Todo)
        ->and($task->created_by)->toBe($this->consultant->id)
        ->and(CurrentCompany::run($this->company, fn () => $task->checklistItems()->pluck('label')->all()))->toBe(['Stäm av bank', 'Bokför momsen']);

    app(SaveTask::class)($this->consultant, $this->company, ['title' => 'Moms oktober 2026', 'type' => 'VAT', 'assignee_id' => $this->owner->id], $task);
    expect($task->fresh()->title)->toBe('Moms oktober 2026')->and($task->fresh()->assignee_id)->toBe($this->owner->id);

    app(WorkOnTask::class)->delete($this->consultant, $task);
    expect(CompanyScope::bypass(fn () => Task::query()->whereKey($task->id)->exists()))->toBeFalse();

    $actions = AuditLog::query()->where('company_id', $this->company->id)->where('action', 'like', 'task.%')->orderBy('id')->pluck('action')->all();
    expect($actions)->toBe(['task.created', 'task.assigned', 'task.updated', 'task.assigned', 'task.deleted']);
    // The audit trail keeps no task text.
    expect(json_encode(AuditLog::query()->where('action', 'like', 'task.%')->pluck('payload')))->not->toContain('Moms oktober');
});

it('lets owner, admin, accountant and bookkeeper manage tasks, and approver and auditor only read', function (string $role, bool $manage) {
    $member = Bookkeeping::member($this->company, $role);
    $task = newTask($this->consultant, $this->company);

    expect($member->can('create', [Task::class, $this->company]))->toBe($manage)
        ->and($member->can('update', $task))->toBe($manage)
        ->and($member->can('delete', $task))->toBe($manage)
        ->and($member->can('view', $task))->toBeTrue()
        ->and($member->can('work', $task))->toBe($manage);

    if (! $manage) {
        expect(fn () => newTask($member, $this->company))->toThrow(AuthorizationException::class)
            ->and(fn () => app(WorkOnTask::class)->status($member, $task, TaskStatus::Done))->toThrow(AuthorizationException::class)
            ->and(fn () => app(WorkOnTask::class)->comment($member, $task, 'Hej'))->toThrow(AuthorizationException::class);
    }
})->with([
    'admin' => ['admin', true],
    'accountant' => ['accountant', true],
    'bookkeeper' => ['bookkeeper', true],
    'approver' => ['approver', false],
    'auditor' => ['auditor', false],
]);

it('gives the owner every task permission', function () {
    expect($this->owner->hasTeamPermission($this->company, 'tasks:manage'))->toBeTrue()
        ->and($this->owner->hasTeamPermission($this->company, 'tasks:view'))->toBeTrue();
});

it('refuses to assign a task to someone outside the company with 404', function () {
    [$strangerOwner] = Bookkeeping::company();

    expect(fn () => newTask($this->consultant, $this->company, ['assignee_id' => $strangerOwner->id]))->toThrow(NotFoundHttpException::class)
        ->and(fn () => newTask($this->consultant, $this->company, ['assignee_id' => 999_999_999]))->toThrow(NotFoundHttpException::class)
        ->and(fn () => app(SaveRecurrence::class)($this->consultant, $this->company, ['title' => 'Moms', 'type' => 'VAT', 'frequency' => 'MONTHLY', 'due_day' => 12, 'assignee_id' => $strangerOwner->id]))->toThrow(NotFoundHttpException::class);

    expect(CompanyScope::bypass(fn () => Task::query()->count()))->toBe(0);
});

it('validates the task', function () {
    expect(fn () => newTask($this->consultant, $this->company, ['title' => '']))->toThrow(ValidationException::class)
        ->and(fn () => newTask($this->consultant, $this->company, ['type' => 'NOPE']))->toThrow(ValidationException::class)
        ->and(fn () => newTask($this->consultant, $this->company, ['due_date' => '12/11/2026']))->toThrow(ValidationException::class)
        ->and(fn () => newTask($this->consultant, $this->company, ['subject_type' => 'App\\Models\\User', 'subject_id' => '1']))->toThrow(ValidationException::class);
});

it('links a record of the same company only', function () {
    $voucher = CurrentCompany::run($this->company, fn () => Bookkeeping::post($this->owner, $this->company, 10_000, 1930, 3001, '2026-10-05'));
    [$otherOwner, $other] = Bookkeeping::company();
    $foreign = CurrentCompany::run($other, fn () => Bookkeeping::post($otherOwner, $other, 10_000, 1930, 3001, '2026-10-05'));

    $task = newTask($this->consultant, $this->company, ['subject_type' => 'voucher', 'subject_id' => $voucher->id]);
    expect($task->subject_type)->toBe('voucher')->and($task->subject_id)->toBe($voucher->id);

    expect(fn () => newTask($this->consultant, $this->company, ['subject_type' => 'voucher', 'subject_id' => $foreign->id]))->toThrow(ValidationException::class);
});

it('lets the client work on a task handed to them, and shows it on their overview', function () {
    $client = Bookkeeping::member($this->company, 'employee');
    $task = newTask($this->consultant, $this->company, ['title' => 'Skicka kvitto för köpet den 12 oktober', 'type' => 'DOCUMENTS', 'status' => 'WAITING_FOR_CLIENT', 'assignee_id' => $client->id]);

    expect($client->hasTeamPermission($this->company, 'tasks:view'))->toBeFalse()
        ->and($client->can('view', $task))->toBeTrue()
        ->and($client->can('work', $task))->toBeTrue()
        ->and($client->can('update', $task))->toBeFalse();

    $todos = app(TaskTodoProvider::class)->todos($client, $this->company, CarbonImmutable::today());
    expect($todos)->toHaveCount(1)->and($todos[0]->title)->toBe('Skicka kvitto för köpet den 12 oktober')
        ->and($todos[0]->url)->toBe(route('tasks.show', $task));
    expect(app(TaskTodoProvider::class)->todos($this->owner, $this->company, CarbonImmutable::today()))->toBe([]);

    $other = newTask($this->consultant, $this->company, ['title' => 'Internt: stäm av lönen']);
    expect($client->can('view', $other))->toBeFalse();

    $this->actingAs($client);
    Livewire::test(TaskCenter::class)
        ->assertSee('Skicka kvitto för köpet den 12 oktober')
        ->assertDontSee('Internt: stäm av lönen');

    // The client company's owner, the usual person the firm asks for something, sees it on the overview.
    $ask = newTask($this->consultant, $this->company, ['title' => 'Skicka kontoutdraget för oktober', 'status' => 'WAITING_FOR_CLIENT', 'assignee_id' => $this->owner->id]);
    $this->actingAs($this->owner);
    $this->get(route('tasks.index'))->assertOk()->assertSee('Skicka kontoutdraget för oktober');
    $this->get(route('tasks.show', $ask))->assertOk()->assertSee('Väntar på kund');

    app(WorkOnTask::class)->comment($client, $task, 'Kvittot är uppladdat.');
    app(WorkOnTask::class)->status($client, $task, TaskStatus::Done);

    expect($task->fresh()->status)->toBe(TaskStatus::Done)
        ->and($task->fresh()->completed_by)->toBe($client->id);

    // The client is removed from the company: the old assignment gives nothing.
    $this->company->users()->detach($client);
    expect($client->fresh()->can('view', $task->fresh()))->toBeFalse();
});

it('notifies the assignee, the creator on comments and on completion, never the actor', function () {
    $colleague = Bookkeeping::member($this->company, 'bookkeeper');
    $task = newTask($this->consultant, $this->company, ['assignee_id' => $colleague->id]);
    newTask($this->consultant, $this->company, ['title' => 'Egen', 'assignee_id' => $this->consultant->id]);

    expect($colleague->notifications()->count())->toBe(1)
        ->and($colleague->notifications()->first()->data)->toMatchArray(['kind' => 'assigned', 'company_id' => $this->company->id, 'task_id' => $task->id, 'title' => 'Moms oktober'])
        ->and($this->consultant->notifications()->count())->toBe(0);

    app(WorkOnTask::class)->comment($colleague, $task, 'Jag tar den i morgon.');
    expect($this->consultant->notifications()->count())->toBe(1)->and($colleague->notifications()->count())->toBe(1);

    app(WorkOnTask::class)->status($colleague, $task, TaskStatus::Done);
    expect($this->consultant->notifications()->pluck('data')->pluck('kind')->sort()->values()->all())->toBe(['commented', 'completed']);

    expect(TaskActivity::sentence($colleague->notifications()->first()->data))->toContain('gav dig uppgiften ”Moms oktober”');
});

it('shows the bell with unread notices and opens a notice in its company', function () {
    $colleague = Bookkeeping::member($this->company, 'bookkeeper');
    [, $second] = Bookkeeping::company();
    $second->users()->attach($colleague, ['role' => 'bookkeeper']);
    $colleague->switchCompany($second);
    $task = newTask($this->consultant, $this->company, ['assignee_id' => $colleague->id]);
    $notice = $colleague->notifications()->first();

    $this->actingAs($colleague->fresh());
    $this->get(route('notifications.index'))->assertOk()->assertSee('Notiser, 1 oläst')->assertSee('gav dig uppgiften');

    $this->put(route('current-team.update'), ['team_id' => $this->company->id, 'uppgift' => $task->id, 'notis' => $notice->id])
        ->assertRedirect(route('tasks.show', $task));
    expect($colleague->fresh()->current_team_id)->toBe($this->company->id)
        ->and($notice->fresh()->read_at)->not->toBeNull();
});

it('generates recurring tasks once per period with the due day in the next month', function () {
    $recurrence = app(SaveRecurrence::class)($this->consultant, $this->company, [
        'title' => 'Moms', 'type' => 'VAT', 'frequency' => 'MONTHLY', 'due_day' => 12, 'assignee_id' => $this->owner->id,
    ]);

    $tasks = fn () => CompanyScope::bypass(fn () => Task::query()->where('recurrence_id', $recurrence->id)->orderBy('period')->get());

    expect($tasks())->toHaveCount(1)
        ->and($tasks()[0]->period)->toBe('2026-11')
        ->and($tasks()[0]->due_date->toDateString())->toBe('2026-12-12')
        ->and($tasks()[0]->title)->toBe('Moms – november 2026')
        ->and($tasks()[0]->assignee_id)->toBe($this->owner->id)
        ->and($this->owner->notifications()->count())->toBe(1);

    expect(app(GenerateRecurringTasks::class)())->toBe(0);

    $this->travelTo(CarbonImmutable::parse('2026-12-01 05:10:00'));
    $this->artisan('tasks:generate-recurring')->assertSuccessful();
    $this->artisan('tasks:generate-recurring')->assertSuccessful();

    expect($tasks()->pluck('period')->all())->toBe(['2026-11', '2026-12'])
        ->and($tasks()[1]->due_date->toDateString())->toBe('2027-01-12');

    app(SaveRecurrence::class)->stop($this->consultant, $recurrence);
    $this->travelTo(CarbonImmutable::parse('2027-01-02'));
    expect(app(GenerateRecurringTasks::class)())->toBe(0);
});

it('works out periods and due dates for quarters and years', function () {
    $day = CarbonImmutable::parse('2026-11-04');

    expect(TaskFrequency::Quarterly->periodOf($day))->toBe('2026-10')
        ->and(TaskFrequency::Quarterly->dueDate('2026-10', 12)->toDateString())->toBe('2027-01-12')
        ->and(TaskFrequency::Yearly->periodOf($day))->toBe('2026-01')
        ->and(TaskFrequency::Yearly->dueDate('2026-01', 31)->toDateString())->toBe('2027-01-31')
        ->and(TaskFrequency::Monthly->dueDate('2027-01', 31)->toDateString())->toBe('2027-02-28');
});

it('leaves a generated task unassigned when the assignee has left the company', function () {
    $colleague = Bookkeeping::member($this->company, 'bookkeeper');
    $recurrence = CurrentCompany::run($this->company, fn () => TaskRecurrence::query()->create([
        'title' => 'AGI', 'type' => 'AGI', 'frequency' => 'MONTHLY', 'due_day' => 12, 'assignee_id' => $colleague->id, 'active' => true, 'created_by' => $this->consultant->id,
    ]));
    $this->company->users()->detach($colleague);

    $task = app(GenerateRecurringTasks::class)->for($recurrence);

    expect($task?->assignee_id)->toBeNull();
});

it('sends the daily digest only to those who asked, with tasks from their own companies', function () {
    Mail::fake();
    $this->consultant->forceFill(['task_digest' => 'DAILY'])->save();
    $quiet = Bookkeeping::member($this->company, 'bookkeeper');
    newTask($this->owner, $this->company, ['title' => 'Bokslut 2026', 'assignee_id' => $this->consultant->id, 'due_date' => '2026-11-01']);
    newTask($this->owner, $this->company, ['title' => 'Tyst uppgift', 'assignee_id' => $quiet->id]);
    $left = newTask($this->owner, $this->company, ['title' => 'Klar redan', 'assignee_id' => $this->consultant->id]);
    app(WorkOnTask::class)->status($this->owner, $left, TaskStatus::Done);

    $this->artisan('tasks:send-digest')->assertSuccessful();

    Mail::assertSent(TaskDigestMail::class, 1);
    Mail::assertSent(TaskDigestMail::class, fn (TaskDigestMail $mail): bool => $mail->hasTo($this->consultant->email)
        && count($mail->tasks) === 1 && $mail->tasks[0]['title'] === 'Bokslut 2026' && $mail->tasks[0]['overdue'] === true);
});

it('runs the pages with toasts: new task, status, comment, checklist', function () {
    $this->actingAs($this->consultant);

    Livewire::test(TaskForm::class, ['companyId' => $this->company->id])
        ->set('open', true)
        ->set('title', 'Skicka underlag för oktober')
        ->set('type', 'DOCUMENTS')
        ->set('status', 'WAITING_FOR_CLIENT')
        ->set('assigneeId', (string) $this->owner->id)
        ->set('checklist', "Kvitton\nKontoutdrag")
        ->call('save')
        ->assertHasNoErrors()
        ->assertDispatched('toast')
        ->assertDispatched('task-saved');

    $task = Task::query()->latest('id')->firstOrFail();
    expect($task->status)->toBe(TaskStatus::WaitingForClient);

    $this->get(route('tasks.index', ['visa' => 'vantar']))->assertOk()->assertSee('Skicka underlag för oktober')->assertSee('Väntar på kund')->assertSee('Ny uppgift');

    Livewire::test(TaskDetail::class, ['task' => $task])
        ->call('setStatus', 'IN_PROGRESS')->assertDispatched('toast')
        ->set('comment', 'Påminde kunden i telefon.')->call('addComment')->assertDispatched('toast')
        ->set('checklistLabel', 'Leverantörsfakturor')->call('addChecklistItem')->assertDispatched('toast')
        ->assertSee('Påminde kunden i telefon.')
        ->assertSee('Leverantörsfakturor');

    expect($task->fresh()->status)->toBe(TaskStatus::InProgress);
});

it('lists my tasks across my companies and switches the digest on', function () {
    [$secondOwner, $second] = Bookkeeping::company();
    $second->users()->attach($this->consultant, ['role' => 'accountant']);
    newTask($this->owner, $this->company, ['title' => 'Uppgift i första', 'assignee_id' => $this->consultant->id]);
    newTask($secondOwner, $second, ['title' => 'Uppgift i andra', 'assignee_id' => $this->consultant->id]);
    [$strangerOwner, $stranger] = Bookkeeping::company();
    newTask($strangerOwner, $stranger, ['title' => 'Främmande uppgift', 'assignee_id' => $strangerOwner->id]);

    $this->actingAs($this->consultant->fresh());

    Livewire::test(TaskCenter::class)
        ->assertSee('Uppgift i första')
        ->assertSee('Uppgift i andra')
        ->assertDontSee('Främmande uppgift')
        ->set('company', (string) $second->id)
        ->assertDontSee('Uppgift i första')
        ->assertSee('Uppgift i andra')
        ->set('company', (string) $stranger->id)
        ->assertDontSee('Främmande uppgift')
        ->set('company', '')
        ->set('digest', 'DAILY')
        ->assertDispatched('toast');

    expect($this->consultant->fresh()->task_digest)->toBe('DAILY');
    $this->get('/uppgifter')->assertOk()->assertSee('Uppgift i andra');
    $this->get('/mina-uppgifter')->assertRedirect('/uppgifter');
});

it('shows the firm every client\'s tasks, and filters by who the task is with', function () {
    [$secondOwner, $second] = Bookkeeping::company();
    $second->users()->attach($this->consultant, ['role' => 'accountant']);
    newTask($this->owner, $this->company, ['title' => 'Kvitton saknas', 'assignee_id' => $this->owner->id]);
    newTask($secondOwner, $second, ['title' => 'Momsavstämning klar', 'assignee_id' => $this->consultant->id]);
    $this->actingAs($this->consultant->fresh());

    Livewire::test(TaskCenter::class)
        ->set('view', 'alla')
        ->assertSee('Kvitton saknas')
        ->assertSee('Momsavstämning klar')
        ->set('side', 'kunden')
        ->assertSee('Kvitton saknas')
        ->assertDontSee('Momsavstämning klar')
        ->set('side', 'byran')
        ->assertSee('Momsavstämning klar')
        ->assertDontSee('Kvitton saknas');
});

it('delegates both ways between the firm and the client, grouped as Byrån and Kunden', function () {
    $people = TaskPeople::of($this->company);
    expect(array_keys($people['firm']))->toBe([$this->consultant->id])
        ->and(array_keys($people['client']))->toContain($this->owner->id);

    // The firm hands a task to the client: it waits for the client.
    $this->actingAs($this->consultant);
    Livewire::test(TaskForm::class)
        ->call('start')
        ->assertSet('companyId', (string) $this->company->id)
        ->assertSee('Byrån')
        ->assertSee('Kunden')
        ->set('title', 'Skicka kvitton för oktober')
        ->set('assigneeId', (string) $this->owner->id)
        ->assertSet('status', 'WAITING_FOR_CLIENT')
        ->call('save')
        ->assertHasNoErrors();
    expect($this->owner->notifications()->count())->toBe(1);

    // The client hands one back to the firm: it is the firm's to do.
    $this->actingAs($this->owner);
    Livewire::test(TaskForm::class)
        ->call('start')
        ->set('title', 'Hur bokför jag leasingbilen?')
        ->set('status', 'WAITING_FOR_CLIENT')
        ->set('assigneeId', (string) $this->consultant->id)
        ->assertSet('status', 'TODO')
        ->call('save')
        ->assertHasNoErrors();

    $back = Task::query()->where('title', 'Hur bokför jag leasingbilen?')->firstOrFail();
    expect($back->assignee_id)->toBe($this->consultant->id)
        ->and($back->status)->toBe(TaskStatus::Todo)
        ->and($this->consultant->notifications()->count())->toBe(1)
        ->and(AuditLog::query()->where('action', 'task.assigned')->where('subject_id', (string) $back->id)->value('payload'))->toMatchArray(['side' => 'firm']);
});

it('refuses to pick a company the user does not belong to in the new-task form', function () {
    [$strangerOwner, $stranger] = Bookkeeping::company();
    $this->actingAs($this->consultant);

    Livewire::test(TaskForm::class)
        ->call('start')
        ->set('companyId', (string) $stranger->id)
        ->set('title', 'Smyguppgift')
        ->call('save')
        ->assertHasErrors('companyId');

    expect(CompanyScope::bypass(fn () => Task::query()->where('title', 'Smyguppgift')->exists()))->toBeFalse();
});

it('reads a page of tasks with the same number of queries for many companies', function () {
    $count = function (int $companies): int {
        $user = User::factory()->create();

        for ($i = 0; $i < $companies; $i++) {
            [$owner, $company] = Bookkeeping::company();
            $company->users()->attach($user, ['role' => 'accountant']);
            newTask($owner, $company, ['title' => "Uppgift {$i}", 'assignee_id' => $user->id, 'checklist' => ['A']]);
        }

        $this->actingAs($user->fresh());
        DB::flushQueryLog();
        DB::enableQueryLog();
        Livewire::test(TaskCenter::class);
        $queries = count(DB::getQueryLog());
        DB::disableQueryLog();

        return $queries;
    };

    expect($count(8))->toBe($count(1));
});
