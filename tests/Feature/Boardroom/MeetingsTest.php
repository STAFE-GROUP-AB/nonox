<?php

declare(strict_types=1);

use App\Actions\Accounting\AttachToVoucher;
use App\Domain\Deadlines\CompanyDeadlines;
use App\Domain\Deadlines\DeadlineCategory;
use App\Models\FiscalYear;
use App\Modules\Boardroom\Actions\MinutesWorkflow;
use App\Modules\Boardroom\Actions\SaveArticles;
use App\Modules\Boardroom\Actions\SaveMeeting;
use App\Modules\Boardroom\Domain\AgendaItemKind;
use App\Modules\Boardroom\Domain\BoardroomRules;
use App\Modules\Boardroom\Domain\MinutesStatus;
use App\Modules\Boardroom\Domain\NoticeWindow;
use App\Modules\Boardroom\Models\AgendaItem;
use App\Modules\Boardroom\Models\ArticlesVersion;
use App\Modules\Boardroom\Models\Meeting;
use App\Modules\Boardroom\Models\Minutes;
use App\Modules\Boardroom\Support\BoardroomDeadlines;
use App\Support\Tenancy\CurrentCompany;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Tests\Support\Boardroom;
use Tests\Support\Bookkeeping;

/*
 * Meetings and minutes (ADR-070 §4–5 and addendum, rule sheet AB-R17–AB-R43):
 * the annual general meeting's agenda from the closing, notice dates from
 * rules.json as advice, minutes from draft through justerat to "Godkänt i
 * NONOX" with a locked PDF and its SHA-256, corrections as new linked
 * minutes, and Bolagsverket reminders in Mina datum. NONOX never files.
 */

beforeEach(function () {
    Storage::fake(AttachToVoucher::DISK);
    [$this->owner, $this->company] = Bookkeeping::company();
    CurrentCompany::set($this->company);
    Boardroom::enable($this->company, $this->owner);
    $this->year = FiscalYear::query()->orderBy('start_date')->firstOrFail();
});

function meeting(array $overrides = []): Meeting
{
    return app(SaveMeeting::class)(test()->owner, test()->company, [
        'kind' => 'AGM', 'title' => 'Årsstämma', 'meeting_date' => '2027-05-20', 'place' => 'Bolagets kontor',
        'fiscal_year_id' => test()->year->id, ...$overrides,
    ]);
}

it('pre-fills the annual meeting from the closing with the three mandatory decisions in order (7 kap. 11 §)', function () {
    Bookkeeping::post($this->owner, $this->company, 10_000_000, 1930, 3001, '2026-07-01');
    $meeting = meeting();
    $items = AgendaItem::query()->where('meeting_id', $meeting->id)->orderBy('position')->get();
    $mandatory = $items->where('mandatory', true)->values();

    expect($mandatory->map(fn (AgendaItem $item) => $item->kind)->all())->toBe([AgendaItemKind::Adoption, AgendaItemKind::Allocation, AgendaItemKind::Discharge])
        ->and($items->pluck('position')->all())->toBe(range(1, $items->count()))
        ->and($mandatory[1]->proposal)->toContain('Årets resultat enligt bokföringen')
        ->and($items->last()->title)->toBe('Stämmans avslutande');

    expect(fn () => app(SaveMeeting::class)->removeItem($this->owner, $this->company, $mandatory[0]))->toThrow(ValidationException::class);

    $added = app(SaveMeeting::class)->saveItem($this->owner, $this->company, $meeting, ['kind' => 'ELECTION_BOARD', 'title' => 'Val av styrelse']);

    expect($added->position)->toBe($items->count())
        ->and($added->reportable?->value)->toBe('BOARD_CHANGE')
        ->and(AgendaItem::query()->where('meeting_id', $meeting->id)->orderByDesc('position')->first()?->title)->toBe('Stämmans avslutande');
});

it('needs the fiscal year for an annual meeting', function () {
    expect(fn () => meeting(['fiscal_year_id' => null]))->toThrow(ValidationException::class);
});

