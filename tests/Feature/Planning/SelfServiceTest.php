<?php

declare(strict_types=1);

use App\Models\AuditLog;
use App\Modules\ModuleManager;
use App\Modules\Payroll\PayrollModule;
use App\Modules\Planning\Actions\DecideRequest;
use App\Modules\Planning\Actions\IssueIcalToken;
use App\Modules\Planning\Actions\PublishPeriod;
use App\Modules\Planning\Actions\RequestShift;
use App\Modules\Planning\Actions\RevokeIcalToken;
use App\Modules\Planning\Actions\SaveAvailability;
use App\Modules\Planning\Actions\SaveShift;
use App\Modules\Planning\Actions\SaveSkill;
use App\Modules\Planning\Livewire\MySchedule;
use App\Modules\Planning\Livewire\RequestsPage;
use App\Modules\Planning\Livewire\SchedulePage;
use App\Modules\Planning\Mail\ScheduleNoticeMail;
use App\Modules\Planning\Mail\ShiftRequestNoticeMail;
use App\Modules\Planning\Models\Availability;
use App\Modules\Planning\Models\EmployeeSkill;
use App\Modules\Planning\Models\IcalToken;
use App\Modules\Planning\Models\Shift;
use App\Modules\Planning\Models\ShiftRequest;
use App\Modules\Planning\Notifications\ScheduleNotice;
use App\Modules\Planning\Notifications\ShiftRequestNotice;
use App\Modules\Planning\PlanningModule;
use App\Modules\Planning\Support\PlanningTodos;
use App\Modules\StaffLedger\Models\Entry;
use App\Support\Tenancy\CurrentCompany;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Tests\Support\Bookkeeping;
use Tests\Support\Planning;

/*
 * Planering step 3 (PLANNING_MODULE.md §6–7, §12.3): Min sida's schedule,
 * availability, requests to claim, swap or drop a shift, the planner's
 * decision and the calendar link. An employee reaches only their own data,
 * and nothing here writes to the staff ledger.
 */

beforeEach(function () {
    Notification::fake();
    $this->travelTo(CarbonImmutable::parse('2026-11-04 10:00:00', 'Europe/Stockholm'));
    [$this->owner, $this->company] = Bookkeeping::company();
    Planning::setUp($this->company, $this->owner);
    $this->worker = Bookkeeping::member($this->company, 'employee');
    $this->colleague = Bookkeeping::member($this->company, 'employee');
    CurrentCompany::set($this->company);
    $this->anna = Planning::employee($this->owner, $this->company, 'Anna', '900615123', ['user_id' => $this->worker->id]);
    $this->bertil = Planning::employee($this->owner, $this->company, 'Bertil', '850301234', ['user_id' => $this->colleague->id]);
    $this->monday = CarbonImmutable::parse('2026-11-09', 'Europe/Stockholm');
});

/** Publishes the drafts of the week after this one. */
function publishNextWeek(): void
{
    $test = test();
    CurrentCompany::run($test->company, fn () => app(PublishPeriod::class)($test->owner, $test->company, $test->monday, $test->monday->addDays(6)));
}

function plannedShift(array $input): Shift
{
    return Planning::shift(test()->owner, test()->company, $input);
}

function ask(object $user, array $input): ShiftRequest
{
    return app(RequestShift::class)($user, test()->company, $input);
}

it('shows the employee their published shifts only, the next one first, with news marked', function () {
    $published = plannedShift(['employee_id' => $this->anna->id, 'date' => '2026-11-10', 'start' => '08:00', 'end' => '16:00']);
    plannedShift(['employee_id' => $this->bertil->id, 'date' => '2026-11-10', 'start' => '12:00', 'end' => '20:00']);
    publishNextWeek();
    plannedShift(['employee_id' => $this->anna->id, 'date' => '2026-11-12', 'start' => '09:00', 'end' => '13:00']);
    // An unpublished change: Anna still sees 08:00–16:00.
    app(SaveShift::class)($this->owner, $this->company, ['employee_id' => $this->anna->id, 'date' => '2026-11-10', 'start' => '10:00', 'end' => '18:00'], $published->fresh());

    $this->actingAs($this->worker);
    $component = Livewire::test(MySchedule::class)
        ->assertSeeHtml('data-next-shift')
        ->assertSee('Tisdag 10 november')
        ->call('nextWeek')
        ->assertSee('08:00–16:00')
        ->assertSee('Nytt eller ändrat')
        ->assertDontSee('12:00–20:00')
        ->assertDontSee('09:00–13:00');

    expect($component->get('week'))->toBe(1);
});

