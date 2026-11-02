<?php

declare(strict_types=1);

use App\Models\AuditLog;
use App\Modules\Planning\Actions\ApplyTemplate;
use App\Modules\Planning\Actions\CopyPeriod;
use App\Modules\Planning\Actions\DeleteShift;
use App\Modules\Planning\Actions\PublishPeriod;
use App\Modules\Planning\Actions\SaveArea;
use App\Modules\Planning\Actions\SaveShift;
use App\Modules\Planning\Actions\SaveSkill;
use App\Modules\Planning\Actions\SaveTemplate;
use App\Modules\Planning\Models\EmployeeSkill;
use App\Modules\Planning\Models\Publication;
use App\Modules\Planning\Models\Shift;
use App\Modules\Planning\Models\Template;
use App\Modules\Planning\Notifications\ScheduleNotice;
use App\Modules\StaffLedger\Models\Entry;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Support\Facades\Notification;
use Illuminate\Validation\ValidationException;
use Tests\Support\Bookkeeping;
use Tests\Support\Planning;

/*
 * Planering's actions (PLANNING_MODULE.md §6, steps 1–2): every one checks
 * the module and the permission, is audited, and never writes to the staff
 * ledger.
 */

function monday(): CarbonImmutable
{
    return CarbonImmutable::parse('2026-11-09', 'Europe/Stockholm');
}

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-11-04 10:00:00', 'Europe/Stockholm'));
    [$this->owner, $this->company] = Bookkeeping::company();
    Planning::setUp($this->company, $this->owner);
    $this->actingAs($this->owner);
    $this->anna = Planning::employee($this->owner, $this->company, 'Anna', '900615123', ['salary_form' => 'HOURLY', 'salary' => '150']);
    $this->area = app(SaveArea::class)($this->owner, $this->company, ['name' => 'Kök', 'colour' => '#E4572E']);
});

it('creates a shift with wall-clock times, ending the next day when the end is earlier', function () {
    $shift = Planning::shift($this->owner, $this->company, ['employee_id' => $this->anna->id, 'area_id' => $this->area->id, 'date' => '2026-11-13', 'start' => '18:00', 'end' => '02:00', 'break_minutes' => 30]);

    expect($shift->starts_at->toIso8601String())->toBe('2026-11-13T18:00:00+01:00')
        ->and($shift->ends_at->toIso8601String())->toBe('2026-11-14T02:00:00+01:00')
        ->and($shift->workedMinutes())->toBe(450)
        ->and($shift->status)->toBe(Shift::DRAFT)
        ->and($shift->cost_estimate_ore)->toBeInt()
        ->and($this->area->fresh()->colour)->toBe('#e4572e')
        ->and(AuditLog::query()->where('action', 'planning.shift_created')->count())->toBe(1);

    $stored = Shift::query()->findOrFail($shift->id);
    expect($stored->starts_at->equalTo($shift->starts_at))->toBeTrue();
});

it('stores the right instant on the night summer time ends', function () {
    $shift = Planning::shift($this->owner, $this->company, ['employee_id' => $this->anna->id, 'starts_at' => '2026-10-24 22:00', 'ends_at' => '2026-10-25 06:00']);

    expect(Shift::query()->findOrFail($shift->id)->grossMinutes())->toBe(540);
});

it('validates the shift', function (array $input, string $field) {
    try {
        Planning::shift($this->owner, $this->company, ['date' => '2026-11-13', 'start' => '08:00', 'end' => '16:00', ...$input]);
    } catch (ValidationException $exception) {
        expect($exception->errors())->toHaveKey($field);

        return;
    }

    $this->fail('No validation error.');
})->with([
    'bad time' => [['start' => '25:00'], 'start'],
    'break longer than the shift' => [['break_minutes' => 480], 'break_minutes'],
    'longer than 24 hours' => [['date' => null, 'start' => null, 'end' => null, 'starts_at' => '2026-11-13 08:00', 'ends_at' => '2026-11-14 09:00'], 'end'],
    'end before start' => [['date' => null, 'start' => null, 'end' => null, 'starts_at' => '2026-11-13 08:00', 'ends_at' => '2026-11-13 07:00'], 'end'],
    'another company\'s employee' => [['employee_id' => 999999], 'employee_id'],
]);

it('refuses someone who cannot plan', function () {
    $accountant = Bookkeeping::member($this->company, 'accountant');

    expect(fn () => app(SaveShift::class)($accountant, $this->company, ['date' => '2026-11-13', 'start' => '08:00', 'end' => '16:00']))->toThrow(AuthorizationException::class);
});

