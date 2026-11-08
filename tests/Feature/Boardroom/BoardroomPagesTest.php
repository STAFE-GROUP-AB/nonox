<?php

declare(strict_types=1);

use App\Actions\Accounting\AttachToVoucher;
use App\Models\FiscalYear;
use App\Modules\Boardroom\Actions\MinutesWorkflow;
use App\Modules\Boardroom\Actions\SaveMeeting;
use App\Modules\Boardroom\Livewire\ArticlesPage;
use App\Modules\Boardroom\Livewire\EventsPage;
use App\Modules\Boardroom\Livewire\MeetingPage;
use App\Modules\Boardroom\Livewire\MeetingsPage;
use App\Modules\Boardroom\Livewire\ShareholdersPage;
use App\Modules\Boardroom\Models\Meeting;
use App\Modules\Boardroom\Models\ShareEvent;
use App\Modules\Boardroom\Models\Shareholder;
use App\Support\Tenancy\CurrentCompany;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\Support\Boardroom;
use Tests\Support\Bookkeeping;

/*
 * The pages of Aktiebok och styrelserum: Swedish URLs, empty states, forms
 * with toasts, confirmations on irreversible steps, and a fixed number of
 * queries however long the history (no N+1).
 */

beforeEach(function () {
    Storage::fake(AttachToVoucher::DISK);
    [$this->owner, $this->company] = Bookkeeping::company();
    CurrentCompany::set($this->company);
    Boardroom::enable($this->company, $this->owner);
    $this->actingAs($this->owner);
});

it('answers on Swedish URLs and shows empty states that say what to do next', function () {
    $this->get('/aktiebok')->assertOk()->assertSee('Aktieboken är inte startad')->assertSee('Nytt bolag: registrera bildandet');
    $this->get('/aktiebok/handelser')->assertOk()->assertSee('Inga händelser ännu');
    $this->get('/aktiebok/agare')->assertOk()->assertSee('Inga aktieägare ännu');
    $this->get('/aktiebok/bolagsordning')->assertOk()->assertSee('Ingen bolagsordning ännu');
    $this->get('/styrelserum')->assertOk()->assertSee('Inga möten ännu');
});

it('adds a shareholder and records the formation through the forms, with toasts', function () {
    Livewire::test(ShareholdersPage::class)
        ->call('create')
        ->set('form.name', 'Anna Andersson')
        ->set('form.personal_number', Boardroom::personalNumber('19800101123'))
        ->set('form.address_line1', 'Testvägen 1')
        ->set('form.postal_code', '111 22')
        ->set('form.city', 'Teststad')
        ->call('save')
        ->assertHasNoErrors()
        ->assertDispatched('toast', message: 'Aktieägaren är tillagd.');

    $holder = Shareholder::query()->sole();

    Livewire::withQueryParams(['ny' => 'FORMATION'])->test(EventsPage::class)
        ->assertSet('form.kind', 'FORMATION')
        ->assertSee('Dagen då alla stiftare skrev under stiftelseurkunden.')
        ->set('form.effective_date', '2026-03-02')
        ->set('form.share_from', '1')
        ->set('form.share_to', '1000')
        ->set('form.to_holder_id', (string) $holder->id)
        ->set('form.capital', '25 000')
        ->call('save')
        ->assertHasNoErrors()
        ->assertDispatched('toast', message: 'Händelse 1 är införd i aktieboken.');

    expect(ShareEvent::query()->sole()->capital_change_ore)->toBe(2_500_000);

    $this->get('/aktiebok')->assertOk()->assertSee('Anna Andersson')->assertSee('1980-01-01')->assertDontSee(Boardroom::personalNumber('19800101123'));
});

it('shows the ten-year notice when an existing company opens its register', function () {
    Livewire::withQueryParams(['ny' => 'OPENING'])->test(EventsPage::class)
        ->assertSee('Spara den gamla aktieboken i minst tio år');
});

it('turns a refused entry into a Swedish error toast', function () {
    $a = Boardroom::person($this->owner, $this->company);
    Boardroom::formation($this->owner, $this->company, $a);

    Livewire::test(EventsPage::class)
        ->call('create', 'NEW_ISSUE')
        ->set('form.effective_date', '2026-05-01')
        ->set('form.share_from', '900')
        ->set('form.share_to', '1100')
        ->set('form.to_holder_id', (string) $a->id)
        ->set('form.capital', '5 000')
        ->call('save')
        ->assertDispatched('toast', type: 'error');

    expect(ShareEvent::query()->count())->toBe(1);
});