it('shows nothing in Min sida to a login that is not an employee, or while Planering is off', function () {
    $this->actingAs($this->owner);
    Livewire::test(MySchedule::class)->assertDontSee('Mitt schema');

    $this->actingAs($this->worker);
    Livewire::test(MySchedule::class)->assertSee('Mitt schema');

    app(ModuleManager::class)->disable(PlanningModule::KEY, $this->company, $this->owner, confirmed: true);
    Livewire::test(MySchedule::class)->assertDontSee('Mitt schema');
    $this->get(route('payroll.portal.home'))->assertOk()->assertDontSee('Mitt schema');
});

it('keeps the employee portal working with the schedule on it', function () {
    plannedShift(['employee_id' => $this->anna->id, 'date' => '2026-11-10', 'start' => '08:00', 'end' => '16:00']);
    publishNextWeek();

    $this->actingAs($this->worker)->get(route('payroll.portal.home'))
        ->assertOk()
        ->assertSee('Nästa pass')
        ->assertSee('Frånvaro')
        ->assertSee('Mina lönebesked');
});

it('saves the employee\'s availability, weekly or for a date, and audits it', function () {
    $save = app(SaveAvailability::class);
    $weekly = $save($this->worker, $this->company, ['kind' => 'UNAVAILABLE', 'repeat' => 'weekly', 'weekday' => 3, 'whole_day' => true]);
    $dated = $save($this->worker, $this->company, ['kind' => 'PREFERRED', 'repeat' => 'date', 'date' => '2026-11-14', 'whole_day' => false, 'from' => '10:00', 'to' => '14:00', 'note' => 'Gärna lunch']);

    expect($weekly->employee_id)->toBe($this->anna->id)
        ->and($weekly->weekday)->toBe(3)
        ->and($weekly->date)->toBeNull()
        ->and($dated->date->toDateString())->toBe('2026-11-14')
        ->and($dated->from)->toBe('10:00')
        ->and(AuditLog::query()->where('action', 'planning.availability_created')->count())->toBe(2);

    $save($this->worker, $this->company, ['kind' => 'AVAILABLE', 'repeat' => 'weekly', 'weekday' => 3, 'whole_day' => true], $weekly);
    expect($weekly->fresh()->kind)->toBe('AVAILABLE');

    $save->delete($this->worker, $this->company, $dated);
    expect(Availability::query()->count())->toBe(1)
        ->and(AuditLog::query()->where('action', 'planning.availability_deleted')->exists())->toBeTrue();
});

it('validates availability and refuses it when the company has turned it off', function () {
    $save = app(SaveAvailability::class);

    expect(fn () => $save($this->worker, $this->company, ['kind' => 'UNAVAILABLE', 'repeat' => 'date', 'date' => '2026-11-01', 'whole_day' => true]))->toThrow(ValidationException::class, 'från och med i dag')
        ->and(fn () => $save($this->worker, $this->company, ['kind' => 'UNAVAILABLE', 'repeat' => 'weekly', 'weekday' => 2, 'whole_day' => false, 'from' => '14:00', 'to' => '10:00']))->toThrow(ValidationException::class, 'efter starttiden')
        ->and(fn () => $save($this->worker, $this->company, ['kind' => 'MAYBE', 'repeat' => 'weekly', 'weekday' => 2, 'whole_day' => true]))->toThrow(ValidationException::class);

    app(ModuleManager::class)->updateSettings(PlanningModule::KEY, $this->company, $this->owner, ['allow_availability' => false]);
    expect(fn () => $save($this->worker, $this->company, ['kind' => 'UNAVAILABLE', 'repeat' => 'weekly', 'weekday' => 2, 'whole_day' => true]))->toThrow(ValidationException::class, 'tar inte emot tillgänglighet');
});

it('never lets an employee change a colleague\'s availability', function () {
    $save = app(SaveAvailability::class);
    $bertils = $save($this->colleague, $this->company, ['kind' => 'UNAVAILABLE', 'repeat' => 'weekly', 'weekday' => 5, 'whole_day' => true]);

    expect(fn () => $save($this->worker, $this->company, ['kind' => 'AVAILABLE', 'repeat' => 'weekly', 'weekday' => 5, 'whole_day' => true], $bertils))->toThrow(ModelNotFoundException::class)
        ->and(fn () => $save->delete($this->worker, $this->company, $bertils))->toThrow(ModelNotFoundException::class)
        ->and($bertils->fresh()->kind)->toBe('UNAVAILABLE');
});

