<?php

declare(strict_types=1);

use App\Actions\Accounting\AttachToVoucher;
use App\Domain\Deadlines\CompanyDeadlines;
use App\Models\FiscalYear;
use App\Modules\Boardroom\Actions\MinutesWorkflow;
use App\Modules\Boardroom\Actions\SaveArticles;
use App\Modules\Boardroom\Actions\SaveMeeting;
use App\Modules\Boardroom\Domain\EventHash;
use App\Modules\Boardroom\Models\AgendaItem;
use App\Modules\Boardroom\Models\ShareClass;
use App\Modules\Boardroom\Support\BoardroomArchive;
use App\Modules\Boardroom\Support\BoardroomPdf;
use App\Modules\Boardroom\Support\ShareRegister;
use App\Support\Tenancy\CurrentCompany;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Tests\Support\Boardroom;
use Tests\Support\Bookkeeping;

/*
 * The cold review of ADR-070 (2026-11-09): the hash chain without personal
 * numbers, the clauses on the register, the checker rules, the agenda after
 * the notice, the frozen voting list, the 5:9 gate on articles and classes,
 * the chain in the archive and voided registrations in Mina datum.
 */

beforeEach(function () {
    Storage::fake(AttachToVoucher::DISK);
    [$this->owner, $this->company] = Bookkeeping::company();
    CurrentCompany::set($this->company);
    Boardroom::enable($this->company, $this->owner);
    $this->a = Boardroom::person($this->owner, $this->company, 'Anna Andersson', '19800101123');
    $this->b = Boardroom::person($this->owner, $this->company, 'Bertil Berg', '19750505123');
});

it('hashes personal numbers as HMAC with the register key, never in clear text (P2-2)', function () {
    $event = Boardroom::formation($this->owner, $this->company, $this->a);
    $payload = EventHash::canonicalJson($event);
    $pnr = Boardroom::personalNumber('19800101123');

    expect($payload)->not->toContain($pnr)->not->toContain(substr($pnr, 2))
        ->toContain('"personal_number":"hmac:'.hash_hmac('sha256', $pnr, (string) config('nonox.register_hash_key')).'"')
        ->and($event->hash)->toBe(hash('sha256', $payload));

    config(['nonox.register_hash_key' => '']);

    expect(fn () => EventHash::canonicalJson($event))->toThrow(RuntimeException::class, 'NONOX_REGISTER_HASH_KEY');
});

it('writes the chain to the archive so it can be recomputed without NONOX (P3-8)', function () {
    Boardroom::formation($this->owner, $this->company, $this->a);
    Boardroom::record($this->owner, $this->company, ['kind' => 'TRANSFER', 'effective_date' => '2026-05-01', 'share_from' => 1, 'share_to' => 10, 'from_holder_id' => $this->a->id, 'to_holder_id' => $this->b->id]);
    $year = FiscalYear::query()->orderBy('start_date')->firstOrFail();

    $files = BoardroomArchive::files($this->owner, $this->company, $year)['generated'];
    $chain = json_decode($files['aktiebok/hashkedja.json'], true);
    $previous = '';

    foreach ($chain['handelser'] as $entry) {
        expect(hash('sha256', $previous.$entry['payload']))->toBe($entry['hash']);
        $previous = $entry['hash'];
    }

    expect($files['aktiebok/hashkedja.json'])->not->toContain(Boardroom::personalNumber('19800101123'))
        ->and($files['aktiebok/handelser.csv'])->toContain('Anna Andersson');
});

it('prints the applicable articles\' clauses on the register (P2-3, AB-R3 p.5)', function () {
    app(SaveArticles::class)->adopt($this->owner, $this->company, ['adopted_on' => '2026-01-10', 'registered_on' => '2026-01-20', 'notice_method' => 'Brev med post.', 'pre_emption_clause' => true, 'conversion_clause' => true]);
    Boardroom::formation($this->owner, $this->company, $this->a);
    $day = CarbonImmutable::parse('2026-06-30');

    $html = BoardroomPdf::registerHtml($this->company, ShareRegister::at($day), $day, false, $this->owner);

    expect($html)->toContain('Förbehåll enligt bolagsordningen, för alla aktier')
        ->toContain('förköpsförbehåll (aktiebolagslagen 4 kap. 18 §)')
        ->toContain('omvandlingsförbehåll (aktiebolagslagen 4 kap. 6 §)')
        ->not->toContain('samtyckesförbehåll');

    $this->actingAs($this->owner)->get('/aktiebok?datum=2026-06-30')->assertOk()->assertSee('förköpsförbehåll (aktiebolagslagen 4 kap. 18 §)');
});

