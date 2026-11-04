<?php

declare(strict_types=1);

use App\Models\AuditLog;
use App\Modules\Crm\Actions\IssueCalendarFeed;
use App\Modules\Crm\Actions\LogActivity;
use App\Modules\Crm\Actions\RevokeCalendarFeed;
use App\Modules\Crm\Actions\SetCalendarDetails;
use App\Modules\Crm\Calendar\CalendarDocument;
use App\Modules\Crm\Calendar\CalendarEntries;
use App\Modules\Crm\CrmModule;
use App\Modules\Crm\CrmSettings;
use App\Modules\Crm\Livewire\CalendarPage;
use App\Modules\Crm\Models\Activity;
use App\Modules\Crm\Models\CalendarFeed;
use App\Modules\ModuleManager;
use App\Support\Calendar\Ics;
use App\Support\Tenancy\CurrentCompany;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Laravel\Jetstream\Events\TeamMemberRemoved;
use Livewire\Livewire;
use Tests\Support\Bookkeeping;
use Tests\Support\Crm;

/*
 * CRM stage 6 (ADR-049): the subscription link. A secret token (only its
 * hash kept), minimal data by default, details only on request, RFC 5545
 * output in Stockholm time, and nothing for anyone who may not see it.
 */

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-06-15 10:00:00'));
    [$this->owner, $this->company] = Bookkeeping::company();
    CurrentCompany::set($this->company);
    $this->actingAs($this->owner);
    Crm::enable($this->company, $this->owner);

    $this->account = Crm::prospect($this->company, $this->owner, 'Bygg & Co; AB');
    $this->person = Crm::person($this->company, $this->owner, 'Anna Andersson', ['email' => 'anna@exempel.test']);
    $this->meeting = app(LogActivity::class)($this->owner, $this->company, [
        'account_id' => $this->account->id, 'person_id' => $this->person->id, 'type' => 'MEETING',
        'subject' => 'Genomgång av offerten', 'body' => 'Hemlig intern anteckning', 'due_at' => '2026-06-18T09:00',
        'duration_minutes' => 90, 'location' => 'Storgatan 1, Lund',
    ]);
});

function feedToken(string $url): string
{
    return (string) preg_replace('#^.*/kunder/kalender/([A-Za-z0-9]{48})\.ics$#', '$1', $url);
}

it('issues a link whose token is kept only as a hash, replaces the old one and audits it', function () {
    $first = app(IssueCalendarFeed::class)($this->owner, $this->company);
    $second = app(IssueCalendarFeed::class)($this->owner, $this->company);
    $token = feedToken($second);

    expect(strlen($token))->toBe(48)
        ->and(CalendarFeed::query()->whereNull('revoked_at')->count())->toBe(1)
        ->and(CalendarFeed::query()->whereNull('revoked_at')->value('token_hash'))->toBe(hash('sha256', $token))
        ->and(DB::table('crm_calendar_feeds')->where('token_hash', $token)->exists())->toBeFalse()
        ->and(AuditLog::query()->where('action', 'crm.calendar_feed_issued')->count())->toBe(2);

    $this->get($first)->assertNotFound();
    $this->get($second)->assertOk();
});

it('serves minimal events by default: type and company, a link back, no text, person or place', function () {
    $url = app(IssueCalendarFeed::class)($this->owner, $this->company);
    auth()->logout();

    $response = $this->get($url)->assertOk()
        ->assertHeader('Content-Type', 'text/calendar; charset=utf-8')
        ->assertHeader('X-Robots-Tag', 'noindex, nofollow, noarchive');
    $body = $response->getContent();

    expect($response->headers->get('Cache-Control'))->toContain('no-store')
        ->and($body)->toContain("BEGIN:VCALENDAR\r\nVERSION:2.0\r\n")
        ->and($body)->toContain('TZID:Europe/Stockholm')
        ->and($body)->toContain('UID:crm-activity-'.$this->meeting->id.'@')
        ->and($body)->toContain('DTSTART;TZID=Europe/Stockholm:20260618T090000')
        ->and($body)->toContain('DTEND;TZID=Europe/Stockholm:20260618T103000')
        ->and($body)->toContain('SUMMARY:Möte: Bygg & Co\; AB')
        ->and($body)->toContain('SEQUENCE:0')
        ->and($body)->not->toContain('Genomgång')
        ->and($body)->not->toContain('Hemlig')
        ->and($body)->not->toContain('Anna')
        ->and($body)->not->toContain('Storgatan')
        ->and($body)->toEndWith("END:VCALENDAR\r\n");

    foreach (explode("\r\n", $body) as $line) {
        expect(strlen($line))->toBeLessThanOrEqual(75);
    }
});