it('lets an employee ask for an open published shift without changing the schedule or the staff ledger', function () {
    $open = plannedShift(['date' => '2026-11-11', 'start' => '17:00', 'end' => '23:00']);
    publishNextWeek();

    $request = ask($this->worker, ['kind' => 'CLAIM', 'shift_id' => $open->id, 'note' => 'Jag kan!']);

    expect($request->status)->toBe(ShiftRequest::PENDING)
        ->and($request->requested_by)->toBe($this->anna->id)
        ->and($open->fresh()->employee_id)->toBeNull()
        ->and($open->fresh()->status)->toBe(Shift::PUBLISHED)
        ->and(Entry::query()->count())->toBe(0)
        ->and(AuditLog::query()->where('action', 'planning.request_created')->count())->toBe(1);

    expect(fn () => ask($this->worker, ['kind' => 'CLAIM', 'shift_id' => $open->id]))->toThrow(ValidationException::class, 'redan en förfrågan');
});

it('refuses requests for drafts, started shifts, other people\'s shifts and missing skills', function () {
    $draft = plannedShift(['date' => '2026-11-11', 'start' => '17:00', 'end' => '23:00']);
    expect(fn () => ask($this->worker, ['kind' => 'CLAIM', 'shift_id' => $draft->id]))->toThrow(ValidationException::class, 'publicerade schemat');

    $skill = app(SaveSkill::class)($this->owner, $this->company, 'Bartender');
    $needsSkill = plannedShift(['date' => '2026-11-12', 'start' => '17:00', 'end' => '23:00', 'skill_id' => $skill->id]);
    $bertils = plannedShift(['employee_id' => $this->bertil->id, 'date' => '2026-11-13', 'start' => '08:00', 'end' => '16:00']);
    publishNextWeek();

    expect(fn () => ask($this->worker, ['kind' => 'CLAIM', 'shift_id' => $needsSkill->id]))->toThrow(ValidationException::class, 'kompetens')
        ->and(fn () => ask($this->worker, ['kind' => 'DROP', 'shift_id' => $bertils->id]))->toThrow(ValidationException::class, 'egna pass')
        ->and(fn () => ask($this->worker, ['kind' => 'CLAIM', 'shift_id' => $bertils->id]))->toThrow(ValidationException::class, 'inte längre öppet');

    EmployeeSkill::create(['employee_id' => $this->anna->id, 'skill_id' => $skill->id]);
    expect(ask($this->worker, ['kind' => 'CLAIM', 'shift_id' => $needsSkill->id])->status)->toBe(ShiftRequest::PENDING);

    $this->travelTo(CarbonImmutable::parse('2026-11-13 09:00:00', 'Europe/Stockholm'));
    expect(fn () => ask($this->colleague, ['kind' => 'DROP', 'shift_id' => $bertils->id]))->toThrow(ValidationException::class, 'redan börjat');
});

it('follows the company\'s self-service switches', function () {
    $open = plannedShift(['date' => '2026-11-11', 'start' => '17:00', 'end' => '23:00']);
    $mine = plannedShift(['employee_id' => $this->anna->id, 'date' => '2026-11-12', 'start' => '08:00', 'end' => '16:00']);
    publishNextWeek();
    app(ModuleManager::class)->updateSettings(PlanningModule::KEY, $this->company, $this->owner, ['allow_open_shift_claims' => false, 'allow_swaps' => false]);

    expect(fn () => ask($this->worker, ['kind' => 'CLAIM', 'shift_id' => $open->id]))->toThrow(ValidationException::class, 'öppna pass')
        ->and(fn () => ask($this->worker, ['kind' => 'DROP', 'shift_id' => $mine->id]))->toThrow(ValidationException::class, 'byten');

    // With Min sida closed in payroll's settings, nothing of Planering is reachable for staff.
    app(ModuleManager::class)->updateSettings(PayrollModule::KEY, $this->company, $this->owner, ['employee_self_service' => false]);
    expect(fn () => app(IssueIcalToken::class)($this->worker, $this->company))->toThrow(AuthorizationException::class);
});

