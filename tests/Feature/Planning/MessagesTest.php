<?php

declare(strict_types=1);

use App\Models\AuditLog;
use App\Modules\ModuleManager;
use App\Modules\Payroll\Models\Employee;
use App\Modules\Planning\Actions\DeleteShift;
use App\Modules\Planning\Actions\MarkMessage;
use App\Modules\Planning\Actions\PublishPeriod;
use App\Modules\Planning\Actions\SaveArea;
use App\Modules\Planning\Actions\SaveShift;
use App\Modules\Planning\Actions\SendMessage;
use App\Modules\Planning\Livewire\MessagesPage;
use App\Modules\Planning\Livewire\MySchedule;
use App\Modules\Planning\Mail\ScheduleMessageMail;
use App\Modules\Planning\Models\Message;
use App\Modules\Planning\Models\MessageRecipient;
use App\Modules\Planning\Models\Shift;
use App\Modules\Planning\Notifications\ScheduleMessageNotice;
use App\Modules\Planning\PlanningModule;
use App\Support\Privacy\DataSubjectExport;
use App\Support\Tenancy\CurrentCompany;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\Support\Bookkeeping;
use Tests\Support\Planning;

/*
 * Planering: messages to staff tied to the schedule (ADR-037 gap 5). The
 * recipients are frozen when a message is sent; the text is encrypted at
 * rest, never audited and never e-mailed; each recipient reads and confirms
 * on Min sida and only the planner sees who has.
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
    $this->cecilia = Planning::employee($this->owner, $this->company, 'Cecilia', '920202123');
    $this->kitchen = app(SaveArea::class)($this->owner, $this->company, ['name' => 'Kök', 'colour' => '#E4572E']);
    $this->hall = app(SaveArea::class)($this->owner, $this->company, ['name' => 'Servering', 'colour' => '#2E86AB']);
    $this->monday = CarbonImmutable::parse('2026-11-09', 'Europe/Stockholm');
});

/** @param  array<string, mixed>  $input */
function p4Shift(array $input = []): Shift
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

function p4Publish(): void
{
    $test = test();
    CurrentCompany::run($test->company, fn () => app(PublishPeriod::class)($test->owner, $test->company, $test->monday, $test->monday->addDays(6)));
}

/**
 * @param  array<string, mixed>  $input
 * @return array{message: Message, recipients: int, unreachable: int}
 */
function p4Send(array $input = [], mixed $user = null): array
{
    $test = test();

    return CurrentCompany::run($test->company, fn (): array => app(SendMessage::class)($user ?? $test->scheduler, $test->company, [
        'scope' => Message::PERIOD,
        'date_from' => '2026-11-09',
        'date_to' => '2026-11-15',
        'body' => 'Leveransen kommer en timme tidigare på lördag.',
        ...$input,
    ]));
}

/** @return list<int> */
function p4RecipientIds(Message $message): array
{
    return MessageRecipient::query()->where('message_id', $message->id)->orderBy('employee_id')->pluck('employee_id')->map(fn ($id): int => (int) $id)->all();
}

it('sends a message to everyone with a published shift in the period, and counts those without a login', function () {
    p4Shift();
    p4Shift(['employee_id' => $this->bertil->id, 'date' => '2026-11-12', 'area_id' => $this->hall->id]);
    p4Shift(['employee_id' => $this->cecilia->id, 'date' => '2026-11-13']);
    p4Publish();
    p4Shift(['employee_id' => $this->bertil->id, 'date' => '2026-11-20']);

    Notification::fake();
    $result = p4Send();

    expect($result['recipients'])->toBe(3)
        ->and($result['unreachable'])->toBe(1)
        ->and(p4RecipientIds($result['message']))->toBe([$this->anna->id, $this->bertil->id, $this->cecilia->id])
        ->and(MessageRecipient::query()->where('employee_id', $this->cecilia->id)->value('notified_at'))->toBeNull()
        ->and(MessageRecipient::query()->where('employee_id', $this->anna->id)->value('notified_at'))->not->toBeNull();

    Notification::assertSentTo($this->worker, ScheduleMessageNotice::class);
    Notification::assertSentTo($this->colleague, ScheduleMessageNotice::class);
    Notification::assertCount(2);
});