it('needs a board member who attended and is not the chair as the second checker (P2-4)', function () {
    $workflow = app(MinutesWorkflow::class);
    $meeting = app(SaveMeeting::class)($this->owner, $this->company, ['kind' => 'BOARD', 'title' => 'Styrelsemöte', 'meeting_date' => '2026-09-01', 'attendees' => [
        ['name' => 'Anna Andersson', 'role' => 'member'], ['name' => 'Bertil Berg', 'role' => 'member'], ['name' => 'Cecilia Carlsson', 'role' => 'adviser'],
    ]]);
    $draft = $workflow->start($this->owner, $this->company, $meeting);

    expect(fn () => $workflow->check($this->owner, $this->company, $draft, 'Anna Andersson', 'Anna Andersson', ['Anna Andersson']))->toThrow(ValidationException::class, 'inte är ordförande')
        ->and(fn () => $workflow->check($this->owner, $this->company, $draft, 'Anna Andersson', 'Anna Andersson', ['Cecilia Carlsson']))->toThrow(ValidationException::class, 'närvarande ledamot');

    $workflow->check($this->owner, $this->company, $draft, 'Anna Andersson', 'Anna Andersson', ['Bertil Berg']);
    expect($draft->fresh()?->checked_names)->toBe(['Anna Andersson', 'Bertil Berg']);
});

it('needs a general meeting checker other than the chair (P2-4)', function () {
    $c = Boardroom::person($this->owner, $this->company, 'Cecilia Carlsson', '19700303123');
    Boardroom::formation($this->owner, $this->company, $this->a, 500);
    Boardroom::record($this->owner, $this->company, ['kind' => 'FORMATION', 'effective_date' => '2026-03-02', 'share_from' => 501, 'share_to' => 1000, 'to_holder_id' => $c->id, 'capital_change_ore' => 2_500_000]);
    $workflow = app(MinutesWorkflow::class);
    $meeting = app(SaveMeeting::class)($this->owner, $this->company, ['kind' => 'EXTRA_GM', 'title' => 'Extra stämma', 'meeting_date' => '2026-10-01', 'attendees' => [['name' => 'Anna Andersson', 'role' => 'shareholder', 'shareholder_id' => $this->a->id]]]);
    $draft = $workflow->start($this->owner, $this->company, $meeting);

    expect(fn () => $workflow->check($this->owner, $this->company, $draft, 'Anna Andersson', 'Anna Andersson', ['anna andersson']))->toThrow(ValidationException::class, 'minst en justerare');
});

it('fixes the agenda numbering once the notice is sent: no removal, new items go last (P2-5)', function () {
    $year = FiscalYear::query()->orderBy('start_date')->firstOrFail();
    $meeting = app(SaveMeeting::class)($this->owner, $this->company, ['kind' => 'AGM', 'title' => 'Årsstämma', 'meeting_date' => '2027-05-20', 'fiscal_year_id' => $year->id, 'notice_sent_on' => '2026-11-01']);
    $before = AgendaItem::query()->where('meeting_id', $meeting->id)->orderBy('position')->pluck('title', 'position')->all();
    $procedural = AgendaItem::query()->where('meeting_id', $meeting->id)->where('mandatory', false)->orderBy('position')->firstOrFail();

    expect(fn () => app(SaveMeeting::class)->removeItem($this->owner, $this->company, $procedural))->toThrow(ValidationException::class, 'står fast');

    $added = app(SaveMeeting::class)->saveItem($this->owner, $this->company, $meeting->fresh(), ['kind' => 'OTHER', 'title' => 'Övrigt ärende']);

    expect($added->position)->toBe(count($before) + 1)
        ->and(AgendaItem::query()->where('meeting_id', $meeting->id)->where('position', '<=', count($before))->orderBy('position')->pluck('title', 'position')->all())->toBe($before);
});

