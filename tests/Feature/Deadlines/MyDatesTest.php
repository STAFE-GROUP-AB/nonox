<?php

declare(strict_types=1);

use App\Actions\Guide\IssueDeadlineFeed;
use App\Console\Commands\SendDeadlineRemindersCommand;
use App\Domain\Company\LegalForm;
use App\Domain\Company\VatPeriod;
use App\Domain\Deadlines\Deadline;
use App\Domain\Deadlines\DeadlineType;
use App\Domain\Overview\Todos\DeadlineTodos;
use App\Events\UserAccountDeleting;
use App\Http\Controllers\DeadlineFeedController;
use App\Livewire\Guide\MyDates;
use App\Models\AuditLog;
use App\Models\DeadlineCalendarFeed;
use App\Models\DeadlineReminder;
use App\Models\FiscalYear;
use App\Models\User;
use App\Notifications\DeadlineReminder as DeadlineReminderNotice;
use App\Support\Tenancy\CompanyScope;
use App\Support\Tenancy\CurrentCompany;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Laravel\Jetstream\Contracts\RemovesTeamMembers;
use Livewire\Livewire;
use Tests\Support\Bookkeeping;

/*
 * Mina datum (/foretagsguiden/mina-datum, ADR-051 stage 2): the page, the
 * calendar link and its feed, the reminders, Att göra and Översikt.
 */

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-11-04 10:00:00'));
    [$this->owner, $this->company] = Bookkeeping::company();
    $this->company->forceFill(['name' => 'Kafé Linden AB', 'legal_form' => LegalForm::Ab, 'vat_registered' => true, 'vat_period' => VatPeriod::Quarterly, 'turnover_over_40_msek' => false])->save();
    CurrentCompany::set($this->company);
});

it('shows the dates by month, why they apply, the days left, links and the notices', function () {
    $this->actingAs($this->owner)->get(route('guide.dates'))->assertOk()
        ->assertSee('Mina datum')
        ->assertSee('November 2026')
        ->assertSee('Deklarera och betala moms för kvartal 3 2026')
        ->assertSee('Ni redovisar moms varje kvartal.')
        ->assertSee('Om 8 dagar')
        ->assertSee('Gör det i NONOX')
        ->assertSee(route('vat.index'), false)
        ->assertSee('Datum i lagen')
        ->assertSee('Rekommendation')
        ->assertSee('Datumen räknas fram ur företagets inställningar')
        ->assertSee('Företagsguiden är i beta.')
        ->assertSee('Lägg till i din kalender');
});

it('needs a signed-in user with a company, and keeps its address out of the public guide', function () {
    $this->get(route('guide.dates'))->assertRedirect(route('login'));
    $this->actingAs(User::factory()->create())->get(route('guide.dates'))->assertRedirect(route('onboarding'));

    config(['app.landing.enabled' => true]);
    $this->get('/foretagsguiden/mina-datum/oversikt')->assertNotFound();
    $this->get('/foretagsguiden/kalender')->assertNotFound();
});

it('makes a calendar link that is shown once, replaces the old one, and can be stopped', function () {
    $this->actingAs($this->owner);

    $page = Livewire::test(MyDates::class)->call('issueLink')
        ->assertDispatched('toast', message: 'Kalenderlänken är skapad. Kopiera den nu, den visas bara en gång.');
    $first = $page->get('newLink');

    expect($first)->toStartWith(url('/foretagsguiden/kalender/'))->toEndWith('.ics')
        ->and(DeadlineCalendarFeed::query()->sole()->token_hash)->toBe(DeadlineCalendarFeed::hash(basename($first, '.ics')))
        ->and(AuditLog::query()->where('action', 'guide.calendar_feed_issued')->count())->toBe(1);

    $this->get($first)->assertOk();
    $second = Livewire::test(MyDates::class)->call('issueLink')->get('newLink');
    $this->get($first)->assertNotFound();
    $this->get($second)->assertOk();

    Livewire::test(MyDates::class)->assertSee('Du har en kalenderlänk sedan 2026-11-04.')->assertDontSee($second)
        ->call('revokeLink')->assertDispatched('toast', message: 'Kalenderlänken är avstängd. Kalendern hämtar inga fler datum.');
    $this->get($second)->assertNotFound();
});