it('approves a claim: the shift is the employee\'s, published, re-checked and the employee told', function () {
    // Anna works until 23:00 on the 10th; the claim at 06:00 on the 11th leaves 7 h rest.
    plannedShift(['employee_id' => $this->anna->id, 'date' => '2026-11-10', 'start' => '15:00', 'end' => '23:00']);
    $open = plannedShift(['date' => '2026-11-11', 'start' => '06:00', 'end' => '12:00']);
    publishNextWeek();
    $request = ask($this->worker, ['kind' => 'CLAIM', 'shift_id' => $open->id]);
    $other = ask($this->colleague, ['kind' => 'CLAIM', 'shift_id' => $open->id]);

    $warnings = app(DecideRequest::class)($this->owner, $this->company, $request, true);

    $shift = $open->fresh();
    expect($shift->employee_id)->toBe($this->anna->id)
        ->and($shift->status)->toBe(Shift::PUBLISHED)
        ->and($shift->source)->toBe('CLAIM')
        ->and($shift->published_snapshot['employee_id'])->toBe($this->anna->id)
        ->and($request->fresh()->status)->toBe(ShiftRequest::APPROVED)
        ->and($request->fresh()->decided_by)->toBe($this->owner->id)
        ->and($other->fresh()->status)->toBe(ShiftRequest::REJECTED)
        ->and(collect($warnings)->pluck('message')->join(' '))->toContain('Dygnsvilan')
        ->and(Entry::query()->count())->toBe(0)
        ->and(AuditLog::query()->where('action', 'planning.request_approved')->sole()->payload['warnings'])->not->toBe([]);

    Notification::assertSentTo($this->worker, ShiftRequestNotice::class, fn (ShiftRequestNotice $notice): bool => $notice->kind === ShiftRequestNotice::APPROVED && str_contains($notice->sentence(), 'Du har fått passet'));
    Notification::assertSentTo($this->colleague, ShiftRequestNotice::class, fn (ShiftRequestNotice $notice): bool => $notice->kind === ShiftRequestNotice::REJECTED);
});

it('approves a swap: both shifts change hands and both people are told', function () {
    $annas = plannedShift(['employee_id' => $this->anna->id, 'date' => '2026-11-12', 'start' => '08:00', 'end' => '16:00']);
    $bertils = plannedShift(['employee_id' => $this->bertil->id, 'date' => '2026-11-13', 'start' => '08:00', 'end' => '16:00']);
    publishNextWeek();

    expect(fn () => ask($this->worker, ['kind' => 'SWAP', 'shift_id' => $annas->id]))->toThrow(ValidationException::class, 'Välj passet');

    $request = ask($this->worker, ['kind' => 'SWAP', 'shift_id' => $annas->id, 'counterpart_shift_id' => $bertils->id]);
    expect($request->counterpart_employee_id)->toBe($this->bertil->id)
        ->and($request->status)->toBe(ShiftRequest::AWAITING_COUNTERPART);

    // The colleague is asked first, and the planner cannot decide before they answer.
    Notification::assertSentTo($this->colleague, ShiftRequestNotice::class, fn (ShiftRequestNotice $notice, array $channels): bool => $notice->kind === ShiftRequestNotice::ASKED
        && $channels === ['database', 'mail']
        && str_contains($notice->sentence(), 'Anna Kock vill byta pass med dig'));
    expect(fn () => app(DecideRequest::class)($this->owner, $this->company, $request, true))->toThrow(ValidationException::class, 'inte svarat')
        ->and($annas->fresh()->employee_id)->toBe($this->anna->id);

    app(RequestShift::class)->answer($this->colleague, $this->company, $request, true);
    expect($request->fresh()->status)->toBe(ShiftRequest::PENDING)
        ->and($request->fresh()->counterpart_answered_by)->toBe($this->colleague->id)
        ->and($annas->fresh()->employee_id)->toBe($this->anna->id)
        ->and(AuditLog::query()->where('action', 'planning.request_counterpart_accepted')->exists())->toBeTrue();
    Notification::assertSentTo($this->worker, ShiftRequestNotice::class, fn (ShiftRequestNotice $notice): bool => $notice->kind === ShiftRequestNotice::ACCEPTED);

    app(DecideRequest::class)($this->owner, $this->company, $request, true);

    expect($annas->fresh()->employee_id)->toBe($this->bertil->id)
        ->and($bertils->fresh()->employee_id)->toBe($this->anna->id)
        ->and($annas->fresh()->status)->toBe(Shift::PUBLISHED)
        ->and($bertils->fresh()->source)->toBe('SWAP');

    Notification::assertSentTo($this->worker, ShiftRequestNotice::class, fn (ShiftRequestNotice $notice): bool => $notice->kind === ShiftRequestNotice::APPROVED);
    Notification::assertSentTo($this->colleague, ShiftRequestNotice::class, fn (ShiftRequestNotice $notice): bool => $notice->kind === ShiftRequestNotice::SWAPPED);
});

