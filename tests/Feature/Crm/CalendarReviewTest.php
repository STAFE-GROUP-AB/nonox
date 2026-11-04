<?php

declare(strict_types=1);

use App\Models\Company;
use App\Models\User;
use App\Modules\Crm\Actions\ConnectCalendar;
use App\Modules\Crm\Actions\EraseCrmData;
use App\Modules\Crm\Actions\IssueCalendarFeed;
use App\Modules\Crm\Actions\LinkPerson;
use App\Modules\Crm\Actions\LogActivity;
use App\Modules\Crm\Actions\SaveAccount;
use App\Modules\Crm\Actions\SavePerson;
use App\Modules\Crm\Calendar\CalendarEntries;
use App\Modules\Crm\Calendar\CalendarProviderKey;
use App\Modules\Crm\Calendar\CalendarSync;
use App\Modules\Crm\Calendar\Jobs\DisconnectCalendar;
use App\Modules\Crm\Calendar\Jobs\SyncCalendarConnection;
use App\Modules\Crm\Calendar\Providers\GoogleCalendarProvider;
use App\Modules\Crm\CrmModule;
use App\Modules\Crm\CrmSettings;
use App\Modules\Crm\Domain\PersonRole;
use App\Modules\Crm\Livewire\AccountCard;
use App\Modules\Crm\Livewire\CrmSettingsForm;
use App\Modules\Crm\Models\Activity;
use App\Modules\Crm\Models\CalendarConnection;
use App\Modules\Crm\Models\CalendarEvent;
use App\Modules\ModuleManager;
use App\Support\Tenancy\CurrentCompany;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Tests\Support\Bookkeeping;
use Tests\Support\Crm;

/*
 * CRM calendar, review round (ADR-049): private persons' accounts, the
 * company's details setting on files, calendar sequences after mass
 * changes, the delta window, a kept deal step, a changed account,
 * idempotent inserts, organisation accounts, throttling and clean-up.
 */

beforeEach(function () {
    Http::preventStrayRequests();
    $this->travelTo(CarbonImmutable::parse('2026-06-15 10:00:00'));
    [$this->owner, $this->company] = Bookkeeping::company();
    CurrentCompany::set($this->company);
    $this->actingAs($this->owner);
    Crm::enable($this->company, $this->owner);
    $this->account = Crm::prospect($this->company, $this->owner, 'Bygg AB');
});

function crmSetting(Company $company, User $owner, array $values): void
{
    app(ModuleManager::class)->updateSettings(CrmModule::KEY, $company, $owner, [...CrmSettings::for($company), ...$values]);
}

function reviewGoogle(): void
{
    config(['services.google_calendar.client_id' => 'g', 'services.google_calendar.client_secret' => 's']);
    crmSetting(test()->company, test()->owner, ['calendar_sync' => 1]);
}

function reviewConnection(array $extra = []): CalendarConnection
{
    return CalendarConnection::create([
        'user_id' => test()->owner->id, 'provider' => CalendarProviderKey::Google, 'account_email' => 'saljare@exempel.test',
        'access_token' => 'access-1', 'refresh_token' => 'refresh-1', 'token_expires_at' => CarbonImmutable::now()->addHour(),
        'calendar_id' => 'primary', 'status' => CalendarConnection::ACTIVE, ...$extra,
    ]);
}

it('leaves out a private person\'s account when anyone at it has restricted the processing', function () {
    $private = Crm::prospect($this->company, $this->owner, 'Eva Privat', ['kind' => 'PERSON']);
    $eva = Crm::person($this->company, $this->owner, 'Eva Privat');
    $relative = Crm::person($this->company, $this->owner, 'Bo Privat');
    app(LinkPerson::class)($this->owner, $this->company, $private, $eva, PersonRole::DecisionMaker);
    app(LinkPerson::class)($this->owner, $this->company, $private, $relative, PersonRole::Other);
    app(LogActivity::class)($this->owner, $this->company, ['account_id' => $private->id, 'person_id' => $eva->id, 'type' => 'CALL', 'subject' => 'Ring Eva', 'due_at' => '2026-06-18T09:00']);

    $relative->forceFill(['do_not_contact_at' => now(), 'do_not_contact_reason' => 'RESTRICTION'])->save();

    expect(CalendarEntries::forUser($this->company, $this->owner, true))->toHaveCount(0);
    $activity = Activity::query()->with(CalendarEntries::relations())->where('subject', 'Ring Eva')->firstOrFail();
    expect(CalendarEntries::allows($activity))->toBeFalse();
    $this->get(route('crm.activities.ics', $activity->id))->assertNotFound();
});