it('addresses one shift, one day, one area or everyone employed', function () {
    $annas = p4Shift();
    p4Shift(['employee_id' => $this->bertil->id, 'date' => '2026-11-10', 'area_id' => $this->hall->id]);
    p4Shift(['employee_id' => $this->cecilia->id, 'date' => '2026-11-11']);
    p4Publish();

    expect(p4RecipientIds(p4Send(['scope' => Message::SHIFT, 'shift_id' => $annas->id, 'date_from' => null, 'date_to' => null])['message']))->toBe([$this->anna->id])
        ->and(p4RecipientIds(p4Send(['scope' => Message::DAY, 'date_from' => '2026-11-10', 'date_to' => null])['message']))->toBe([$this->anna->id, $this->bertil->id])
        ->and(p4RecipientIds(p4Send(['scope' => Message::AREA, 'area_id' => $this->kitchen->id])['message']))->toBe([$this->anna->id, $this->cecilia->id])
        ->and(p4RecipientIds(p4Send(['scope' => Message::ALL, 'date_from' => '2026-12-01', 'date_to' => '2026-12-07'])['message']))->toBe([$this->anna->id, $this->bertil->id, $this->cecilia->id]);

    $shiftMessage = Message::query()->where('scope', Message::SHIFT)->firstOrFail();
    expect($shiftMessage->date_from->toDateString())->toBe('2026-11-10')
        ->and($shiftMessage->date_to->toDateString())->toBe('2026-11-10');
});

it('only counts what the published schedule shows, never drafts, cancelled shifts or unpublished changes', function () {
    $moved = p4Shift();
    $cancelled = p4Shift(['employee_id' => $this->cecilia->id, 'date' => '2026-11-11']);
    p4Publish();
    p4Shift(['employee_id' => $this->bertil->id, 'date' => '2026-11-12']);
    app(DeleteShift::class)($this->owner, $this->company, $cancelled->fresh());
    // An unpublished move to Bertil: Anna is still the one who was shown the shift.
    app(SaveShift::class)($this->owner, $this->company, ['employee_id' => $this->bertil->id, 'area_id' => $this->kitchen->id, 'date' => '2026-11-10', 'start' => '08:00', 'end' => '16:00'], $moved->fresh());

    expect(p4RecipientIds(p4Send()['message']))->toBe([$this->anna->id]);
});

it('freezes the recipients when the message is sent', function () {
    p4Shift();
    p4Publish();
    $message = p4Send()['message'];

    p4Shift(['employee_id' => $this->bertil->id, 'date' => '2026-11-12']);
    p4Publish();

    expect(p4RecipientIds($message->fresh()))->toBe([$this->anna->id]);
});

it('refuses an empty or too long text, a period over 31 days, an unpublished shift and a scope nobody is in', function () {
    $draft = p4Shift();

    expect(fn () => p4Send(['body' => '   ']))->toThrow(ValidationException::class)
        ->and(fn () => p4Send(['body' => str_repeat('a', 2001)]))->toThrow(ValidationException::class)
        ->and(fn () => p4Send(['date_to' => '2026-12-10']))->toThrow(ValidationException::class, '31 dagar')
        ->and(fn () => p4Send(['scope' => Message::SHIFT, 'shift_id' => $draft->id]))->toThrow(ValidationException::class, 'inte publicerat')
        ->and(fn () => p4Send())->toThrow(ValidationException::class, 'Publicera schemat först')
        ->and(fn () => p4Send(['scope' => Message::AREA, 'area_id' => null]))->toThrow(ValidationException::class);

    expect(Message::query()->count())->toBe(0);
});

it('keeps the text encrypted at rest, out of the audit log, the notice and the e-mail', function () {
    p4Shift();
    p4Publish();
    Notification::fake();
    $message = p4Send(['body' => 'Hemlig text om lördagen', 'requires_ack' => true])['message'];

    $raw = DB::table('planning_messages')->where('id', $message->id)->value('body');
    $audit = AuditLog::query()->where('action', 'planning.message_sent')->firstOrFail();

    expect($raw)->not->toContain('Hemlig')
        ->and($message->fresh()->body)->toBe('Hemlig text om lördagen')
        ->and(json_encode($audit->payload))->not->toContain('Hemlig')
        ->and($audit->payload)->toMatchArray(['scope' => Message::PERIOD, 'recipients' => 1, 'unreachable' => 0, 'requires_ack' => true]);

    Notification::assertSentTo($this->worker, ScheduleMessageNotice::class, function (ScheduleMessageNotice $notice) {
        $html = (string) $notice->toMail($this->worker)->render();

        return ! str_contains(json_encode($notice->toArray($this->worker)), 'Hemlig')
            && ! str_contains($html, 'Hemlig')
            && str_contains($html, 'Min sida')
            && $notice->toMail($this->worker) instanceof ScheduleMessageMail;
    });
});