it('closes a swap the colleague declines and tells the employee who asked', function () {
    $annas = plannedShift(['employee_id' => $this->anna->id, 'date' => '2026-11-12', 'start' => '08:00', 'end' => '16:00']);
    $bertils = plannedShift(['employee_id' => $this->bertil->id, 'date' => '2026-11-13', 'start' => '08:00', 'end' => '16:00']);
    publishNextWeek();
    $request = ask($this->worker, ['kind' => 'SWAP', 'shift_id' => $annas->id, 'counterpart_shift_id' => $bertils->id]);

    app(RequestShift::class)->answer($this->colleague, $this->company, $request, false, 'Jag är bortrest.');

    expect($request->fresh()->status)->toBe(ShiftRequest::DECLINED)
        ->and($request->fresh()->decision_note)->toBe('Jag är bortrest.')
        ->and($annas->fresh()->employee_id)->toBe($this->anna->id)
        ->and($bertils->fresh()->employee_id)->toBe($this->bertil->id)
        ->and(AuditLog::query()->where('action', 'planning.request_counterpart_declined')->exists())->toBeTrue()
        ->and(fn () => app(RequestShift::class)->answer($this->colleague, $this->company, $request, true))->toThrow(ValidationException::class, 'redan besvarad')
        ->and(fn () => app(DecideRequest::class)($this->owner, $this->company, $request, true))->toThrow(ValidationException::class, 'redan besvarad');

    Notification::assertSentTo($this->worker, ShiftRequestNotice::class, fn (ShiftRequestNotice $notice): bool => $notice->kind === ShiftRequestNotice::DECLINED
        && $notice->reason === 'Jag är bortrest.'
        && str_contains($notice->sentence(), 'tackade nej'));

    // A new request for the same shift can be made once the old one is closed.
    expect(ask($this->worker, ['kind' => 'SWAP', 'shift_id' => $annas->id, 'counterpart_shift_id' => $bertils->id])->status)->toBe(ShiftRequest::AWAITING_COUNTERPART);
});

it('lets only the named colleague answer a swap', function () {
    $third = Bookkeeping::member($this->company, 'employee');
    $this->company->unsetRelation('users');
    Planning::employee($this->owner, $this->company, 'Cecilia', '880202345', ['user_id' => $third->id]);
    $annas = plannedShift(['employee_id' => $this->anna->id, 'date' => '2026-11-12', 'start' => '08:00', 'end' => '16:00']);
    $bertils = plannedShift(['employee_id' => $this->bertil->id, 'date' => '2026-11-13', 'start' => '08:00', 'end' => '16:00']);
    publishNextWeek();
    $request = ask($this->worker, ['kind' => 'SWAP', 'shift_id' => $annas->id, 'counterpart_shift_id' => $bertils->id]);

    expect(fn () => app(RequestShift::class)->answer($this->worker, $this->company, $request, true))->toThrow(ModelNotFoundException::class)
        ->and(fn () => app(RequestShift::class)->answer($third, $this->company, $request, true))->toThrow(ModelNotFoundException::class)
        ->and(fn () => app(RequestShift::class)->answer($this->owner, $this->company, $request, true))->toThrow(ValidationException::class, 'inte kopplat')
        ->and($request->fresh()->status)->toBe(ShiftRequest::AWAITING_COUNTERPART);

    // The colleague's shift changed hands meanwhile: accepting is refused.
    app(SaveShift::class)($this->owner, $this->company, ['employee_id' => $this->anna->id, 'date' => '2026-11-13', 'start' => '08:00', 'end' => '16:00'], $bertils->fresh());
    expect(fn () => app(RequestShift::class)->answer($this->colleague, $this->company, $request, true))->toThrow(ValidationException::class, 'har ändrats');

    // The employee who asked can still withdraw it while it waits for the colleague.
    app(RequestShift::class)->withdraw($this->worker, $this->company, $request);
    expect($request->fresh()->status)->toBe(ShiftRequest::WITHDRAWN);
});