it('names no one and copies no text for a private person\'s account with someone marked Kontakta inte, also with details', function () {
    $private = Crm::prospect($this->company, $this->owner, 'Eva Privat', ['kind' => 'PERSON']);
    $eva = Crm::person($this->company, $this->owner, 'Eva Privat');
    $relative = Crm::person($this->company, $this->owner, 'Bo Privat');
    app(LinkPerson::class)($this->owner, $this->company, $private, $eva, PersonRole::DecisionMaker);
    app(LinkPerson::class)($this->owner, $this->company, $private, $relative, PersonRole::Other);
    app(LogActivity::class)($this->owner, $this->company, ['account_id' => $private->id, 'person_id' => $eva->id, 'type' => 'MEETING', 'subject' => 'Köksrenovering hos Eva', 'body' => 'Hemlig text', 'due_at' => '2026-06-18T09:00']);

    $relative->forceFill(['do_not_contact_at' => now(), 'do_not_contact_reason' => 'OBJECTION'])->save();

    $entry = CalendarEntries::forUser($this->company, $this->owner, true)->sole();
    expect($entry->summary)->toBe('Möte: privatkund')
        ->and((string) $entry->description)->not->toContain('Hemlig')->not->toContain('Eva')
        ->and($entry->location)->toBeNull();
});

it('applies the company\'s details setting to a downloaded file', function () {
    $meeting = app(LogActivity::class)($this->owner, $this->company, ['account_id' => $this->account->id, 'type' => 'MEETING', 'subject' => 'Hemligt ämne', 'due_at' => '2026-06-18T09:00']);
    crmSetting($this->company, $this->owner, ['calendar_details' => 0]);

    $body = $this->get(route('crm.activities.ics', $meeting->id))->assertOk()->getContent();

    expect($body)->toContain('SUMMARY:Möte: Bygg AB')->not->toContain('Hemligt');
});

it('raises the calendar sequence when a person is erased, marked Kontakta inte or renamed, and when an account is renamed', function () {
    $person = Crm::person($this->company, $this->owner, 'Anna Andersson', ['email' => 'anna@exempel.test']);
    app(LinkPerson::class)($this->owner, $this->company, $this->account, $person, PersonRole::DecisionMaker);
    $meeting = app(LogActivity::class)($this->owner, $this->company, ['account_id' => $this->account->id, 'person_id' => $person->id, 'type' => 'MEETING', 'subject' => 'Möte', 'due_at' => '2026-06-18T09:00']);
    $sequence = fn (): int => $meeting->fresh()->calendar_sequence;

    app(SaveAccount::class)($this->owner, $this->company, ['name' => 'Bygg och Bo AB'], $this->account->fresh());
    expect($sequence())->toBe(1);

    app(SavePerson::class)($this->owner, $this->company, ['name' => 'Anna Berg', 'legal_basis' => 'LEGITIMATE_INTEREST', 'email' => 'anna@exempel.test'], $person->fresh());
    expect($sequence())->toBe(2);

    app(SavePerson::class)($this->owner, $this->company, ['name' => 'Anna Berg', 'legal_basis' => 'LEGITIMATE_INTEREST', 'email' => 'anna@exempel.test', 'do_not_contact' => true, 'do_not_contact_reason' => 'OBJECTION'], $person->fresh());
    expect($sequence())->toBe(3);

    app(EraseCrmData::class)->person($this->owner, $this->company, $person->fresh());
    expect($sequence())->toBe(4);
});

it('writes every connected calendar again when the company changes the details setting', function () {
    reviewGoogle();
    Bus::fake([SyncCalendarConnection::class]);
    reviewConnection();

    Livewire::test(CrmSettingsForm::class, ['company' => $this->company])->set('settings.calendar_details', false)->call('save')->assertHasNoErrors();

    Bus::assertDispatched(SyncCalendarConnection::class, fn (SyncCalendarConnection $job): bool => $job->force);
});

it('starts a Microsoft delta read again when its window is older than 30 days', function () {
    config(['services.microsoft_graph.client_id' => 'm', 'services.microsoft_graph.client_secret' => 's']);
    crmSetting($this->company, $this->owner, ['calendar_sync' => 1]);
    $connection = reviewConnection(['provider' => CalendarProviderKey::Microsoft, 'calendar_id' => 'cal-1', 'sync_cursor' => 'https://graph.microsoft.com/v1.0/old-delta', 'cursor_started_at' => CarbonImmutable::now()->subDays(31)]);
    Http::fake(['graph.microsoft.com/v1.0/me/calendars/cal-1/calendarView/delta*' => Http::response(['value' => [], '@odata.deltaLink' => 'https://graph.microsoft.com/v1.0/new-delta'])]);

    app(CalendarSync::class)->pull($connection);

    Http::assertNotSent(fn (Request $request): bool => str_contains($request->url(), 'old-delta'));
    expect($connection->fresh()->sync_cursor)->toBe('https://graph.microsoft.com/v1.0/new-delta')
        ->and($connection->fresh()->cursor_started_at?->isSameDay(CarbonImmutable::now()))->toBeTrue();
});