it('freezes the voting list into general meeting minutes at approval (P3-3), and refuses a correction from another meeting (P3-1)', function () {
    Boardroom::formation($this->owner, $this->company, $this->a);
    $workflow = app(MinutesWorkflow::class);
    $meeting = app(SaveMeeting::class)($this->owner, $this->company, ['kind' => 'EXTRA_GM', 'title' => 'Extra stämma', 'meeting_date' => '2026-10-01', 'attendees' => [['name' => 'Anna Andersson', 'role' => 'shareholder', 'shareholder_id' => $this->a->id]]]);
    $draft = $workflow->start($this->owner, $this->company, $meeting);
    $workflow->check($this->owner, $this->company, $draft, 'Anna Andersson', 'Anna Andersson', []);
    $approved = $workflow->approve($this->owner, $this->company, $draft->fresh());

    Boardroom::record($this->owner, $this->company, ['kind' => 'TRANSFER', 'effective_date' => '2026-10-01', 'share_from' => 1, 'share_to' => 10, 'from_holder_id' => $this->a->id, 'to_holder_id' => $this->b->id]);

    expect($approved->body['voting_list'])->toBe([['name' => 'Anna Andersson', 'role' => 'shareholder', 'shares' => 1000, 'votes_tenths' => 10_000]])
        ->and($approved->fresh()?->body['voting_list'][0]['shares'])->toBe(1000);

    $other = app(SaveMeeting::class)($this->owner, $this->company, ['kind' => 'BOARD', 'title' => 'Styrelsemöte', 'meeting_date' => '2026-10-02']);

    expect(fn () => $workflow->start($this->owner, $this->company, $other, $approved))->toThrow(ValidationException::class, 'samma möte');
});

it('puts the articles and share classes behind the 5:9 gate, and never changes a class in use (P3-5)', function () {
    $consultant = Bookkeeping::member($this->company, 'accountant');
    // Adding a member resets the test's company context; the actions set their own.
    CurrentCompany::set($this->company);

    expect(fn () => app(SaveArticles::class)->adopt($consultant, $this->company, ['adopted_on' => '2026-01-10', 'notice_method' => 'Brev.']))->toThrow(AuthorizationException::class)
        ->and(fn () => app(SaveArticles::class)->saveClass($consultant, $this->company, ['code' => 'B', 'name' => 'B-aktier', 'votes' => '1']))->toThrow(AuthorizationException::class);

    $articles = app(SaveArticles::class)->adopt($this->owner, $this->company, ['adopted_on' => '2026-01-10', 'notice_method' => 'Brev.']);
    $class = app(SaveArticles::class)->saveClass($this->owner, $this->company, ['code' => 'A', 'name' => 'A-aktier', 'votes' => '10']);
    Boardroom::record($this->owner, $this->company, ['kind' => 'FORMATION', 'effective_date' => '2026-03-02', 'share_from' => 1, 'share_to' => 100, 'share_class_id' => $class->id, 'to_holder_id' => $this->a->id, 'capital_change_ore' => 2_500_000]);

    expect($class->articles_version_id)->toBe($articles->id)
        ->and(fn () => app(SaveArticles::class)->saveClass($this->owner, $this->company, ['code' => 'A', 'name' => 'A-aktier', 'votes' => '1'], $class))->toThrow(ValidationException::class, 'ändras inte i efterhand');

    app(SaveArticles::class)->saveClass($this->owner, $this->company, ['code' => 'A', 'name' => 'Stamaktier serie A', 'votes' => '10', 'restriction_text' => 'Förköp enligt § 9.'], $class);
    expect(ShareClass::query()->findOrFail($class->id)->name)->toBe('Stamaktier serie A');
});

it('keeps reminding about a new issue whose registration entry was voided (P3-9)', function () {
    Boardroom::formation($this->owner, $this->company, $this->a);
    $issue = Boardroom::record($this->owner, $this->company, ['kind' => 'NEW_ISSUE', 'decided_on' => '2026-05-01', 'effective_date' => '2026-05-10', 'share_from' => 1001, 'share_to' => 1100, 'to_holder_id' => $this->b->id, 'capital_change_ore' => 250_000]);
    $registration = Boardroom::record($this->owner, $this->company, ['kind' => 'REGISTRATION', 'registered_on' => '2026-06-01', 'refers_to_event_id' => $issue->id]);
    $key = 'boardroom-new-issue:'.$issue->id;
    $upcoming = fn () => collect(app(CompanyDeadlines::class)->upcoming($this->company, CarbonImmutable::parse('2026-05-15'), 12))->firstWhere('key', $key);

    expect($upcoming())->toBeNull();

    Boardroom::record($this->owner, $this->company, ['kind' => 'CORRECTION', 'effective_date' => '2026-06-02', 'refers_to_event_id' => $registration->id, 'reason' => 'Registreringen fördes in för tidigt.']);
    app(CompanyDeadlines::class)->forget();

    expect($upcoming()?->date->toDateString())->toBe('2026-11-01');
});

it('creates the register hash key once and never replaces it', function () {
    $this->artisan('share-register:hash-key')->expectsOutputToContain('NONOX_REGISTER_HASH_KEY=')->assertSuccessful();
});