it('serves the dates as all-day events with stable ids and alarms a week and a day before', function () {
    $link = app(IssueDeadlineFeed::class)($this->owner, $this->company);
    $response = $this->get($link)->assertOk()
        ->assertHeader('Content-Type', 'text/calendar; charset=utf-8')
        ->assertHeader('X-Robots-Tag', 'noindex, nofollow, noarchive');
    $ics = str_replace("\r\n ", '', (string) $response->getContent());

    expect($ics)->toContain('BEGIN:VCALENDAR')
        ->toContain('X-WR-CALNAME:Mina datum – Kafé Linden AB')
        ->toContain('UID:deadline-'.$this->company->id.'-vat-2026-09-30@'.parse_url(url('/'), PHP_URL_HOST))
        ->toContain('DTSTART;VALUE=DATE:20261112')
        ->toContain('DTEND;VALUE=DATE:20261113')
        ->toContain('SUMMARY:Moms: Deklarera och betala moms för kvartal 3 2026')
        ->toContain('TRIGGER:-P7D')
        ->toContain('TRIGGER:-P1D')
        ->not->toContain($this->owner->email)
        ->not->toContain($this->owner->name);

    expect(substr_count($ics, 'BEGIN:VEVENT'))->toBe(substr_count($ics, 'END:VEVENT'))
        ->and(substr_count($ics, 'BEGIN:VALARM'))->toBe(2 * substr_count($ics, 'BEGIN:VEVENT'));

    // The same deadline keeps its UID when fetched again.
    expect(str_replace("\r\n ", '', (string) $this->get($link)->getContent()))->toContain('UID:deadline-'.$this->company->id.'-vat-2026-09-30@');
});

it('answers 404 to a wrong link, a revoked one, an archived company, a guide turned off and a user who left', function () {
    $this->get('/foretagsguiden/kalender/'.str_repeat('a', 48).'.ics')->assertNotFound();

    $member = Bookkeeping::member($this->company, 'bookkeeper');
    $link = app(IssueDeadlineFeed::class)($member, $this->company->fresh());
    $this->get($link)->assertOk();

    $this->company->forceFill(['guide_enabled' => false])->save();
    $this->get($link)->assertNotFound();
    $this->company->forceFill(['guide_enabled' => true])->save();

    $this->company->forceFill(['archived_at' => now()])->save();
    $this->get($link)->assertNotFound();
    $this->company->forceFill(['archived_at' => null])->save();

    $this->company->users()->detach($member);
    $this->get($link)->assertNotFound();
});

it('stops a member\'s link when they are removed from the company, and forgets it when the account is deleted', function () {
    $member = Bookkeeping::member($this->company, 'admin');
    $link = app(IssueDeadlineFeed::class)($member, $this->company->fresh());

    app(RemovesTeamMembers::class)->remove($this->owner, $this->company->fresh(), $member);
    $this->company->users()->attach($member, ['role' => 'admin']);

    $this->get($link)->assertNotFound();

    event(new UserAccountDeleting($member->id));
    expect(CompanyScope::bypass(fn () => DeadlineCalendarFeed::query()->where('user_id', $member->id)->count()))->toBe(0);
});

it('throttles links that lead nowhere', function () {
    foreach (range(1, DeadlineFeedLimits::misses()) as $attempt) {
        $this->get('/foretagsguiden/kalender/'.str_repeat('b', 48).'.ics')->assertNotFound();
    }

    $this->get('/foretagsguiden/kalender/'.str_repeat('b', 48).'.ics')->assertStatus(429);
});

it('reminds the owner and those who look after the company a week and a day before, once', function () {
    $bookkeeper = Bookkeeping::member($this->company, 'bookkeeper');
    $accountant = Bookkeeping::member($this->company, 'accountant');

    // 12 November is the VAT date: 5 November is 7 days before.
    $this->travelTo(CarbonImmutable::parse('2026-11-05 06:50:00'));
    $this->artisan(SendDeadlineRemindersCommand::class)->assertSuccessful();
    $this->artisan(SendDeadlineRemindersCommand::class)->assertSuccessful();

    $notices = fn (User $user): array => $user->fresh()->notifications()->where('type', DeadlineReminderNotice::class)->pluck('data')
        ->filter(fn ($data): bool => str_starts_with($data['deadline_key'], 'vat:'))->map(fn ($data): string => $data['title'])->values()->all();
    $vatReminders = fn (): int => CompanyScope::bypass(fn (): int => DeadlineReminder::query()->where('deadline_key', 'like', 'vat:%')->count());

    expect($notices($this->owner))->toBe(['Moms: Deklarera och betala moms för kvartal 3 2026. Sista dagen är om 7 dagar, 12 november. Betala i god tid: en betalning räknas när den har kommit in på skattekontot. Beräknat från företagets inställningar.'])
        ->and($notices($accountant))->toHaveCount(1)
        ->and($notices($bookkeeper))->toBe([])
        ->and($vatReminders())->toBe(2);

    $this->travelTo(CarbonImmutable::parse('2026-11-11 06:50:00'));
    $this->artisan(SendDeadlineRemindersCommand::class)->assertSuccessful();

    expect($notices($this->owner))->toContain('Moms: Deklarera och betala moms för kvartal 3 2026. Sista dagen är i morgon, 12 november. Betala i god tid: en betalning räknas när den har kommit in på skattekontot. Beräknat från företagets inställningar.')
        ->and($notices($this->owner))->toHaveCount(2);

    $this->company->forceFill(['guide_enabled' => false])->save();
    $this->travelTo(CarbonImmutable::parse('2027-02-05 06:50:00'));
    $this->artisan(SendDeadlineRemindersCommand::class)->assertSuccessful();
    expect($notices($this->owner))->toHaveCount(2);
});