it('names no private person in the minimal feed', function () {
    $private = Crm::prospect($this->company, $this->owner, 'Eva Privat', ['kind' => 'PERSON']);
    app(LogActivity::class)($this->owner, $this->company, ['account_id' => $private->id, 'type' => 'CALL', 'subject' => 'Ring', 'due_at' => '2026-06-19T08:00']);

    $body = $this->get(app(IssueCalendarFeed::class)($this->owner, $this->company))->getContent();

    expect($body)->toContain('SUMMARY:Samtal: privatkund')->and($body)->not->toContain('Eva');
});

it('carries the subject, place, text and person only when the user opted in', function () {
    $url = app(IssueCalendarFeed::class)($this->owner, $this->company);
    app(SetCalendarDetails::class)($this->owner, $this->company, SetCalendarDetails::FEED, true);

    $body = str_replace("\r\n ", '', $this->get($url)->getContent());

    expect($body)->toContain('SUMMARY:Genomgång av offerten – Bygg & Co\; AB')
        ->and($body)->toContain('LOCATION:Storgatan 1\, Lund')
        ->and($body)->toContain('Person: Anna Andersson')
        ->and($body)->toContain('Hemlig intern anteckning')
        ->and(AuditLog::query()->where('action', 'crm.calendar_details_set')->value('payload'))->toMatchArray(['target' => 'feed', 'details' => true]);
});

it('gives only type and company when the company does not allow details', function () {
    $url = app(IssueCalendarFeed::class)($this->owner, $this->company, true);
    app(ModuleManager::class)->updateSettings(CrmModule::KEY, $this->company, $this->owner, [...CrmSettings::for($this->company), 'calendar_details' => 0]);

    expect($this->get($url)->getContent())->toContain('SUMMARY:Möte: Bygg & Co\; AB')->not->toContain('Genomgång')
        ->and(fn () => app(SetCalendarDetails::class)($this->owner, $this->company, SetCalendarDetails::FEED, true))->toThrow(ValidationException::class, 'tillåter inte detaljer');

    $this->get(route('crm.calendar'))->assertSee('Företaget tillåter bara typ och företag');
});

it('never names a person marked Kontakta inte, and leaves out a restricted person\'s activities', function () {
    $url = app(IssueCalendarFeed::class)($this->owner, $this->company, true);
    $this->person->forceFill(['do_not_contact_at' => now(), 'do_not_contact_reason' => 'OBJECTION'])->save();

    $body = str_replace("\r\n ", '', $this->get($url)->getContent());
    expect($body)->toContain('Genomgång av offerten')->and($body)->not->toContain('Anna')->and($body)->not->toContain('Hemlig');

    $this->person->forceFill(['do_not_contact_reason' => 'RESTRICTION'])->save();
    expect($this->get($url)->getContent())->not->toContain('BEGIN:VEVENT');
});

it('leaves out a customer with restricted processing', function () {
    $customer = Crm::customer($this->company, $this->owner, 'Begränsad AB');
    app(LogActivity::class)($this->owner, $this->company, ['account_id' => Crm::accountFor($customer)->id, 'type' => 'TASK', 'subject' => 'X', 'due_at' => '2026-06-20T08:00']);
    $customer->forceFill(['processing_restricted_at' => now()])->save();

    expect(CalendarEntries::forUser($this->company, $this->owner, false)->pluck('summary')->all())->toBe(['Möte: Bygg & Co; AB']);
});