it('shows the colleague the swap in Min sida and the planner that they accepted', function () {
    $annas = plannedShift(['employee_id' => $this->anna->id, 'date' => '2026-11-12', 'start' => '08:00', 'end' => '16:00']);
    $bertils = plannedShift(['employee_id' => $this->bertil->id, 'date' => '2026-11-13', 'start' => '08:00', 'end' => '16:00']);
    publishNextWeek();
    $request = ask($this->worker, ['kind' => 'SWAP', 'shift_id' => $annas->id, 'counterpart_shift_id' => $bertils->id, 'note' => 'Tandläkare']);

    $this->actingAs($this->owner);
    Livewire::test(RequestsPage::class)
        ->assertSeeHtml('data-awaiting')
        ->assertSee('Inga förfrågningar väntar');

    $this->actingAs($this->worker);
    Livewire::test(MySchedule::class)->assertDontSeeHtml('data-asked-of-me')->assertSee('Väntar på kollegan');

    $this->actingAs($this->colleague);
    Livewire::test(MySchedule::class)
        ->assertSeeHtml('data-asked-of-me')
        ->assertSee('Anna K vill byta pass med dig')
        ->assertSee('Tandläkare')
        ->call('acceptSwap', $request->id)
        ->assertDispatched('toast')
        ->assertDontSeeHtml('data-asked-of-me');

    $this->actingAs($this->owner);
    Livewire::test(RequestsPage::class)
        ->assertSeeHtml('data-counterpart-accepted')
        ->assertSee('Bertil Kock har sagt ja till bytet')
        ->assertDontSeeHtml('data-awaiting')
        ->call('approve', $request->id)
        ->assertDispatched('toast');

    expect($annas->fresh()->employee_id)->toBe($this->bertil->id)
        ->and($bertils->fresh()->employee_id)->toBe($this->anna->id);
});

it('approves a drop by making the shift open, and rejects with a reason', function () {
    $first = plannedShift(['employee_id' => $this->anna->id, 'date' => '2026-11-12', 'start' => '08:00', 'end' => '16:00']);
    $second = plannedShift(['employee_id' => $this->anna->id, 'date' => '2026-11-14', 'start' => '08:00', 'end' => '16:00']);
    publishNextWeek();
    $drop = ask($this->worker, ['kind' => 'DROP', 'shift_id' => $first->id]);
    $keep = ask($this->worker, ['kind' => 'DROP', 'shift_id' => $second->id]);

    app(DecideRequest::class)($this->owner, $this->company, $drop, true);
    app(DecideRequest::class)($this->owner, $this->company, $keep, false, 'Vi behöver dig då.');

    expect($first->fresh()->employee_id)->toBeNull()
        ->and($first->fresh()->status)->toBe(Shift::PUBLISHED)
        ->and($second->fresh()->employee_id)->toBe($this->anna->id)
        ->and($keep->fresh()->status)->toBe(ShiftRequest::REJECTED)
        ->and($keep->fresh()->decision_note)->toBe('Vi behöver dig då.');

    Notification::assertSentTo($this->worker, ShiftRequestNotice::class, fn (ShiftRequestNotice $notice): bool => $notice->kind === ShiftRequestNotice::REJECTED && $notice->reason === 'Vi behöver dig då.');
    expect(fn () => app(DecideRequest::class)($this->owner, $this->company, $keep, true))->toThrow(ValidationException::class, 'redan besvarad');
});

it('refuses to approve when the shift changed after the request', function () {
    $open = plannedShift(['date' => '2026-11-11', 'start' => '17:00', 'end' => '23:00']);
    publishNextWeek();
    $request = ask($this->worker, ['kind' => 'CLAIM', 'shift_id' => $open->id]);
    app(SaveShift::class)($this->owner, $this->company, ['employee_id' => $this->bertil->id, 'date' => '2026-11-11', 'start' => '17:00', 'end' => '23:00'], $open->fresh());

    expect(fn () => app(DecideRequest::class)($this->owner, $this->company, $request, true))->toThrow(ValidationException::class, 'har ändrats')
        ->and($request->fresh()->status)->toBe(ShiftRequest::PENDING);
});

it('lets the employee withdraw only their own waiting request', function () {
    $open = plannedShift(['date' => '2026-11-11', 'start' => '17:00', 'end' => '23:00']);
    publishNextWeek();
    $request = ask($this->worker, ['kind' => 'CLAIM', 'shift_id' => $open->id]);

    expect(fn () => app(RequestShift::class)->withdraw($this->colleague, $this->company, $request))->toThrow(ModelNotFoundException::class);

    app(RequestShift::class)->withdraw($this->worker, $this->company, $request);
    expect($request->fresh()->status)->toBe(ShiftRequest::WITHDRAWN)
        ->and(fn () => app(RequestShift::class)->withdraw($this->worker, $this->company, $request))->toThrow(ValidationException::class, 'redan besvarad');
});