it('gives the notice window from rules.json as unverified advice (AB-R20–AB-R22, AB-R25)', function () {
    $agm = meeting();
    $window = NoticeWindow::for($agm, AgendaItem::query()->where('meeting_id', $agm->id)->get(), null);

    expect($window->earliest->toDateString())->toBe('2027-04-08')
        ->and($window->latest->toDateString())->toBe('2027-04-22')
        ->and($window->itemRequestDeadline()->toDateString())->toBe('2027-04-01')
        ->and($window->verified)->toBeFalse()
        ->and($window->judge(CarbonImmutable::parse('2027-04-07')))->toBe('för tidigt')
        ->and($window->judge(CarbonImmutable::parse('2027-04-23')))->toBe('för sent')
        ->and($window->judge(CarbonImmutable::parse('2027-04-15')))->toBeNull();

    $articles = app(SaveArticles::class)->adopt($this->owner, $this->company, ['adopted_on' => '2026-01-10', 'registered_on' => '2026-01-20', 'notice_method' => 'Kallelse sker med e-post till aktieägarna.', 'short_notice_clause' => true]);
    expect(NoticeWindow::for($agm, [], $articles)->latest->toDateString())->toBe('2027-05-06');

    $extra = meeting(['kind' => 'EXTRA_GM', 'title' => 'Extra stämma', 'meeting_date' => '2027-09-15', 'fiscal_year_id' => null]);
    $other = NoticeWindow::for($extra, AgendaItem::query()->where('meeting_id', $extra->id)->get(), null);
    expect([$other->earliest->toDateString(), $other->latest->toDateString(), $other->postRequired])->toBe(['2027-08-04', '2027-09-01', false]);

    $item = app(SaveMeeting::class)->saveItem($this->owner, $this->company, $extra, ['kind' => 'ARTICLES', 'title' => 'Ändring av bolagsordningen']);
    $changing = NoticeWindow::for($extra, AgendaItem::query()->where('meeting_id', $extra->id)->get(), null);
    expect([$changing->latest->toDateString(), $changing->postRequired, $item->reportable?->value])->toBe(['2027-08-18', true, 'ARTICLES_CHANGE']);
});

it('keeps every rule value unverified until the lawyer has answered', function () {
    foreach (BoardroomRules::all() as $key => $rule) {
        if (is_array($rule)) {
            expect($rule['verified'] ?? null)->toBeFalse("{$key} is marked verified")
                ->and($rule['source'] ?? '')->not->toBe('', "{$key} has no source");
        }
    }
});

it('shows the notice dates as advice to check against the law', function () {
    $meeting = meeting();

    $this->actingAs($this->owner)->get(route('boardroom.meetings.show', $meeting))
        ->assertOk()
        ->assertSee('Skicka kallelsen tidigast 2027-04-08 och senast 2027-04-22')
        ->assertSee('kontrollera mot aktiebolagslagen')
        ->assertSee('NONOX skickar inte kallelsen.');
});

it('takes minutes from draft through justerat to Godkänt i NONOX, locked with a PDF and its SHA-256', function () {
    $holder = Boardroom::person($this->owner, $this->company);
    Boardroom::formation($this->owner, $this->company, $holder);
    $meeting = meeting(['meeting_date' => '2026-10-01', 'attendees' => [['name' => 'Anna Andersson', 'role' => 'shareholder', 'shareholder_id' => $holder->id]]]);
    $workflow = app(MinutesWorkflow::class);

    $minutes = $workflow->start($this->owner, $this->company, $meeting);
    $workflow->update($this->owner, $this->company, $minutes, ['decisions' => [8 => 'Stämman fastställde resultaträkningen och balansräkningen.'], 'notes' => 'Inga övriga frågor.']);

    expect(fn () => $workflow->approve($this->owner, $this->company, $minutes->fresh()))->toThrow(ValidationException::class);

    // The sole owner is both chair and minute-taker: no adjuster is needed (7 kap. 48 §).
    $workflow->check($this->owner, $this->company, $minutes->fresh(), 'Anna Andersson', 'Anna Andersson', []);
    $approved = $workflow->approve($this->owner, $this->company, $minutes->fresh());

    $pdf = (string) Storage::disk(AttachToVoucher::DISK)->get((string) $approved->pdf_path);

    expect($approved->status)->toBe(MinutesStatus::Approved)
        ->and($approved->status->label())->toBe('Godkänt i NONOX')
        ->and($approved->approved_by)->toBe($this->owner->id)
        ->and($approved->number)->toBeNull()
        ->and($approved->sha256)->toBe(hash('sha256', $pdf))
        ->and(str_starts_with($pdf, '%PDF'))->toBeTrue()
        ->and(collect($approved->body['items'])->firstWhere('position', 8)['decision'])->toBe('Stämman fastställde resultaträkningen och balansräkningen.');

    expect(fn () => $approved->update(['body' => []]))->toThrow(LogicException::class)
        ->and(fn () => DB::transaction(fn () => DB::table('boardroom_minutes')->where('id', $approved->id)->update(['sha256' => str_repeat('0', 64)])))->toThrow(QueryException::class)
        ->and(fn () => DB::transaction(fn () => DB::table('boardroom_minutes')->where('id', $approved->id)->delete()))->toThrow(QueryException::class)
        ->and(fn () => app(SaveMeeting::class)($this->owner, $this->company, ['title' => 'Ändrad', 'meeting_date' => '2026-10-01'], $meeting))->toThrow(ValidationException::class);

    $this->actingAs($this->owner)->get(route('boardroom.minutes.pdf', $approved->id))->assertOk()->assertHeader('Content-Type', 'application/pdf');
    $this->actingAs($this->owner)->get(route('boardroom.meetings.show', $meeting))->assertOk()->assertSee('Godkänt i NONOX')->assertDontSee('Signerat');
});