it('drops done activities and notes, and raises the sequence when a meeting moves', function () {
    $url = app(IssueCalendarFeed::class)($this->owner, $this->company);
    app(LogActivity::class)($this->owner, $this->company, ['account_id' => $this->account->id, 'type' => 'NOTE', 'subject' => 'Anteckning']);
    app(LogActivity::class)($this->owner, $this->company, ['due_at' => '2026-06-18T13:00'], $this->meeting->fresh());

    $body = $this->get($url)->getContent();
    expect(substr_count($body, 'BEGIN:VEVENT'))->toBe(1)
        ->and($body)->toContain('DTSTART;TZID=Europe/Stockholm:20260618T130000')
        ->and($body)->toContain('SEQUENCE:1');

    $this->meeting->fresh()->forceFill(['done_at' => now()])->save();
    expect($this->get($url)->getContent())->not->toContain('BEGIN:VEVENT');
});

it('only shows the user\'s own activities', function () {
    $colleague = Bookkeeping::member($this->company, 'sales');
    CurrentCompany::set($this->company);
    app(LogActivity::class)($this->owner, $this->company, ['account_id' => $this->account->id, 'type' => 'CALL', 'subject' => 'Kollegans samtal', 'due_at' => '2026-06-19T08:00', 'assigned_to' => $colleague->id]);

    expect(CalendarEntries::forUser($this->company, $colleague, true)->pluck('summary')->all())->toBe(['Kollegans samtal – Bygg & Co; AB'])
        ->and(CalendarEntries::forUser($this->company, $this->owner, true))->toHaveCount(1);
});

it('answers 404 for a revoked link, a removed member, a user without crm:view, CRM off and an archived company', function () {
    $url = app(IssueCalendarFeed::class)($this->owner, $this->company);
    app(RevokeCalendarFeed::class)($this->owner, $this->company);
    $this->get($url)->assertNotFound();
    $this->get('/kunder/kalender/'.str_repeat('a', 48).'.ics')->assertNotFound();

    $seller = Bookkeeping::member($this->company, 'sales');
    CurrentCompany::set($this->company);
    $sellerUrl = app(IssueCalendarFeed::class)($seller, $this->company);
    $this->get($sellerUrl)->assertOk();

    $this->company->users()->updateExistingPivot($seller->id, ['role' => 'approver']);
    $this->get($sellerUrl)->assertNotFound();
    $this->company->users()->updateExistingPivot($seller->id, ['role' => 'sales']);
    $this->get($sellerUrl)->assertOk();

    app(ModuleManager::class)->disable(CrmModule::KEY, $this->company, $this->owner, true);
    $this->get($sellerUrl)->assertNotFound();
    app(ModuleManager::class)->enable(CrmModule::KEY, $this->company, $this->owner);
    $this->get($sellerUrl)->assertOk();

    $this->company->forceFill(['archived_at' => now()])->save();
    $this->get($sellerUrl)->assertNotFound();
});

it('revokes the link of a member who is removed from the company', function () {
    $seller = Bookkeeping::member($this->company, 'sales');
    CurrentCompany::set($this->company);
    app(IssueCalendarFeed::class)($seller, $this->company);

    $this->company->users()->detach($seller->id);
    event(new TeamMemberRemoved($this->company, $seller));

    expect(CalendarFeed::query()->where('user_id', $seller->id)->whereNull('revoked_at')->exists())->toBeFalse()
        ->and(AuditLog::query()->where('action', 'crm.calendar_feed_revoked')->exists())->toBeTrue();
});

it('is throttled', function () {
    $url = app(IssueCalendarFeed::class)($this->owner, $this->company);

    foreach (range(1, 30) as $ignored) {
        $this->get($url)->assertOk();
    }

    $this->get($url)->assertStatus(429);
});