it('asks for confirmation before an entry and before approving minutes', function () {
    $html = Livewire::test(EventsPage::class)->call('create', 'TRANSFER')->html();
    expect($html)->toContain('wire:confirm="För in händelsen i aktieboken? Den kan inte ändras efteråt, bara rättas med en ny händelse."');

    $meeting = app(SaveMeeting::class)($this->owner, $this->company, ['kind' => 'BOARD', 'title' => 'Styrelsemöte', 'meeting_date' => '2026-09-01']);
    $draft = app(MinutesWorkflow::class)->start($this->owner, $this->company, $meeting);
    app(MinutesWorkflow::class)->check($this->owner, $this->company, $draft, 'Anna Andersson', 'Anna Andersson', []);

    expect(Livewire::test(MeetingPage::class, ['meeting' => $meeting->id])->html())->toContain('Godkänna protokollet i NONOX? Det låses och kan sedan bara rättas med ett nytt protokoll.');
});

it('plans an annual meeting and opens it with a toast after the redirect', function () {
    $year = FiscalYear::query()->orderBy('start_date')->firstOrFail();

    Livewire::test(MeetingsPage::class)
        ->call('plan', 'AGM')
        ->set('form.fiscal_year_id', (string) $year->id)
        ->set('form.meeting_date', '2027-05-20')
        ->call('save')
        ->assertHasNoErrors()
        ->assertRedirect(route('boardroom.meetings.show', Meeting::query()->sole()));

    expect(collect(session('toasts'))->pluck('message')->all())->toContain('Mötet är planerat. Dagordningen är ifylld med förslag.');
});

it('takes minutes through the meeting page', function () {
    $meeting = app(SaveMeeting::class)($this->owner, $this->company, ['kind' => 'BOARD', 'title' => 'Styrelsemöte', 'meeting_date' => '2026-09-01', 'attendees' => [['name' => 'Anna Andersson', 'role' => 'member']]]);

    Livewire::test(MeetingPage::class, ['meeting' => $meeting->id])
        ->call('startMinutes')
        ->assertDispatched('toast', message: 'Ett utkast till protokoll är skapat.')
        ->set('minutesForm.decisions.4', 'Föregående protokoll lades till handlingarna.')
        ->set('checking.chair', 'Anna Andersson')
        ->set('checking.minute_taker', 'Anna Andersson')
        ->call('check')
        ->assertDispatched('toast', message: 'Protokollet är markerat som justerat.')
        ->call('approve')
        ->assertDispatched('toast', message: 'Protokollet är godkänt i NONOX och låst. PDF:en och dess kontrollsumma är sparade.')
        ->assertSee('Godkänt i NONOX av')
        ->assertSee('Kontrollsumma (SHA-256)');
});

it('keeps the articles page and its share classes working', function () {
    Livewire::test(ArticlesPage::class)
        ->call('adopt')
        ->set('form.notice_method', 'Kallelse sker med brev med post.')
        ->set('form.adopted_on', '2026-01-10')
        ->call('save')
        ->assertDispatched('toast', message: 'Bolagsordningen är sparad som en ny version.')
        ->call('editClass')
        ->set('class.code', 'B')
        ->set('class.name', 'B-aktier')
        ->set('class.votes', '0,1')
        ->call('saveClass')
        ->assertDispatched('toast', message: 'Aktieslaget är sparat.')
        ->assertSee('0,1 röst per aktie');
});

it('reads the register and its history with a fixed number of queries, however long the history', function () {
    $holders = collect(range(1, 3))->map(fn (int $i) => Boardroom::person($this->owner, $this->company, "Ägare {$i}", '1980010'.$i.'123'));
    Boardroom::formation($this->owner, $this->company, $holders[0], 3000);

    $count = function (string $url): int {
        DB::flushQueryLog();
        DB::enableQueryLog();
        $this->get($url)->assertOk();
        $queries = count(DB::getQueryLog());
        DB::disableQueryLog();

        return $queries;
    };

    // The first request warms caches (modules, navigation); measure from the second.
    $count('/aktiebok');
    $short = [$count('/aktiebok'), $count('/aktiebok/handelser'), $count('/aktiebok/agare')];

    for ($i = 0; $i < 12; $i++) {
        $from = $i * 100 + 1;
        Boardroom::record($this->owner, $this->company, ['kind' => 'TRANSFER', 'effective_date' => '2026-04-'.str_pad((string) ($i + 1), 2, '0', STR_PAD_LEFT), 'share_from' => $from, 'share_to' => $from + 99, 'from_holder_id' => $holders[0]->id, 'to_holder_id' => $holders[1 + $i % 2]->id]);
    }

    expect([$count('/aktiebok'), $count('/aktiebok/handelser'), $count('/aktiebok/agare')])->toBe($short);
});