it('publishes a period, tells each employee their shifts, and marks later changes', function () {
    Notification::fake();
    $bertil = Planning::employee($this->owner, $this->company, 'Bertil', '850301234', ['email' => 'bertil@example.test']);
    $first = Planning::shift($this->owner, $this->company, ['employee_id' => $this->anna->id, 'date' => '2026-11-10', 'start' => '08:00', 'end' => '16:00', 'break_minutes' => 30]);
    Planning::shift($this->owner, $this->company, ['employee_id' => $bertil->id, 'date' => '2026-11-11', 'start' => '10:00', 'end' => '14:00']);
    Planning::shift($this->owner, $this->company, ['date' => '2026-11-12', 'start' => '10:00', 'end' => '14:00']);
    Planning::shift($this->owner, $this->company, ['employee_id' => $this->anna->id, 'date' => '2026-11-20', 'start' => '10:00', 'end' => '14:00']);

    expect(PublishPeriod::summary(monday(), monday()->addDays(6)))->toMatchArray(['new' => 3, 'changed' => 0, 'open' => 1, 'unavailable' => 0, 'notify' => ['Bertil Kock'], 'unreachable' => ['Anna Kock']]);

    $publication = app(PublishPeriod::class)($this->owner, $this->company, monday(), monday()->addDays(6));

    expect($publication->shift_count)->toBe(3)
        ->and($publication->notified_count)->toBe(1)
        ->and(Shift::query()->where('status', Shift::PUBLISHED)->count())->toBe(3)
        ->and(Shift::query()->where('status', Shift::DRAFT)->count())->toBe(1)
        ->and(AuditLog::query()->where('action', 'planning.period_published')->count())->toBe(1);

    Notification::assertSentTo(new AnonymousNotifiable, ScheduleNotice::class, fn (ScheduleNotice $notice, array $channels, AnonymousNotifiable $to): bool => $to->routes['mail'] === 'bertil@example.test'
        && $notice->kind === ScheduleNotice::PUBLISHED
        && count($notice->shifts) === 1
        && $notice->shifts[0]['time'] === '10:00–14:00');

    $moved = app(SaveShift::class)($this->owner, $this->company, ['employee_id' => $this->anna->id, 'date' => '2026-11-10', 'start' => '09:00', 'end' => '16:00', 'break_minutes' => 30], $first->fresh());

    expect($moved->status)->toBe(Shift::DRAFT)
        ->and($moved->hasUnpublishedChanges())->toBeTrue()
        ->and($moved->published_snapshot['starts_at'])->toBe('2026-11-10T08:00:00+01:00')
        ->and(PublishPeriod::summary(monday(), monday()->addDays(6)))->toMatchArray(['new' => 0, 'changed' => 1]);

    expect(fn () => app(PublishPeriod::class)($this->owner, $this->company, monday()->addWeeks(4), monday()->addWeeks(4)->addDays(6)))->toThrow(ValidationException::class);
});

it('notifies a user in the app and by email', function () {
    Notification::fake();
    $user = Bookkeeping::member($this->company, 'employee');
    $this->anna->forceFill(['user_id' => $user->id])->save();
    Planning::shift($this->owner, $this->company, ['employee_id' => $this->anna->id, 'date' => '2026-11-10', 'start' => '08:00', 'end' => '12:00']);

    app(PublishPeriod::class)($this->owner, $this->company, monday(), monday()->addDays(6));

    Notification::assertSentTo($user, ScheduleNotice::class, fn (ScheduleNotice $notice, array $channels): bool => $channels === ['database', 'mail']
        && str_contains($notice->toArray($user)['title'], 'Du har 1 pass.'));
});

it('deletes a draft but cancels a published shift and tells the employee', function () {
    Notification::fake();
    $this->anna->forceFill(['email' => 'anna@example.test'])->save();
    $draft = Planning::shift($this->owner, $this->company, ['employee_id' => $this->anna->id, 'date' => '2026-11-10', 'start' => '08:00', 'end' => '12:00']);

    expect(app(DeleteShift::class)($this->owner, $this->company, $draft))->toBeFalse()
        ->and(Shift::query()->find($draft->id))->toBeNull();
    Notification::assertNothingSent();

    $shift = Planning::shift($this->owner, $this->company, ['employee_id' => $this->anna->id, 'date' => '2026-11-10', 'start' => '08:00', 'end' => '12:00']);
    app(PublishPeriod::class)($this->owner, $this->company, monday(), monday()->addDays(6));

    expect(app(DeleteShift::class)($this->owner, $this->company, $shift->fresh()))->toBeTrue()
        ->and($shift->fresh()->status)->toBe(Shift::CANCELLED)
        ->and(AuditLog::query()->where('action', 'planning.shift_cancelled')->count())->toBe(1);

    Notification::assertSentTo(new AnonymousNotifiable, ScheduleNotice::class, fn (ScheduleNotice $notice): bool => $notice->kind === ScheduleNotice::CANCELLED);
    expect(fn () => app(SaveShift::class)($this->owner, $this->company, ['date' => '2026-11-10', 'start' => '08:00', 'end' => '12:00'], $shift->fresh()))->toThrow(ValidationException::class);
});