it('reads the feed with a fixed number of queries', function () {
    $url = app(IssueCalendarFeed::class)($this->owner, $this->company, true);
    // The first read notes when the link was used; later reads within the hour write nothing.
    $this->get($url)->assertOk();
    $count = function () use ($url): int {
        DB::enableQueryLog();
        DB::flushQueryLog();
        $this->get($url)->assertOk();
        $queries = count(DB::getQueryLog());
        DB::disableQueryLog();

        return $queries;
    };

    $few = $count();

    foreach (range(1, 8) as $day) {
        $account = Crm::prospect($this->company, $this->owner, "Kund {$day} AB");
        $person = Crm::person($this->company, $this->owner, "Person {$day}");
        app(LogActivity::class)($this->owner, $this->company, ['account_id' => $account->id, 'person_id' => $person->id, 'type' => 'TASK', 'subject' => "Uppgift {$day}", 'due_at' => "2026-06-2{$day}T08:00"]);
    }

    expect($count())->toBe($few);
});

it('downloads one planned activity as an .ics file, and 404 for a done one', function () {
    $response = $this->get(route('crm.activities.ics', $this->meeting->id))->assertOk()
        ->assertHeader('Content-Disposition', 'attachment; filename="mote-2026-06-18.ics"');

    expect($response->getContent())->toContain('METHOD:PUBLISH')->and(str_replace("\r\n ", '', $response->getContent()))->toContain('Genomgång av offerten');

    $this->meeting->forceFill(['done_at' => now()])->save();
    $this->get(route('crm.activities.ics', $this->meeting->id))->assertNotFound();
});

it('shows the Kalender page, makes a link and hides the sync while no provider is configured', function () {
    $this->get(route('crm.calendar'))->assertOk()->assertSee('Prenumerera på kalender')->assertSee('Google Kalender')->assertDontSee('Koppla din kalender');

    Livewire::test(CalendarPage::class)
        ->call('issueFeed')
        ->assertSet('feedUrl', fn (?string $url): bool => is_string($url) && str_contains($url, '/kunder/kalender/'))
        ->assertDispatched('toast')
        ->call('setFeedDetails', true)
        ->assertSee('Detaljer visas i kalendern')
        ->call('revokeFeed')
        ->assertSee('Skapa kalenderlänk');
});

it('needs crm:view for the page and the file', function () {
    $approver = Bookkeeping::member($this->company, 'approver');
    $this->actingAs($approver);

    $this->get(route('crm.calendar'))->assertForbidden();
    $this->get(route('crm.activities.ics', $this->meeting->id))->assertNotFound();
});

it('escapes and folds RFC 5545 text, and keeps UTF-8 characters whole', function () {
    expect(Ics::text("a;b,c\\d\ne"))->toBe('a\;b\,c\\\\d\ne');

    $folded = Ics::fold('SUMMARY:'.str_repeat('å', 60));
    foreach (explode("\r\n", $folded) as $line) {
        expect(strlen($line))->toBeLessThanOrEqual(75)->and(mb_check_encoding($line, 'UTF-8'))->toBeTrue();
    }

    expect(str_replace("\r\n ", '', $folded))->toBe('SUMMARY:'.str_repeat('å', 60));
});

it('writes an invitation with organiser and attendee', function () {
    $entry = CalendarEntries::entry(Activity::query()->with(CalendarEntries::relations())->findOrFail($this->meeting->id), false);
    $ics = CalendarDocument::invitation($entry, 'Säljaren', 'saljare@exempel.test', 'Anna Andersson', 'anna@exempel.test');

    expect($ics)->toContain('METHOD:REQUEST')
        ->and(str_replace("\r\n ", '', $ics))->toContain('ORGANIZER;CN="Säljaren":mailto:saljare@exempel.test')
        ->and(str_replace("\r\n ", '', $ics))->toContain('ATTENDEE;CN="Anna Andersson";ROLE=REQ-PARTICIPANT;PARTSTAT=NEEDS-ACTION;RSVP=TRUE:mailto:anna@exempel.test');
});