it('requires an adjuster unless the chair and the minute-taker represent every share (AB-R32)', function () {
    $a = Boardroom::person($this->owner, $this->company, 'Anna Andersson', '19800101123');
    $b = Boardroom::person($this->owner, $this->company, 'Bertil Berg', '19750505123');
    $c = Boardroom::person($this->owner, $this->company, 'Cecilia Carlsson', '19700303123');
    Boardroom::formation($this->owner, $this->company, $a, 500);
    Boardroom::record($this->owner, $this->company, ['kind' => 'FORMATION', 'effective_date' => '2026-03-02', 'share_from' => 501, 'share_to' => 1000, 'to_holder_id' => $b->id, 'capital_change_ore' => 2_500_000]);
    $attendees = [['name' => 'Anna Andersson', 'role' => 'shareholder', 'shareholder_id' => $a->id], ['name' => 'Bertil Berg', 'role' => 'shareholder', 'shareholder_id' => $b->id]];
    $workflow = app(MinutesWorkflow::class);

    // 50/50: chair A and minute-taker B together hold every share.
    $even = meeting(['meeting_date' => '2026-10-01', 'attendees' => $attendees]);
    $workflow->check($this->owner, $this->company, $workflow->start($this->owner, $this->company, $even), 'Anna Andersson', 'Bertil Berg', []);

    // After a transfer A and B hold 60 %: one adjuster is required.
    Boardroom::record($this->owner, $this->company, ['kind' => 'TRANSFER', 'effective_date' => '2026-10-15', 'share_from' => 1, 'share_to' => 400, 'from_holder_id' => $a->id, 'to_holder_id' => $c->id]);
    $uneven = meeting(['meeting_date' => '2026-11-01', 'attendees' => $attendees]);
    $draft = $workflow->start($this->owner, $this->company, $uneven);

    expect(fn () => $workflow->check($this->owner, $this->company, $draft, 'Anna Andersson', 'Bertil Berg', []))->toThrow(ValidationException::class, 'minst en justerare');

    $workflow->check($this->owner, $this->company, $draft->fresh(), 'Anna Andersson', 'Bertil Berg', ['Cecilia Carlsson']);
    expect($draft->fresh()?->checked_names)->toBe(['Anna Andersson', 'Cecilia Carlsson']);
});

it('numbers board minutes in order on approval; a discarded draft uses no number (8 kap. 24 och 26 §§)', function () {
    $workflow = app(MinutesWorkflow::class);
    $board = fn (string $date, array $members) => meeting(['kind' => 'BOARD', 'title' => 'Styrelsemöte', 'meeting_date' => $date, 'fiscal_year_id' => null, 'attendees' => array_map(fn ($name) => ['name' => $name, 'role' => 'member'], $members)]);
    $approve = function (Meeting $meeting, array $adjusters) use ($workflow): Minutes {
        $draft = $workflow->start($this->owner, $this->company, $meeting);
        $workflow->check($this->owner, $this->company, $draft, 'Anna Andersson', 'Anna Andersson', $adjusters);

        return $workflow->approve($this->owner, $this->company, $draft->fresh());
    };

    $one = $approve($board('2026-09-01', ['Anna Andersson']), []);

    $two = $board('2026-09-15', ['Anna Andersson', 'Bertil Berg']);
    $discarded = $workflow->start($this->owner, $this->company, $two);
    expect(fn () => $workflow->check($this->owner, $this->company, $discarded, 'Anna Andersson', 'Anna Andersson', []))->toThrow(ValidationException::class, 'flera ledamöter');
    $workflow->discard($this->owner, $this->company, $discarded->fresh());

    $second = $approve($two, ['Bertil Berg']);
    $third = $approve($board('2026-10-01', ['Anna Andersson']), []);

    expect([$one->number, $second->number, $third->number])->toBe([1, 2, 3]);
});