it('copies a week with or without people, without duplicates or overlapping itself', function () {
    Planning::shift($this->owner, $this->company, ['employee_id' => $this->anna->id, 'area_id' => $this->area->id, 'date' => '2026-11-10', 'start' => '08:00', 'end' => '16:00']);
    Planning::shift($this->owner, $this->company, ['date' => '2026-11-15', 'start' => '22:00', 'end' => '02:00']);

    expect(app(CopyPeriod::class)($this->owner, $this->company, monday(), 1, monday()->addWeek(), 2))->toBe(4)
        ->and(app(CopyPeriod::class)($this->owner, $this->company, monday(), 1, monday()->addWeek(), 1))->toBe(0)
        ->and(app(CopyPeriod::class)($this->owner, $this->company, monday(), 1, monday()->addWeeks(4), 1, withPeople: false))->toBe(2);

    $copied = Shift::query()->where('source', 'COPY')->orderBy('starts_at')->get();
    expect($copied->first()->starts_at->toIso8601String())->toBe('2026-11-17T08:00:00+01:00')
        ->and($copied->first()->employee_id)->toBe($this->anna->id)
        ->and($copied->last()->employee_id)->toBeNull()
        ->and($copied->last()->ends_at->toIso8601String())->toBe('2026-12-14T02:00:00+01:00');

    expect(fn () => app(CopyPeriod::class)($this->owner, $this->company, monday(), 1, monday(), 1))->toThrow(ValidationException::class);
});

it('copies across the summer time change keeping the clock times', function () {
    Planning::shift($this->owner, $this->company, ['employee_id' => $this->anna->id, 'date' => '2026-03-23', 'start' => '09:00', 'end' => '17:00']);

    app(CopyPeriod::class)($this->owner, $this->company, CarbonImmutable::parse('2026-03-23', 'Europe/Stockholm'), 1, CarbonImmutable::parse('2026-03-30', 'Europe/Stockholm'));

    expect(Shift::query()->where('source', 'COPY')->sole()->starts_at->toIso8601String())->toBe('2026-03-30T09:00:00+02:00');
});

it('saves a week as a template and lays it out again', function () {
    Planning::shift($this->owner, $this->company, ['employee_id' => $this->anna->id, 'area_id' => $this->area->id, 'date' => '2026-11-14', 'start' => '17:00', 'end' => '01:00', 'break_minutes' => 30]);

    $template = app(SaveTemplate::class)->fromWeeks($this->owner, $this->company, 'Vanlig vecka', monday(), 1);
    $row = $template->shifts()->sole();

    expect($row->only(['week_index', 'weekday', 'start', 'end', 'break_minutes']))->toBe(['week_index' => 0, 'weekday' => 6, 'start' => '17:00', 'end' => '01:00', 'break_minutes' => 30]);

    $created = app(ApplyTemplate::class)($this->owner, $this->company, Template::query()->with('shifts')->findOrFail($template->id), monday()->addWeek(), 3, withPeople: false);
    $shifts = Shift::query()->where('source', 'TEMPLATE')->orderBy('starts_at')->get();

    expect($created)->toBe(3)
        ->and($shifts->first()->starts_at->toIso8601String())->toBe('2026-11-21T17:00:00+01:00')
        ->and($shifts->first()->ends_at->toIso8601String())->toBe('2026-11-22T01:00:00+01:00')
        ->and($shifts->first()->template_id)->toBe($template->id)
        ->and($shifts->pluck('employee_id')->filter())->toBeEmpty();

    app(SaveTemplate::class)->saveShift($this->owner, $this->company, $template, ['week_index' => 0, 'weekday' => 1, 'start' => '08:00', 'end' => '12:00']);
    expect(fn () => app(SaveTemplate::class)->saveShift($this->owner, $this->company, $template, ['week_index' => 1, 'weekday' => 1, 'start' => '08:00', 'end' => '12:00']))->toThrow(ValidationException::class);

    app(SaveTemplate::class)->delete($this->owner, $this->company, $template);
    expect(Shift::query()->where('source', 'TEMPLATE')->count())->toBe(3);
});

it('keeps skills unique per company and assigns them to employees', function () {
    $skill = app(SaveSkill::class)($this->owner, $this->company, 'Kassa');

    expect(fn () => app(SaveSkill::class)($this->owner, $this->company, 'Kassa'))->toThrow(ValidationException::class);

    app(SaveSkill::class)->assign($this->owner, $this->company, $this->anna, [$skill->id, 999999]);
    expect(EmployeeSkill::query()->where('employee_id', $this->anna->id)->pluck('skill_id')->all())->toBe([$skill->id]);

    app(SaveArea::class)->archive($this->owner, $this->company, $this->area);
    expect($this->area->fresh()->archived_at)->not->toBeNull();

    app(SaveSkill::class)->delete($this->owner, $this->company, $skill);
    expect(EmployeeSkill::query()->count())->toBe(0);
});

it('never writes to the staff ledger', function () {
    $entries = Entry::query()->count();
    Planning::shift($this->owner, $this->company, ['employee_id' => $this->anna->id, 'date' => '2026-11-10', 'start' => '08:00', 'end' => '12:00']);
    app(PublishPeriod::class)($this->owner, $this->company, monday(), monday()->addDays(6));

    expect(Entry::query()->count())->toBe($entries)
        ->and(Publication::query()->count())->toBe(1);
});