it('keeps sending and the page from employees and while Planering is off', function () {
    p4Shift();
    p4Publish();

    expect(fn () => p4Send([], $this->worker))->toThrow(AuthorizationException::class);

    $this->actingAs($this->worker);
    Livewire::test(MessagesPage::class)->assertForbidden();

    app(ModuleManager::class)->disable(PlanningModule::KEY, $this->company, $this->owner, confirmed: true);
    expect(fn () => p4Send([], $this->owner))->toThrow(Exception::class);
});

it('lets a planner write, see the receipts per recipient and withdraw a message on the page, with toasts', function () {
    p4Shift();
    p4Shift(['employee_id' => $this->cecilia->id, 'date' => '2026-11-11']);
    p4Publish();
    $this->actingAs($this->scheduler);

    $page = Livewire::test(MessagesPage::class)
        ->assertSee('Skriv inte om hälsa, sjukdom eller andra känsliga uppgifter här.')
        ->set('form.date_from', '2026-11-09')
        ->set('form.date_to', '2026-11-15')
        ->assertSee('Meddelandet når 2 personer.')
        ->set('form.body', 'Personalmöte på torsdag kl. 15.')
        ->set('form.requires_ack', true)
        ->call('send')
        ->assertDispatched('toast', message: 'Meddelandet är skickat till 2 personer. 1 nås inte eftersom hen inte har någon inloggning.')
        ->assertSee('Personalmöte på torsdag kl. 15.')
        ->assertSee('Läst av 0 av 2')
        ->assertSee('Nås inte (ingen inloggning)')
        ->assertSee('Anna');

    $message = Message::query()->firstOrFail();
    $page->call('show', $message->id)->assertDontSeeHtml('data-recipients');
    $page->set('form.body', '')->call('send')->assertHasErrors(['form.body']);
    $page->call('delete', $message->id)->assertDispatched('toast');

    expect(Message::query()->count())->toBe(0)
        ->and(MessageRecipient::query()->count())->toBe(0)
        ->and(AuditLog::query()->where('action', 'planning.message_deleted')->count())->toBe(1);
});

it('shows an employee their own messages on Min sida, read on opening and confirmed with a click', function () {
    p4Shift();
    p4Shift(['employee_id' => $this->bertil->id, 'date' => '2026-11-12']);
    p4Publish();
    $message = p4Send(['requires_ack' => true, 'body' => 'Ta med förklädet.'])['message'];
    p4Send(['scope' => Message::SHIFT, 'shift_id' => Shift::query()->where('employee_id', $this->bertil->id)->value('id'), 'body' => 'Bara till Bertil.']);
    $receipt = MessageRecipient::query()->where('message_id', $message->id)->where('employee_id', $this->anna->id)->firstOrFail();
    $this->travelTo(CarbonImmutable::parse('2026-11-05 09:00:00', 'Europe/Stockholm'));

    $this->actingAs($this->worker);
    $page = Livewire::test(MySchedule::class)
        ->assertSeeHtml('data-my-messages')
        ->assertSee('Läs meddelandet')
        ->assertDontSee('Ta med förklädet.')
        ->assertDontSee('Bara till Bertil.')
        ->assertDontSee('Bertil');

    $page->call('readMessage', $receipt->id)->assertSee('Ta med förklädet.')->assertSee('Jag har läst');
    expect($receipt->fresh()->read_at?->format('Y-m-d H:i'))->toBe('2026-11-05 09:00')
        ->and($receipt->fresh()->acknowledged_at)->toBeNull();

    $this->travelTo(CarbonImmutable::parse('2026-11-05 09:10:00', 'Europe/Stockholm'));
    $page->call('acknowledgeMessage', $receipt->id)->assertDispatched('toast')->assertSee('Du bekräftade 2026-11-05 09:10');
    expect($receipt->fresh()->acknowledged_at?->format('H:i'))->toBe('09:10')
        ->and($receipt->fresh()->read_at?->format('H:i'))->toBe('09:00');
});