it('corrects approved minutes with new minutes that name them, keeping the original', function () {
    $workflow = app(MinutesWorkflow::class);
    $meeting = meeting(['kind' => 'BOARD', 'title' => 'Styrelsemöte', 'meeting_date' => '2026-09-01', 'fiscal_year_id' => null]);
    $draft = $workflow->start($this->owner, $this->company, $meeting);
    $workflow->check($this->owner, $this->company, $draft, 'Anna Andersson', 'Anna Andersson', []);
    $original = $workflow->approve($this->owner, $this->company, $draft->fresh());

    expect(fn () => $workflow->start($this->owner, $this->company, $meeting))->toThrow(ValidationException::class);

    $correction = $workflow->start($this->owner, $this->company, $meeting, $original);
    $workflow->update($this->owner, $this->company, $correction, ['notes' => 'Rättelse: fel datum i § 4.']);
    $workflow->check($this->owner, $this->company, $correction->fresh(), 'Anna Andersson', 'Anna Andersson', []);
    $approved = $workflow->approve($this->owner, $this->company, $correction->fresh());

    expect($approved->corrects_minutes_id)->toBe($original->id)
        ->and($approved->number)->toBe(2)
        ->and($original->fresh()?->sha256)->toBe($original->sha256)
        ->and(Minutes::query()->where('meeting_id', $meeting->id)->count())->toBe(2);
});

it('reminds in Mina datum under Bolagsverket for a reportable decision until it is reported, linking the e-service', function () {
    $meeting = meeting(['meeting_date' => '2027-05-20']);
    $item = app(SaveMeeting::class)->saveItem($this->owner, $this->company, $meeting, ['kind' => 'ELECTION_BOARD', 'title' => 'Val av styrelse']);

    $deadline = collect(app(CompanyDeadlines::class)->upcoming($this->company, CarbonImmutable::parse('2027-05-01'), 3))->firstWhere('key', 'boardroom-report:'.$item->id);

    expect($deadline)->not->toBeNull()
        ->and(DeadlineCategory::of($deadline))->toBe(DeadlineCategory::Bolagsverket)
        ->and($deadline->sourceUrl)->toBe(BoardroomDeadlines::E_SERVICE)
        ->and($deadline->legalVerify)->toBeTrue()
        ->and($deadline->date->toDateString())->toBe('2027-06-10')
        ->and($deadline->appliesBecause)->toContain('anmälan kom in till Bolagsverket');

    app(CompanyDeadlines::class)->forget();
    app(SaveMeeting::class)->recordReported($this->owner, $this->company, $item, '2026-11-01');

    expect(collect(app(CompanyDeadlines::class)->upcoming($this->company, CarbonImmutable::parse('2027-05-01'), 3))->firstWhere('key', 'boardroom-report:'.$item->id))->toBeNull();
});

it('reminds to register a new issue within six months of the decision (13 kap. 27 §)', function () {
    $a = Boardroom::person($this->owner, $this->company);
    Boardroom::formation($this->owner, $this->company, $a);
    $issue = Boardroom::record($this->owner, $this->company, ['kind' => 'NEW_ISSUE', 'decided_on' => '2027-02-01', 'effective_date' => '2027-02-20', 'share_from' => 1001, 'share_to' => 1200, 'to_holder_id' => $a->id, 'capital_change_ore' => 500_000]);

    $deadline = collect(app(CompanyDeadlines::class)->upcoming($this->company, CarbonImmutable::parse('2027-02-21'), 12))->firstWhere('key', 'boardroom-new-issue:'.$issue->id);

    expect($deadline?->date->toDateString())->toBe('2027-08-01')
        ->and(DeadlineCategory::of($deadline))->toBe(DeadlineCategory::Bolagsverket);
});

it('applies a new version of the articles only from its registration (3 kap. 5 §)', function () {
    $articles = app(SaveArticles::class);
    $first = $articles->adopt($this->owner, $this->company, ['adopted_on' => '2026-01-10', 'registered_on' => '2026-01-20', 'notice_method' => 'Brev med post.']);
    $second = $articles->adopt($this->owner, $this->company, ['adopted_on' => '2027-05-20', 'notice_method' => 'E-post.']);

    expect(ArticlesVersion::applicable(CarbonImmutable::parse('2027-06-01'))?->id)->toBe($first->id);

    $articles->registerVersion($this->owner, $this->company, $second, '2026-11-01');
})->throws(ValidationException::class);

it('uses the new articles from the registration date, and never edits a version', function () {
    $articles = app(SaveArticles::class);
    $first = $articles->adopt($this->owner, $this->company, ['adopted_on' => '2026-01-10', 'registered_on' => '2026-01-20', 'notice_method' => 'Brev med post.']);
    $second = $articles->adopt($this->owner, $this->company, ['adopted_on' => '2026-05-20', 'notice_method' => 'E-post.']);
    $articles->registerVersion($this->owner, $this->company, $second, '2026-06-01');

    expect(ArticlesVersion::applicable(CarbonImmutable::parse('2026-05-31'))?->id)->toBe($first->id)
        ->and(ArticlesVersion::applicable(CarbonImmutable::parse('2026-06-01'))?->id)->toBe($second->id)
        ->and(fn () => $first->update(['notice_method' => 'Ändrad']))->toThrow(LogicException::class);
});
