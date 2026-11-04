<?php

declare(strict_types=1);

use App\Actions\Privacy\DeleteAccount;
use App\Models\AuditLog;
use App\Models\Company;
use App\Models\User;
use App\Modules\Crm\Actions\DisconnectCalendarAccount;
use App\Modules\Crm\Actions\IssueCalendarFeed;
use App\Modules\Crm\Actions\LogActivity;
use App\Modules\Crm\Calendar\CalendarEntries;
use App\Modules\Crm\Calendar\CalendarProviderKey;
use App\Modules\Crm\Calendar\CalendarSync;
use App\Modules\Crm\Calendar\Jobs\SyncCalendarActivity;
use App\Modules\Crm\Calendar\Jobs\SyncCalendarConnection;
use App\Modules\Crm\Calendar\Providers\CalendarProviderException;
use App\Modules\Crm\Calendar\Providers\MicrosoftGraphProvider;
use App\Modules\Crm\CrmModule;
use App\Modules\Crm\CrmSettings;
use App\Modules\Crm\Http\CalendarConnectController;
use App\Modules\Crm\Livewire\CalendarPage;
use App\Modules\Crm\Models\Activity;
use App\Modules\Crm\Models\CalendarConnection;
use App\Modules\Crm\Models\CalendarEvent;
use App\Modules\Crm\Models\CalendarFeed;
use App\Modules\ModuleManager;
use App\Support\Tenancy\CurrentCompany;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Laravel\Jetstream\Events\TeamMemberRemoved;
use Livewire\Livewire;
use Tests\Support\Bookkeeping;
use Tests\Support\Crm;