it('answers 404 for someone else\'s receipt and for another company\'s message or receipt', function () {
    p4Shift(['employee_id' => $this->bertil->id]);
    p4Publish();
    $message = p4Send()['message'];
    $bertils = MessageRecipient::query()->where('message_id', $message->id)->firstOrFail();

    $this->actingAs($this->worker);
    Livewire::test(MySchedule::class)->call('readMessage', $bertils->id)->assertNotFound();
    Livewire::test(MySchedule::class)->call('acknowledgeMessage', $bertils->id)->assertNotFound();
    expect($bertils->fresh()->read_at)->toBeNull()
        ->and(fn () => app(MarkMessage::class)->read($this->worker, $this->company, $bertils))->toThrow(AuthorizationException::class);

    [, $otherCompany] = Bookkeeping::company();
    [$foreign, $foreignReceipt] = CurrentCompany::run($otherCompany, function (): array {
        $employee = Employee::query()->create(['employee_number' => 1, 'first_name' => 'Dora', 'last_name' => 'Hemlig', 'personal_number' => '198001010000', 'birth_date' => '1980-01-01']);
        $foreign = Message::query()->create(['scope' => Message::ALL, 'date_from' => '2026-11-09', 'date_to' => '2026-11-15', 'body' => 'Hemligt']);

        return [$foreign, MessageRecipient::query()->create(['message_id' => $foreign->id, 'employee_id' => $employee->id])];
    });
    CurrentCompany::set($this->company);

    Livewire::test(MySchedule::class)->call('readMessage', $foreignReceipt->id)->assertNotFound();
    $this->actingAs($this->scheduler);
    Livewire::test(MessagesPage::class)->call('show', $foreign->id)->assertNotFound();
    Livewire::test(MessagesPage::class)->call('delete', $foreign->id)->assertNotFound();
    expect(CurrentCompany::run($otherCompany, fn (): int => Message::query()->count()))->toBe(1);
});

it('writes no audit row when a message is read or confirmed', function () {
    p4Shift();
    p4Publish();
    $message = p4Send(['requires_ack' => true])['message'];
    $receipt = MessageRecipient::query()->where('message_id', $message->id)->firstOrFail();
    $before = AuditLog::query()->count();

    app(MarkMessage::class)->read($this->worker, $this->company, $receipt);
    app(MarkMessage::class)->acknowledge($this->worker, $this->company, $receipt->fresh());

    expect(AuditLog::query()->count())->toBe($before);
});

it('refuses a confirmation of a message that asks for none', function () {
    p4Shift();
    p4Publish();
    $receipt = MessageRecipient::query()->where('message_id', p4Send()['message']->id)->firstOrFail();

    expect(fn () => app(MarkMessage::class)->acknowledge($this->worker, $this->company, $receipt))->toThrow(ValidationException::class);
});

it('removes messages and receipts twelve months after their last day, and keeps the rest', function () {
    p4Shift();
    p4Publish();
    $old = p4Send()['message'];
    $kept = p4Send()['message'];
    $old->forceFill(['date_from' => '2025-10-01', 'date_to' => '2025-10-31'])->save();
    $kept->forceFill(['date_from' => '2025-11-04', 'date_to' => '2025-11-04'])->save();

    $this->artisan('planning:prune-messages')->assertSuccessful();

    expect(Message::query()->pluck('id')->all())->toBe([$kept->id])
        ->and(MessageRecipient::query()->where('message_id', $old->id)->count())->toBe(0);
});

