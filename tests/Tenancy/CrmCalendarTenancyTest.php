<?php

declare(strict_types=1);

use App\Modules\Crm\Actions\IssueCalendarFeed;
use App\Modules\Crm\Actions\LogActivity;
use App\Modules\Crm\Calendar\CalendarProviderKey;
use App\Modules\Crm\Calendar\CalendarSync;
use App\Modules\Crm\CrmModule;
use App\Modules\Crm\CrmSettings;
use App\Modules\Crm\Models\CalendarConnection;
use App\Modules\Crm\Models\CalendarEvent;
use App\Modules\Crm\Models\CalendarFeed;
use App\Modules\ModuleManager;
use App\Support\Tenancy\CurrentCompany;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\Support\Bookkeeping;
use Tests\Support\Crm;

/*
 * AGENTS.md §13.2 for the CRM calendar (ADR-049): a link and a connection
 * belong to one user in one company. A user who is a member of two
 * companies gets each company's activities only through that company's
 * link; another company's activity file answers 404, its links and
 * connections are invisible, and a sync round never touches its activities.
 */

beforeEach(function () {
    Http::preventStrayRequests();
    $this->travelTo(CarbonImmutable::parse('2026-06-15 10:00:00'));

    [$this->user, $this->a] = Bookkeeping::company();
    $this->actingAs($this->user);
    Crm::enable($this->a, $this->user);
    $accountA = Crm::prospect($this->a, $this->user, 'Alfakunden AB');
    $this->activityA = app(LogActivity::class)($this->user, $this->a, ['account_id' => $accountA->id, 'type' => 'MEETING', 'subject' => 'Alfamöte', 'due_at' => '2026-06-18T09:00']);

    [$ownerB, $this->b] = Bookkeeping::company();
    Crm::enable($this->b, $ownerB);
    $this->b->users()->attach($this->user, ['role' => 'admin']);
    $accountB = Crm::prospect($this->b, $ownerB, 'Betakunden AB');
    $this->activityB = app(LogActivity::class)($ownerB, $this->b, ['account_id' => $accountB->id, 'type' => 'MEETING', 'subject' => 'Betamöte', 'due_at' => '2026-06-18T10:00', 'assigned_to' => $this->user->id]);
    $this->feedB = app(IssueCalendarFeed::class)($this->user, $this->b);
    CalendarConnection::create(['user_id' => $this->user->id, 'provider' => CalendarProviderKey::Google, 'access_token' => 'b-token', 'calendar_id' => 'primary', 'status' => 'ACTIVE', 'organisation_account' => true]);

    CurrentCompany::set($this->a);
    $this->user->switchCompany($this->a);
});

it('gives each company\'s link only that company\'s activities', function () {
    $feedA = app(IssueCalendarFeed::class)($this->user, $this->a);

    $a = $this->get($feedA)->assertOk()->getContent();
    $b = $this->get($this->feedB)->assertOk()->getContent();

    expect($a)->toContain('Alfakunden')->not->toContain('Betakunden')
        ->and($b)->toContain('Betakunden')->not->toContain('Alfakunden');
});

it('hides B\'s links and connections, and answers 404 for B\'s activity file', function () {
    expect(CalendarFeed::query()->count())->toBe(0)
        ->and(CalendarConnection::query()->count())->toBe(0);

    $this->get(route('crm.activities.ics', $this->activityB->id))->assertNotFound();
    $this->get(route('crm.activities.ics', $this->activityA->id))->assertOk();
});

it('never writes B\'s activities to the calendar connected in A', function () {
    config(['services.google_calendar.client_id' => 'g', 'services.google_calendar.client_secret' => 's']);
    app(ModuleManager::class)->updateSettings(CrmModule::KEY, $this->a, $this->user, [...CrmSettings::for($this->a), 'calendar_sync' => 1]);
    Http::fake(['www.googleapis.com/calendar/v3/calendars/primary/events' => Http::response(['id' => 'evt-a'])]);
    $connection = CalendarConnection::create(['user_id' => $this->user->id, 'provider' => CalendarProviderKey::Google, 'access_token' => 'a-token', 'calendar_id' => 'primary', 'status' => 'ACTIVE', 'organisation_account' => true]);

    expect(app(CalendarSync::class)->pushAll($connection, true))->toBe(1);
    Http::assertSent(fn (Request $request): bool => str_contains((string) json_encode($request->data()), 'Alfakunden'));
    Http::assertNotSent(fn (Request $request): bool => str_contains((string) json_encode($request->data()), 'Betakunden'));
    expect(CalendarEvent::query()->pluck('activity_id')->all())->toBe([$this->activityA->id]);
});