it('lets planners decide and nobody else, and only linked members ask', function () {
    $bookkeeper = Bookkeeping::member($this->company, 'bookkeeper');
    $scheduler = Bookkeeping::member($this->company, 'scheduler');
    $this->company->unsetRelation('users');
    $open = plannedShift(['date' => '2026-11-11', 'start' => '17:00', 'end' => '23:00']);
    publishNextWeek();
    $request = ask($this->worker, ['kind' => 'CLAIM', 'shift_id' => $open->id]);

    expect(fn () => app(DecideRequest::class)($this->worker, $this->company, $request, true))->toThrow(AuthorizationException::class)
        ->and(fn () => app(DecideRequest::class)($bookkeeper, $this->company, $request, true))->toThrow(AuthorizationException::class)
        ->and(fn () => ask($bookkeeper, ['kind' => 'CLAIM', 'shift_id' => $open->id]))->toThrow(AuthorizationException::class)
        ->and(fn () => ask($this->owner, ['kind' => 'CLAIM', 'shift_id' => $open->id]))->toThrow(ValidationException::class, 'inte kopplat');

    app(DecideRequest::class)($scheduler, $this->company, $request, true);
    expect($open->fresh()->employee_id)->toBe($this->anna->id);
});

it('makes a calendar link that serves the published shifts without a login, and can be revoked', function () {
    plannedShift(['employee_id' => $this->anna->id, 'date' => '2026-11-10', 'start' => '08:00', 'end' => '16:00', 'note' => 'Inventering, ta med nycklar']);
    publishNextWeek();
    plannedShift(['employee_id' => $this->anna->id, 'date' => '2026-11-12', 'start' => '09:00', 'end' => '13:00']);

    $url = app(IssueIcalToken::class)($this->worker, $this->company);
    $token = basename($url, '.ics');
    $stored = IcalToken::query()->sole();

    expect($stored->token_hash)->toBe(hash('sha256', $token))
        ->and($stored->token_hash)->not->toContain($token)
        ->and(AuditLog::query()->where('action', 'planning.ical_issued')->exists())->toBeTrue();

    auth()->logout();
    $response = $this->get($url)->assertOk();
    expect($response->headers->get('Content-Type'))->toStartWith('text/calendar')
        ->and($response->getContent())->toContain('BEGIN:VCALENDAR')
        ->toContain('DTSTART:20261110T070000Z')
        ->toContain('Inventering\\, ta med nycklar')
        ->not->toContain('20261112T080000Z')
        ->not->toContain('900615')
        ->and($stored->fresh()->last_used_at)->not->toBeNull();

    $newUrl = app(IssueIcalToken::class)($this->worker, $this->company);
    $this->get($url)->assertNotFound();
    $this->get($newUrl)->assertOk();

    expect(app(RevokeIcalToken::class)($this->worker, $this->company))->toBeTrue();
    $this->get($newUrl)->assertNotFound();
    $this->get('/planering/kalender/'.str_repeat('a', 48).'.ics')->assertNotFound();
});

it('stops the calendar feed while Planering is off', function () {
    $url = app(IssueIcalToken::class)($this->worker, $this->company);
    app(ModuleManager::class)->disable(PlanningModule::KEY, $this->company, $this->owner, confirmed: true);

    auth()->logout();
    $this->get($url)->assertNotFound();
});

it('runs the employee flow in Min sida: claim, availability and the calendar link', function () {
    $open = plannedShift(['date' => '2026-11-11', 'start' => '17:00', 'end' => '23:00']);
    publishNextWeek();
    $this->actingAs($this->worker);

    Livewire::test(MySchedule::class)
        ->assertSee('Öppna pass')
        ->call('claim', $open->id)
        ->set('request.note', 'Gärna')
        ->call('sendRequest')
        ->assertDispatched('toast')
        ->assertSee('Förfrågan skickad')
        ->assertSee('Väntar på svar')
        ->call('newAvailability')
        ->set('availability.kind', 'UNAVAILABLE')
        ->set('availability.weekday', 5)
        ->call('saveAvailability')
        ->assertHasNoErrors()
        ->assertSee('Varje fredag')
        ->call('issueIcal')
        ->assertSeeHtml('data-ical-url')
        ->call('revokeIcal')
        ->assertDontSeeHtml('data-ical-url');

    expect(ShiftRequest::query()->sole()->note)->toBe('Gärna')
        ->and(Availability::query()->sole()->weekday)->toBe(5);
});