it('sends a missed reminder late, with the days that are left, and never the week reminder after the day one', function () {
    $this->travelTo(CarbonImmutable::parse('2026-11-09 06:50:00'));
    $this->artisan(SendDeadlineRemindersCommand::class)->assertSuccessful();
    $this->travelTo(CarbonImmutable::parse('2026-11-11 06:50:00'));
    $this->artisan(SendDeadlineRemindersCommand::class)->assertSuccessful();
    $this->artisan(SendDeadlineRemindersCommand::class)->assertSuccessful();

    expect($this->owner->fresh()->notifications()->pluck('data')->filter(fn ($data): bool => str_starts_with($data['deadline_key'], 'vat:'))->map(fn ($data): string => $data['title'])->values()->all())->toEqualCanonicalizing([
        'Moms: Deklarera och betala moms för kvartal 3 2026. Sista dagen är om 3 dagar, 12 november. Betala i god tid: en betalning räknas när den har kommit in på skattekontot. Beräknat från företagets inställningar.',
        'Moms: Deklarera och betala moms för kvartal 3 2026. Sista dagen är i morgon, 12 november. Betala i god tid: en betalning räknas när den har kommit in på skattekontot. Beräknat från företagets inställningar.',
    ]);
});

it('puts dates within two weeks under Att göra, except VAT and the employer declaration, which have their own', function () {
    $this->company->forceFill(['employer_registered' => true])->save();
    $todos = fn (CarbonImmutable $today, ?User $user = null): array => array_map(
        fn ($todo): string => $todo->key,
        app(DeadlineTodos::class)->todos($user ?? $this->owner, $this->company->refresh(), $today),
    );

    // 20 March 2027: the closing advice (31 March) is near; VAT for February/Q1 and AGI are left to their own items.
    expect($todos(CarbonImmutable::parse('2027-03-20')))->toContain('deadline:closing:2026-12-31')
        ->not->toContain('deadline:vat:2026-12-31')
        ->and(array_filter($todos(CarbonImmutable::parse('2027-03-20')), fn (string $key): bool => str_starts_with($key, 'deadline:agi')))->toBe([])
        ->and($todos(CarbonImmutable::parse('2027-03-20'), Bookkeeping::member($this->company, 'bookkeeper')))->toBe([]);

    $this->company->forceFill(['guide_enabled' => false])->save();
    expect($todos(CarbonImmutable::parse('2027-03-20')))->toBe([]);
});

it('reminds 30 days before the income tax return and the annual report, and never of a recommendation', function () {
    // 3 July 2027: the income tax return (2 August) is 30 days away, the annual report limit (31 July) 28.
    $this->travelTo(CarbonImmutable::parse('2027-07-03 06:50:00'));
    $this->artisan(SendDeadlineRemindersCommand::class)->assertSuccessful();

    $keys = $this->owner->fresh()->notifications()->pluck('data')->map(fn ($data): string => $data['deadline_key'])->all();

    expect($keys)->toContain('annual_report:2026-12-31', 'income_tax_return:2026-12-31')
        ->not->toContain('closing:2026-12-31')
        ->and(SendDeadlineRemindersCommand::due(
            new Deadline('closing:x', DeadlineType::Closing, 'Gör bokslutet', CarbonImmutable::parse('2027-07-02'), '2026', '', isRecommendation: true),
            CarbonImmutable::parse('2027-07-01'),
        ))->toBeNull();
});

it('shows the next dates on Översikt with a fixed number of queries', function () {
    $count = function (): int {
        DB::flushQueryLog();
        DB::enableQueryLog();
        $this->actingAs($this->owner->fresh())->get(route('dashboard'))->assertOk()->assertSee('Nästa datum')->assertSee('Deklarera och betala moms för kvartal 3 2026');

        return count(DB::getQueryLog());
    };

    $count();
    $short = $count();
    FiscalYear::create(['start_date' => '2027-01-01', 'end_date' => '2027-12-31']);
    FiscalYear::create(['start_date' => '2028-01-01', 'end_date' => '2028-12-31']);
    $count();

    expect($count())->toBe($short);

    $this->company->forceFill(['guide_enabled' => false])->save();
    $this->actingAs($this->owner->fresh())->get(route('dashboard'))->assertOk()->assertDontSee('data-next-dates', false);
});

it('links Mina datum from Min startplan and the app menu', function () {
    $this->actingAs($this->owner)->get(route('guide.plan'))->assertOk()->assertSee(route('guide.dates'), false);
    $this->actingAs($this->owner)->get(route('dashboard'))->assertOk()->assertSee('data-guide-dates-link', false);
});

/** The feed's miss limit, without repeating the number. */
final class DeadlineFeedLimits
{
    public static function misses(): int
    {
        return DeadlineFeedController::MISSES_PER_MINUTE;
    }
}