it('reads the meetings and a meeting with a fixed number of queries, however many there are', function () {
    $make = fn (string $date) => app(SaveMeeting::class)($this->owner, $this->company, ['kind' => 'BOARD', 'title' => 'Styrelsemöte '.$date, 'meeting_date' => $date]);
    $first = $make('2026-09-01');

    $count = function (string $url): int {
        DB::flushQueryLog();
        DB::enableQueryLog();
        $this->get($url)->assertOk();
        $queries = count(DB::getQueryLog());
        DB::disableQueryLog();

        return $queries;
    };

    $count('/styrelserum');
    $short = [$count('/styrelserum'), $count('/styrelserum/moten/'.$first->id)];

    foreach (['2026-09-15', '2026-10-01', '2026-10-15', '2026-11-01'] as $date) {
        $make($date);
    }

    app(SaveMeeting::class)->saveItem($this->owner, $this->company, $first, ['kind' => 'OTHER', 'title' => 'Budget']);
    app(SaveMeeting::class)->saveItem($this->owner, $this->company, $first, ['kind' => 'OTHER', 'title' => 'Lokaler']);

    expect([$count('/styrelserum'), $count('/styrelserum/moten/'.$first->id)])->toBe($short);
});

it('answers 404 for another company\'s meeting, minutes and shareholder', function () {
    [$otherOwner, $other] = Bookkeeping::company();
    Boardroom::enable($other, $otherOwner);
    $foreign = CurrentCompany::run($other, function () use ($otherOwner, $other): array {
        $meeting = app(SaveMeeting::class)($otherOwner, $other, ['kind' => 'BOARD', 'title' => 'Hemligt möte', 'meeting_date' => '2026-09-01']);
        $draft = app(MinutesWorkflow::class)->start($otherOwner, $other, $meeting);
        app(MinutesWorkflow::class)->check($otherOwner, $other, $draft, 'X', 'X', []);
        $minutes = app(MinutesWorkflow::class)->approve($otherOwner, $other, $draft->fresh());

        return [$meeting, $minutes, Boardroom::person($otherOwner, $other, 'Hemlig Ägare')];
    });

    $this->actingAs($this->owner)->get('/styrelserum/moten/'.$foreign[0]->id)->assertNotFound();
    $this->actingAs($this->owner)->get('/styrelserum/moten/'.$foreign[0]->id.'/kallelse')->assertNotFound();
    $this->actingAs($this->owner)->get('/styrelserum/protokoll/'.$foreign[1]->id)->assertNotFound();
    $this->actingAs($this->owner)->get('/registerutdrag/aktieagare/'.$foreign[2]->id)->assertNotFound();
});

it('gives a shareholder their register extract with their history, and logs it', function () {
    $a = Boardroom::person($this->owner, $this->company);
    $b = Boardroom::person($this->owner, $this->company, 'Bertil Berg', '19750505123');
    Boardroom::formation($this->owner, $this->company, $a);
    Boardroom::record($this->owner, $this->company, ['kind' => 'TRANSFER', 'effective_date' => '2027-01-15', 'share_from' => 501, 'share_to' => 1000, 'from_holder_id' => $a->id, 'to_holder_id' => $b->id]);

    $json = $this->get('/registerutdrag/aktieagare/'.$a->id)->assertOk()->json();

    expect($json['aktieagare']['personnummer'])->toBe(Boardroom::personalNumber('19800101123'))
        ->and($json['utdrag']['rattslig_grund'])->toContain('artikel 6.1 c')
        ->and(array_column($json['handelser'], 'typ'))->toBe(['FORMATION', 'TRANSFER'])
        ->and($json['aktier_i_dag'][0]['till_nummer'])->toBe(500);
});