it('keeps a deal\'s last step cancelled in the calendar out of the calendar until it changes in NONOX', function () {
    reviewGoogle();
    $connection = reviewConnection();
    Http::fake([
        'www.googleapis.com/calendar/v3/calendars/primary/events' => Http::response(['id' => 'evt-step']),
        'www.googleapis.com/calendar/v3/calendars/primary/events/evt-step' => Http::response(['id' => 'evt-step']),
        'www.googleapis.com/calendar/v3/calendars/primary/events?*' => Http::response(['items' => [['id' => 'evt-step', 'status' => 'cancelled']], 'nextSyncToken' => 's']),
    ]);
    $deal = Crm::deal($this->company, $this->owner, $this->account, 'Ramavtal');
    $step = Activity::query()->where('deal_id', $deal->id)->firstOrFail();
    app(LogActivity::class)($this->owner, $this->company, ['due_at' => '2026-06-20T10:00'], $step->fresh());
    expect(CalendarEvent::query()->value('external_id'))->toBe('evt-step');

    app(CalendarSync::class)->pull($connection->fresh());

    $mapping = CalendarEvent::query()->firstOrFail();
    expect($step->fresh()->isOpen())->toBeTrue()->and($mapping->cancelled_remotely_at)->not->toBeNull();

    // A full round, even forced, does not write it back.
    $sent = count(Http::recorded());
    app(CalendarSync::class)->pushAll($connection->fresh(), true);
    expect(count(Http::recorded()))->toBe($sent);

    // Moved in NONOX: written again, and the cancellation is forgotten.
    app(LogActivity::class)($this->owner, $this->company, ['due_at' => '2026-06-21T10:00'], $step->fresh());
    Http::assertSent(fn (Request $request): bool => $request->method() === 'PATCH' && $request['status'] === 'confirmed');
    expect($mapping->fresh()->cancelled_remotely_at)->toBeNull();
});

it('starts over when the user reconnects with another account, and revokes the old grant', function () {
    reviewGoogle();
    Bus::fake([SyncCalendarConnection::class]);
    $connection = reviewConnection(['status' => CalendarConnection::ERROR, 'calendar_id' => 'old-calendar', 'sync_cursor' => 'old']);
    $meeting = app(LogActivity::class)($this->owner, $this->company, ['account_id' => $this->account->id, 'type' => 'MEETING', 'subject' => 'Möte', 'due_at' => '2026-06-18T09:00']);
    CalendarEvent::create(['connection_id' => $connection->id, 'activity_id' => $meeting->id, 'external_id' => 'evt-old']);
    Http::fake([
        'oauth2.googleapis.com/token' => Http::response(['access_token' => 'access-2', 'refresh_token' => 'refresh-2', 'expires_in' => 3600]),
        'oauth2.googleapis.com/revoke' => Http::response(null, 200),
        'openidconnect.googleapis.com/*' => Http::response(['email' => 'annan@exempel.test', 'hd' => 'exempel.test']),
        'www.googleapis.com/calendar/v3/users/me/calendarList*' => Http::response(['items' => [['id' => 'annan@exempel.test', 'summary' => 'Annan', 'primary' => true]]]),
    ]);

    app(ConnectCalendar::class)($this->owner, $this->company, CalendarProviderKey::Google, 'code', 'verifier');

    $fresh = $connection->fresh();
    expect($fresh->calendar_id)->toBe('annan@exempel.test')
        ->and($fresh->sync_cursor)->toBeNull()
        ->and($fresh->refresh_token)->toBe('refresh-2')
        ->and(CalendarEvent::query()->count())->toBe(0);
    Http::assertSent(fn (Request $request): bool => str_contains($request->url(), 'revoke') && $request['token'] === 'refresh-1');
    Bus::assertDispatched(SyncCalendarConnection::class, fn (SyncCalendarConnection $job): bool => $job->force);
});