/*
 * CRM stage 6 (ADR-049): two-way sync with Google Calendar and Microsoft
 * Graph, built but dormant. Every request to Google and Microsoft is faked;
 * nothing here reaches the internet (Http::preventStrayRequests).
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

function configureGoogle(bool $allow = true): void
{
    config(['services.google_calendar.client_id' => 'google-client', 'services.google_calendar.client_secret' => 'google-secret']);

    if ($allow) {
        allowCalendarSync(test()->company, test()->owner);
    }
}

function allowCalendarSync(Company $company, User $owner, int $on = 1): void
{
    app(ModuleManager::class)->updateSettings(CrmModule::KEY, $company, $owner, [...CrmSettings::for($company), 'calendar_sync' => $on]);
}

function googleConnection(array $extra = []): CalendarConnection
{
    return CalendarConnection::create([
        'user_id' => test()->owner->id,
        'provider' => CalendarProviderKey::Google,
        'account_email' => 'saljare@exempel.test',
        'access_token' => 'access-1',
        'refresh_token' => 'refresh-1',
        'token_expires_at' => CarbonImmutable::now()->addHour(),
        'calendar_id' => 'primary',
        'calendar_name' => 'Säljaren',
        'status' => CalendarConnection::ACTIVE,
        ...$extra,
    ]);
}

it('stays dormant without configuration: no screen, no routes, nothing queued', function () {
    Bus::fake();

    app(LogActivity::class)($this->owner, $this->company, ['account_id' => $this->account->id, 'type' => 'MEETING', 'subject' => 'Möte', 'due_at' => '2026-06-18T09:00']);

    Bus::assertNotDispatched(SyncCalendarActivity::class);
    $this->get(route('crm.calendar.connect', 'google'))->assertNotFound();
    $this->get(route('crm.calendar'))->assertOk()->assertDontSee('Koppla din kalender');
    $this->artisan('crm:calendar-sync')->expectsOutputToContain('Queued 0 calendar connections, ended 0.')->assertSuccessful();
    Http::assertNothingSent();
});

it('connects Google with state and PKCE, keeps the tokens encrypted and queues the first sync', function () {
    configureGoogle();
    Bus::fake([SyncCalendarConnection::class]);

    $this->get(route('crm.calendar'))->assertSee('Koppla din kalender')->assertSee('Koppla Google Kalender');

    $redirect = $this->get(route('crm.calendar.connect', 'google'))->assertRedirect();
    $location = (string) $redirect->headers->get('Location');
    parse_str((string) parse_url($location, PHP_URL_QUERY), $query);
    $pending = session(CalendarConnectController::SESSION);

    expect($location)->toStartWith('https://accounts.google.com/o/oauth2/v2/auth?')
        ->and($query['code_challenge_method'])->toBe('S256')
        ->and($query['access_type'])->toBe('offline')
        ->and($query['redirect_uri'])->toBe(route('crm.calendar.callback', 'google'))
        ->and($query['scope'])->toContain('calendar.events')
        ->and($query['code_challenge'])->toBe(rtrim(strtr(base64_encode(hash('sha256', $pending['verifier'], true)), '+/', '-_'), '='));

    // A wrong state is refused before any code is exchanged.
    $this->get(route('crm.calendar.callback', ['provider' => 'google', 'state' => 'fel', 'code' => 'x']))->assertRedirect(route('crm.calendar'));
    expect(CalendarConnection::query()->count())->toBe(0);

    $this->get(route('crm.calendar.connect', 'google'));
    $state = session(CalendarConnectController::SESSION)['state'];

    Http::fake([
        'oauth2.googleapis.com/token' => Http::response(['access_token' => 'access-1', 'refresh_token' => 'refresh-1', 'expires_in' => 3600]),
        'openidconnect.googleapis.com/*' => Http::response(['email' => 'saljare@exempel.test', 'hd' => 'exempel.test']),
        'www.googleapis.com/calendar/v3/users/me/calendarList*' => Http::response(['items' => [['id' => 'work', 'summary' => 'Jobb'], ['id' => 'saljare@exempel.test', 'summary' => 'Säljaren', 'primary' => true]]]),
    ]);

    $this->get(route('crm.calendar.callback', ['provider' => 'google', 'state' => $state, 'code' => 'the-code']))->assertRedirect(route('crm.calendar'));

    $connection = CalendarConnection::query()->firstOrFail();
    $raw = DB::table('crm_calendar_connections')->first();

    expect($connection->access_token)->toBe('access-1')
        ->and($connection->calendar_id)->toBe('saljare@exempel.test')
        ->and($connection->account_email)->toBe('saljare@exempel.test')
        ->and($raw->access_token)->not->toContain('access-1')
        ->and($raw->refresh_token)->not->toContain('refresh-1')
        ->and($raw->account_email)->not->toContain('saljare')
        ->and(AuditLog::query()->where('action', 'crm.calendar_connected')->value('payload'))->toBe(['provider' => 'GOOGLE']);

    Http::assertSent(fn (Request $request): bool => $request->url() === 'https://oauth2.googleapis.com/token' && $request['code_verifier'] !== null && $request['code'] === 'the-code');
    Bus::assertDispatched(SyncCalendarConnection::class);
});

it('writes a planned meeting to Google, moves it, and removes it when done', function () {
    configureGoogle();
    $connection = googleConnection();
    Http::fake([
        'www.googleapis.com/calendar/v3/calendars/primary/events' => Http::response(['id' => 'evt-1']),
        'www.googleapis.com/calendar/v3/calendars/primary/events/evt-1' => fn (Request $request) => $request->method() === 'DELETE' ? Http::response(null, 204) : Http::response(['id' => 'evt-1']),
    ]);

    $meeting = app(LogActivity::class)($this->owner, $this->company, ['account_id' => $this->account->id, 'type' => 'MEETING', 'subject' => 'Hemligt ämne', 'body' => 'Intern text', 'due_at' => '2026-06-18T09:00']);
    $mapping = CalendarEvent::query()->firstOrFail();

    expect($mapping->external_id)->toBe('evt-1')->and($mapping->synced_sequence)->toBe(0);
    Http::assertSent(fn (Request $request): bool => $request->method() === 'POST'
        && $request['summary'] === 'Möte: Bygg AB'
        && ! str_contains((string) json_encode($request->data()), 'Intern text')
        && $request['start']['dateTime'] === '2026-06-18T09:00:00+02:00'
        && $request['extendedProperties']['private']['nonoxActivity'] === CalendarEntries::uid($meeting->id));

    app(LogActivity::class)($this->owner, $this->company, ['due_at' => '2026-06-18T11:00'], $meeting->fresh());
    Http::assertSent(fn (Request $request): bool => $request->method() === 'PATCH' && $request['start']['dateTime'] === '2026-06-18T11:00:00+02:00');
    expect($mapping->fresh()->synced_sequence)->toBe(1);

    $meeting->fresh()->forceFill(['done_at' => now()])->save();
    Http::assertSent(fn (Request $request): bool => $request->method() === 'DELETE');
    expect(CalendarEvent::query()->count())->toBe(0)->and($connection->fresh()->isActive())->toBeTrue();
});

it('reads back only NONOX\'s events: a move follows, a cancellation closes, other events are never stored', function () {
    configureGoogle();
    Http::fake(['www.googleapis.com/calendar/v3/calendars/primary/events' => Http::sequence()->push(['id' => 'evt-a'])->push(['id' => 'evt-b'])]);
    $connection = googleConnection();
    $moved = app(LogActivity::class)($this->owner, $this->company, ['account_id' => $this->account->id, 'type' => 'MEETING', 'subject' => 'Flyttas', 'due_at' => '2026-06-18T09:00']);
    $cancelled = app(LogActivity::class)($this->owner, $this->company, ['account_id' => $this->account->id, 'type' => 'CALL', 'subject' => 'Ställs in', 'due_at' => '2026-06-19T09:00']);
    CalendarEvent::query()->where('activity_id', $moved->id)->update(['external_id' => 'evt-moved']);
    CalendarEvent::query()->where('activity_id', $cancelled->id)->update(['external_id' => 'evt-cancelled']);

    Http::fake([
        'www.googleapis.com/calendar/v3/calendars/primary/events?*' => Http::response(['items' => [
            ['id' => 'evt-moved', 'status' => 'confirmed', 'start' => ['dateTime' => '2026-06-18T14:00:00+02:00'], 'end' => ['dateTime' => '2026-06-18T15:30:00+02:00']],
            ['id' => 'evt-cancelled', 'status' => 'cancelled'],
            ['id' => 'private-dentist', 'status' => 'confirmed', 'summary' => 'Tandläkaren', 'start' => ['dateTime' => '2026-06-18T08:00:00+02:00'], 'end' => ['dateTime' => '2026-06-18T09:00:00+02:00']],
        ], 'nextSyncToken' => 'sync-2']),
    ]);

    $applied = app(CalendarSync::class)->pull($connection->fresh());

    expect($applied)->toBe(2)
        ->and($moved->fresh()->due_at->setTimezone('Europe/Stockholm')->format('Y-m-d H:i'))->toBe('2026-06-18 14:00')
        ->and($moved->fresh()->duration_minutes)->toBe(90)
        ->and($cancelled->fresh()->isOpen())->toBeFalse()
        ->and($cancelled->fresh()->body)->toContain('Inställd i kalendern')
        ->and(CalendarEvent::query()->pluck('external_id')->all())->toBe(['evt-moved'])
        ->and(CalendarEvent::query()->first()->synced_sequence)->toBe($moved->fresh()->calendar_sequence)
        ->and(Activity::query()->where('subject', 'like', '%Tandläkaren%')->exists())->toBeFalse()
        ->and($connection->fresh()->sync_cursor)->toBe('sync-2')
        ->and(json_encode(AuditLog::query()->where('action', 'like', 'crm.calendar_%')->pluck('payload')->all()))->not->toContain('Tandläkaren');
});

it('lets NONOX win when the activity changed since it was last written, and keeps a deal\'s last step open', function () {
    configureGoogle();
    Bus::fake([SyncCalendarActivity::class]);
    $connection = googleConnection();
    $meeting = app(LogActivity::class)($this->owner, $this->company, ['account_id' => $this->account->id, 'type' => 'MEETING', 'subject' => 'Möte', 'due_at' => '2026-06-18T09:00']);
    CalendarEvent::create(['connection_id' => $connection->id, 'activity_id' => $meeting->id, 'external_id' => 'evt-1', 'synced_sequence' => 0]);
    app(LogActivity::class)($this->owner, $this->company, ['subject' => 'Nytt ämne'], $meeting->fresh());

    $deal = Crm::deal($this->company, $this->owner, $this->account, 'Ramavtal');
    $step = Activity::query()->where('deal_id', $deal->id)->firstOrFail();
    CalendarEvent::create(['connection_id' => $connection->id, 'activity_id' => $step->id, 'external_id' => 'evt-step', 'synced_sequence' => $step->calendar_sequence]);

    Http::fake(['www.googleapis.com/calendar/v3/calendars/primary/events?*' => Http::response(['items' => [
        ['id' => 'evt-1', 'start' => ['dateTime' => '2026-06-18T16:00:00+02:00'], 'end' => ['dateTime' => '2026-06-18T17:00:00+02:00']],
        ['id' => 'evt-step', 'status' => 'cancelled'],
    ], 'nextSyncToken' => 's'])]);

    expect(app(CalendarSync::class)->pull($connection))->toBe(0)
        ->and($meeting->fresh()->due_at->setTimezone('Europe/Stockholm')->format('H:i'))->toBe('09:00')
        ->and($step->fresh()->isOpen())->toBeTrue()
        ->and(AuditLog::query()->where('action', 'crm.calendar_cancel_kept')->exists())->toBeTrue();
});

it('reads everything again when the sync token has expired', function () {
    configureGoogle();
    $connection = googleConnection(['sync_cursor' => 'old']);
    Http::fake(['www.googleapis.com/calendar/v3/calendars/primary/events?*' => fn (Request $request) => str_contains($request->url(), 'syncToken=old')
        ? Http::response(['error' => ['code' => 410]], 410)
        : Http::response(['items' => [], 'nextSyncToken' => 'fresh'])]);

    app(CalendarSync::class)->pull($connection);

    expect($connection->fresh()->sync_cursor)->toBe('fresh');
});

it('refreshes an expired access token, and asks the user to connect again when the grant is gone', function () {
    configureGoogle();
    $connection = googleConnection(['token_expires_at' => CarbonImmutable::now()->subMinute()]);
    Http::fake([
        'oauth2.googleapis.com/token' => Http::sequence()->push(['access_token' => 'access-2', 'expires_in' => 3600])->push(['error' => 'invalid_grant'], 400),
        'www.googleapis.com/calendar/v3/calendars/primary/events?*' => Http::response(['items' => [], 'nextSyncToken' => 's']),
    ]);

    app(CalendarSync::class)->pull($connection);
    expect($connection->fresh()->access_token)->toBe('access-2')->and($connection->fresh()->refresh_token)->toBe('refresh-1');

    $connection->forceFill(['token_expires_at' => CarbonImmutable::now()->subMinute()])->save();
    expect(fn () => app(CalendarSync::class)->pull($connection->fresh()))->toThrow(CalendarProviderException::class);
    expect($connection->fresh()->status)->toBe(CalendarConnection::ERROR);

    $this->get(route('crm.calendar'))->assertSee('Koppla igen');
});

it('disconnects: removes NONOX\'s events, revokes the grant and deletes the tokens', function () {
    configureGoogle();
    Http::fake([
        'www.googleapis.com/calendar/v3/calendars/primary/events' => Http::response(['id' => 'evt-1']),
        'www.googleapis.com/calendar/v3/calendars/primary/events/evt-1' => Http::response(null, 204),
        'oauth2.googleapis.com/revoke' => Http::response(null, 200),
    ]);
    $connection = googleConnection();
    app(LogActivity::class)($this->owner, $this->company, ['account_id' => $this->account->id, 'type' => 'MEETING', 'subject' => 'Möte', 'due_at' => '2026-06-18T09:00']);
    expect(CalendarEvent::query()->where('connection_id', $connection->id)->value('external_id'))->toBe('evt-1');

    Livewire::test(CalendarPage::class)->call('disconnect')->assertDispatched('toast');

    Http::assertSent(fn (Request $request): bool => $request->method() === 'DELETE');
    Http::assertSent(fn (Request $request): bool => $request->url() === 'https://oauth2.googleapis.com/revoke' && $request['token'] === 'refresh-1');
    expect(CalendarConnection::query()->count())->toBe(0)
        ->and(CalendarEvent::query()->count())->toBe(0)
        ->and(AuditLog::query()->where('action', 'crm.calendar_disconnected')->exists())->toBeTrue();
});

it('disconnects a member who leaves the company', function () {
    configureGoogle();
    $seller = Bookkeeping::member($this->company, 'sales');
    CurrentCompany::set($this->company);
    googleConnection(['user_id' => $seller->id]);
    Http::fake(['oauth2.googleapis.com/revoke' => Http::response(null, 200)]);

    $this->company->users()->detach($seller->id);
    event(new TeamMemberRemoved($this->company, $seller));

    expect(CalendarConnection::query()->where('user_id', $seller->id)->exists())->toBeFalse();
    Http::assertSent(fn (Request $request): bool => str_contains($request->url(), 'revoke'));
});

it('deletes the links and connections, and revokes the grant, when the user deletes their account', function () {
    configureGoogle();
    $seller = Bookkeeping::member($this->company, 'sales');
    CurrentCompany::set($this->company);
    app(IssueCalendarFeed::class)($seller, $this->company);
    googleConnection(['user_id' => $seller->id]);
    Http::fake(['oauth2.googleapis.com/revoke' => Http::response(null, 200)]);

    app(DeleteAccount::class)($seller);

    expect(DB::table('crm_calendar_feeds')->where('user_id', $seller->id)->exists())->toBeFalse()
        ->and(DB::table('crm_calendar_connections')->where('user_id', $seller->id)->exists())->toBeFalse();
    Http::assertSent(fn (Request $request): bool => str_contains($request->url(), 'revoke') && $request['token'] === 'refresh-1');
});

it('talks to Microsoft Graph: UTC times, delta with removed events and a delta link, no token revocation', function () {
    config(['services.microsoft_graph.client_id' => 'ms-client', 'services.microsoft_graph.client_secret' => 'ms-secret']);
    $graph = app(MicrosoftGraphProvider::class);

    expect($graph->authorizationUrl('s', 'c'))->toStartWith('https://login.microsoftonline.com/organizations/oauth2/v2.0/authorize?')
        ->and($graph->authorizationUrl('s', 'c'))->toContain('Calendars.ReadWrite')
        ->and($graph->revoke('token'))->toBeFalse();

    Http::fake([
        'graph.microsoft.com/v1.0/me/calendars/cal-1/events' => Http::response(['id' => 'ms-1']),
        'graph.microsoft.com/v1.0/me/calendars/cal-1/calendarView/delta*' => Http::response(['value' => [
            ['id' => 'ms-1', 'start' => ['dateTime' => '2026-06-18T12:00:00.0000000', 'timeZone' => 'UTC'], 'end' => ['dateTime' => '2026-06-18T13:00:00.0000000', 'timeZone' => 'UTC']],
            ['id' => 'ms-2', '@removed' => ['reason' => 'deleted']],
        ], '@odata.nextLink' => 'https://graph.microsoft.com/v1.0/next-page']),
        'graph.microsoft.com/v1.0/next-page' => Http::response(['value' => [], '@odata.deltaLink' => 'https://graph.microsoft.com/v1.0/delta-link']),
    ]);

    $meeting = app(LogActivity::class)($this->owner, $this->company, ['account_id' => $this->account->id, 'type' => 'MEETING', 'subject' => 'Möte', 'due_at' => '2026-06-18T09:00']);
    $entry = CalendarEntries::entry(Activity::query()->with(CalendarEntries::relations())->findOrFail($meeting->id), false);

    expect($graph->upsert('token', 'cal-1', $entry, null))->toBe('ms-1');
    Http::assertSent(fn (Request $request): bool => $request->method() === 'POST' && $request['start'] === ['dateTime' => '2026-06-18T07:00:00', 'timeZone' => 'UTC'] && $request['sensitivity'] === 'private');

    $changes = $graph->changes('token', 'cal-1', null);

    expect($changes->cursor)->toBe('https://graph.microsoft.com/v1.0/delta-link')
        ->and($changes->changes)->toHaveCount(2)
        ->and($changes->changes[0]->start?->utc()->format('Y-m-d H:i'))->toBe('2026-06-18 12:00')
        ->and($changes->changes[1]->cancelled)->toBeTrue();
});

it('queues every active connection of a configured provider from the scheduled command', function () {
    configureGoogle();
    Bus::fake([SyncCalendarConnection::class]);
    googleConnection();

    $this->artisan('crm:calendar-sync')->assertSuccessful();

    Bus::assertDispatched(SyncCalendarConnection::class, 1);
});

it('needs the company\'s yes before anyone can connect, and disconnects everyone when it is taken back', function () {
    configureGoogle(allow: false);

    $this->get(route('crm.calendar'))->assertDontSee('Koppla din kalender');
    $this->get(route('crm.calendar.connect', 'google'))->assertNotFound();

    allowCalendarSync($this->company, $this->owner);
    $this->get(route('crm.calendar'))->assertSee('Koppla din kalender');
    $connection = googleConnection();

    allowCalendarSync($this->company, $this->owner, 0);
    Http::fake(['oauth2.googleapis.com/revoke' => Http::response(null, 200)]);
    (new SyncCalendarConnection($this->company->id, $connection->id))->handle(app(CalendarSync::class));

    expect(CalendarConnection::query()->count())->toBe(0);
});

it('writes no details to a connected calendar when the company does not allow them', function () {
    configureGoogle();
    Http::fake(['www.googleapis.com/calendar/v3/calendars/primary/events' => Http::response(['id' => 'evt-1'])]);
    googleConnection(['include_details' => true]);
    app(ModuleManager::class)->updateSettings(CrmModule::KEY, $this->company, $this->owner, [...CrmSettings::for($this->company), 'calendar_details' => 0]);

    app(LogActivity::class)($this->owner, $this->company, ['account_id' => $this->account->id, 'type' => 'MEETING', 'subject' => 'Hemligt ämne', 'due_at' => '2026-06-18T09:00']);

    Http::assertSent(fn (Request $request): bool => $request['summary'] === 'Möte: Bygg AB');
});

it('lets a user end their own connection even without one', function () {
    expect(app(DisconnectCalendarAccount::class)($this->owner, $this->company))->toBeFalse()
        ->and(CalendarFeed::query()->count())->toBe(0);
});