it('lists waiting requests with the checks for the planner, who approves them on the page', function () {
    plannedShift(['employee_id' => $this->anna->id, 'date' => '2026-11-10', 'start' => '15:00', 'end' => '23:00']);
    $open = plannedShift(['date' => '2026-11-11', 'start' => '06:00', 'end' => '12:00']);
    publishNextWeek();
    $request = ask($this->worker, ['kind' => 'CLAIM', 'shift_id' => $open->id]);
    app(SaveAvailability::class)($this->worker, $this->company, ['kind' => 'UNAVAILABLE', 'repeat' => 'date', 'date' => '2026-11-11', 'whole_day' => true]);

    $todos = app(PlanningTodos::class)->todos($this->owner, $this->company, CarbonImmutable::today('Europe/Stockholm'));
    expect(collect($todos)->pluck('key')->all())->toContain('planning-requests');

    $this->actingAs($this->owner);
    $this->get(route('planning.requests'))->assertOk()->assertSee('Öppna pass och byten');

    Livewire::test(RequestsPage::class)
        ->assertSee('Anna')
        ->assertSee('Dygnsvilan')
        ->assertSee('inte passar')
        ->call('approve', $request->id)
        ->assertDispatched('toast')
        ->assertSee('Inga förfrågningar väntar');

    expect($open->fresh()->employee_id)->toBe($this->anna->id);

    $this->actingAs($this->worker);
    Livewire::test(RequestsPage::class)->assertForbidden();
});

it('marks availability in the schedule grid for the planner', function () {
    $save = app(SaveAvailability::class);
    $save($this->worker, $this->company, ['kind' => 'UNAVAILABLE', 'repeat' => 'weekly', 'weekday' => 3, 'whole_day' => true]);
    $save($this->colleague, $this->company, ['kind' => 'PREFERRED', 'repeat' => 'date', 'date' => '2026-11-12', 'whole_day' => false, 'from' => '10:00', 'to' => '14:00']);

    $this->actingAs($this->owner);
    Livewire::withQueryParams(['vecka' => '2026-11-09'])->test(SchedulePage::class)
        ->assertSeeHtml('data-availability="UNAVAILABLE"')
        ->assertSeeHtml('data-availability="PREFERRED"')
        ->assertSee('Jobbar gärna 10:00–14:00');
});

it('links the schedule and request emails to Min sida', function () {
    $schedule = new ScheduleNoticeMail(ScheduleNotice::PUBLISHED, 'Testbolaget', '9 nov – 15 nov 2026', []);
    $answer = new ShiftRequestNoticeMail('Du har fått passet.', 'Testbolaget', [], 'Välkommen');

    expect($schedule->render())->toContain(route('payroll.portal.home'))
        ->and($answer->render())->toContain(route('payroll.portal.home'))->toContain('Välkommen');
});

it('loads Min sida in the same number of queries for 1 shift and for 12', function () {
    $count = function (): int {
        DB::flushQueryLog();
        DB::enableQueryLog();
        Livewire::test(MySchedule::class)->call('nextWeek');
        $queries = count(DB::getQueryLog());
        DB::disableQueryLog();

        return $queries;
    };

    plannedShift(['employee_id' => $this->anna->id, 'date' => '2026-11-10', 'start' => '08:00', 'end' => '16:00']);
    plannedShift(['date' => '2026-11-11', 'start' => '08:00', 'end' => '16:00']);
    publishNextWeek();
    ask($this->worker, ['kind' => 'CLAIM', 'shift_id' => Shift::query()->whereNull('employee_id')->value('id')]);
    app(SaveAvailability::class)($this->worker, $this->company, ['kind' => 'UNAVAILABLE', 'repeat' => 'weekly', 'weekday' => 1, 'whole_day' => true]);
    $this->actingAs($this->worker);
    $count();
    $few = $count();

    foreach (['2026-11-12', '2026-11-13', '2026-11-14', '2026-11-15'] as $date) {
        plannedShift(['employee_id' => $this->anna->id, 'date' => $date, 'start' => '08:00', 'end' => '12:00']);
        plannedShift(['employee_id' => $this->anna->id, 'date' => $date, 'start' => '17:00', 'end' => '20:00']);
        plannedShift(['date' => $date, 'start' => '12:00', 'end' => '16:00']);
    }
    publishNextWeek();
    ask($this->worker, ['kind' => 'CLAIM', 'shift_id' => Shift::query()->whereNull('employee_id')->latest('starts_at')->value('id')]);
    app(SaveAvailability::class)($this->worker, $this->company, ['kind' => 'UNAVAILABLE', 'repeat' => 'weekly', 'weekday' => 2, 'whole_day' => true]);

    expect($count())->toBe($few);
});