it('inserts Google events with an id of its own, sends no sequence, and updates on a conflict', function () {
    reviewGoogle();
    reviewConnection();
    Http::fake([
        'www.googleapis.com/calendar/v3/calendars/primary/events' => Http::response(['error' => ['code' => 409]], 409),
        'www.googleapis.com/calendar/v3/calendars/primary/events/*' => Http::response(['id' => 'existing']),
    ]);

    $meeting = app(LogActivity::class)($this->owner, $this->company, ['account_id' => $this->account->id, 'type' => 'MEETING', 'subject' => 'Möte', 'due_at' => '2026-06-18T09:00']);
    $id = GoogleCalendarProvider::eventId(CalendarEntries::entry(Activity::query()->with(CalendarEntries::relations())->findOrFail($meeting->id), false));

    expect($id)->toMatch('/^[0-9a-v]{5,1024}$/');
    Http::assertSent(fn (Request $request): bool => $request->method() === 'POST' && $request['id'] === $id && ! array_key_exists('sequence', $request->data()));
    Http::assertSent(fn (Request $request): bool => $request->method() === 'PATCH' && str_ends_with($request->url(), '/events/'.$id) && ! array_key_exists('sequence', $request->data()));
});

it('refuses a private Google account unless the company allows private accounts', function () {
    reviewGoogle();
    Bus::fake([SyncCalendarConnection::class]);
    Http::fake([
        'oauth2.googleapis.com/token' => Http::response(['access_token' => 'a', 'refresh_token' => 'r', 'expires_in' => 3600]),
        'oauth2.googleapis.com/revoke' => Http::response(null, 200),
        'openidconnect.googleapis.com/*' => Http::response(['email' => 'privat@gmail.com']),
        'www.googleapis.com/calendar/v3/users/me/calendarList*' => Http::response(['items' => [['id' => 'privat@gmail.com', 'summary' => 'Privat', 'primary' => true]]]),
    ]);
    $connect = fn () => app(ConnectCalendar::class)($this->owner, $this->company, CalendarProviderKey::Google, 'code', 'verifier');

    expect($connect)->toThrow(ValidationException::class, 'jobbkonton');
    expect(CalendarConnection::query()->count())->toBe(0);
    Http::assertSent(fn (Request $request): bool => str_contains($request->url(), 'revoke'));

    crmSetting($this->company, $this->owner, ['calendar_private_accounts' => 1]);
    $connect();
    expect(CalendarConnection::query()->count())->toBe(1);
});

it('refuses a personal Microsoft account by its tenant', function () {
    config(['services.microsoft_graph.client_id' => 'm', 'services.microsoft_graph.client_secret' => 's']);
    crmSetting($this->company, $this->owner, ['calendar_sync' => 1]);
    $idToken = 'x.'.rtrim(strtr(base64_encode((string) json_encode(['tid' => '9188040d-6c67-4c5b-b112-36a304b66dad'])), '+/', '-_'), '=').'.y';
    Http::fake([
        'login.microsoftonline.com/*' => Http::response(['access_token' => 'a', 'refresh_token' => 'r', 'expires_in' => 3600, 'id_token' => $idToken]),
        'graph.microsoft.com/v1.0/me?*' => Http::response(['mail' => 'privat@outlook.com']),
    ]);

    expect(fn () => app(ConnectCalendar::class)($this->owner, $this->company, CalendarProviderKey::Microsoft, 'code', 'verifier'))->toThrow(ValidationException::class, 'jobbkonton');
});

it('throttles per link, and guessing links harder', function () {
    $first = app(IssueCalendarFeed::class)($this->owner, $this->company);
    $seller = Bookkeeping::member($this->company, 'sales');
    CurrentCompany::set($this->company);
    $second = app(IssueCalendarFeed::class)($seller, $this->company);

    foreach (range(1, 30) as $ignored) {
        $this->get($first)->assertOk();
    }

    $this->get($first)->assertStatus(429);
    $this->get($second)->assertOk();

    foreach (range(1, 10) as $guess) {
        $this->get('/kunder/kalender/'.str_repeat((string) ($guess % 10), 48).'.ics')->assertNotFound();
    }

    $this->get('/kunder/kalender/'.str_repeat('z', 48).'.ics')->assertStatus(429);
});

it('ends connections whose provider is no longer configured or whose company has CRM off', function () {
    Bus::fake([DisconnectCalendar::class, SyncCalendarConnection::class]);
    reviewConnection();

    $this->artisan('crm:calendar-sync')->assertSuccessful();

    Bus::assertDispatched(DisconnectCalendar::class, fn (DisconnectCalendar $job): bool => ! $job->removeEvents);
    Bus::assertNotDispatched(SyncCalendarConnection::class);
});

it('shows Lägg till i kalendern only on an open planned activity', function () {
    $meeting = app(LogActivity::class)($this->owner, $this->company, ['account_id' => $this->account->id, 'type' => 'MEETING', 'subject' => 'Möte', 'due_at' => '2026-06-18T09:00']);
    $meeting->forceFill(['done_at' => now()])->save();

    Livewire::test(AccountCard::class, ['accountId' => $this->account->id])->assertDontSee('Lägg till i kalendern');
});