it('puts received messages in the employee\'s register extract and written ones in the author\'s', function () {
    p4Shift();
    p4Publish();
    $message = p4Send(['body' => 'Ny kod till dörren kommer på måndag.'])['message'];
    app(MarkMessage::class)->read($this->worker, $this->company, MessageRecipient::query()->where('message_id', $message->id)->firstOrFail());

    $annas = DataSubjectExport::employee($this->anna)['schemameddelanden'];
    $authors = DataSubjectExport::user($this->scheduler)['skrivna_schemameddelanden'];

    expect($annas)->toHaveCount(1)
        ->and($annas[0]['meddelande'])->toBe('Ny kod till dörren kommer på måndag.')
        ->and($annas[0]['last'])->not->toBeNull()
        ->and(DataSubjectExport::employee($this->bertil)['schemameddelanden'])->toBe([])
        ->and($authors)->toHaveCount(1)
        ->and($authors[0])->toMatchArray(['meddelande' => 'Ny kod till dörren kommer på måndag.', 'till' => Message::PERIOD, 'foretag' => $this->company->id]);
});

it('loads the messages page in the same number of queries with 1 message and with 12', function () {
    $count = function (): int {
        DB::flushQueryLog();
        DB::enableQueryLog();
        Livewire::test(MessagesPage::class)->call('$refresh');
        $queries = count(DB::getQueryLog());
        DB::disableQueryLog();

        return $queries;
    };

    p4Shift();
    p4Publish();
    p4Send();
    $this->actingAs($this->scheduler);
    $count();
    $few = $count();

    p4Shift(['employee_id' => $this->bertil->id, 'date' => '2026-11-12']);
    p4Shift(['employee_id' => $this->cecilia->id, 'date' => '2026-11-13']);
    p4Publish();

    foreach (range(1, 11) as $number) {
        p4Send(['body' => "Meddelande {$number}"]);
    }

    expect($count())->toBe($few);
});

it('loads Min sida in the same number of queries with 1 message and with 10', function () {
    $count = function (): int {
        DB::flushQueryLog();
        DB::enableQueryLog();
        Livewire::test(MySchedule::class)->call('nextWeek');
        $queries = count(DB::getQueryLog());
        DB::disableQueryLog();

        return $queries;
    };

    p4Shift();
    p4Publish();
    p4Send();
    $this->actingAs($this->worker);
    $count();
    $few = $count();

    foreach (range(1, 9) as $number) {
        p4Send(['body' => "Meddelande {$number}", 'requires_ack' => $number % 2 === 0]);
    }

    expect($count())->toBe($few);
});

it('answers 403 when a planner loses the role while the page is open', function () {
    p4Shift();
    p4Publish();
    $message = p4Send()['message'];
    $this->actingAs($this->scheduler);
    Livewire::test(MessagesPage::class)->assertSee('Skickade meddelanden');
    // An open page keeps its component: its actions and its render check the role again.
    $open = new MessagesPage;
    $open->mount();

    $this->company->users()->updateExistingPivot($this->scheduler->id, ['role' => 'employee']);
    $this->scheduler->unsetRelations();

    expect(fn () => $open->show($message->id))->toThrow(fn (HttpException $e) => expect($e->getStatusCode())->toBe(403))
        ->and(fn () => $open->render())->toThrow(fn (HttpException $e) => expect($e->getStatusCode())->toBe(403));
    Livewire::test(MessagesPage::class)->assertForbidden();
});

it('finds a published shift that was moved more than a day without being republished', function () {
    $shift = p4Shift(['date' => '2026-11-10']);
    p4Publish();
    // Moved two weeks ahead and not republished: Anna was shown the 10th.
    app(SaveShift::class)($this->owner, $this->company, ['employee_id' => $this->anna->id, 'area_id' => $this->kitchen->id, 'date' => '2026-11-24', 'start' => '08:00', 'end' => '16:00'], $shift->fresh());

    expect($shift->fresh()->starts_at->toDateString())->toBe('2026-11-24')
        ->and(p4RecipientIds(p4Send()['message']))->toBe([$this->anna->id])
        ->and(fn () => p4Send(['date_from' => '2026-11-23', 'date_to' => '2026-11-29']))->toThrow(ValidationException::class, 'Publicera schemat först');
});

it('tells the employee on Min sida and in the e-mail that reading is seen by the manager', function () {
    p4Shift();
    p4Publish();
    p4Send();

    $this->actingAs($this->worker);
    Livewire::test(MySchedule::class)->assertSee('När du öppnar ett meddelande ser din chef att du har läst det, och när.');

    expect((string) (new ScheduleMessageMail('Exempel AB', false))->render())->toContain('ser din chef att du har läst det');
});
