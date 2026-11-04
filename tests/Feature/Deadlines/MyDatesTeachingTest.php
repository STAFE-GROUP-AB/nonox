<?php

declare(strict_types=1);

use App\Domain\Company\LegalForm;
use App\Domain\Company\VatPeriod;
use App\Domain\Deadlines\CompanyDeadlines;
use App\Domain\Deadlines\Deadline;
use App\Domain\Deadlines\DeadlineAction;
use App\Domain\Deadlines\DeadlineCategory;
use App\Domain\Deadlines\DeadlineFilter;
use App\Domain\Deadlines\DeadlineType;
use App\Domain\Deadlines\YearAtAGlance;
use App\Livewire\Guide\MyDates;
use App\Notifications\DeadlineReminder;
use App\Support\Tenancy\CurrentCompany;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Tests\Support\Bookkeeping;

/*
 * Mina datum teaches the year (ADR-051 addendum, 2026-11-05): "Ditt år i
 * korthet", a chip per kind, payment or declaration, "Vad är det här?" on the
 * first row of each kind, a repeating flag shown once, and ?visa=… filters.
 */

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-11-04 10:00:00'));
    [$this->owner, $this->company] = Bookkeeping::company();
    // Like the owner's own AB: quarterly VAT, F-skatt, not an employer.
    $this->company->forceFill(['name' => 'Kafé Linden AB', 'legal_form' => LegalForm::Ab, 'vat_registered' => true, 'vat_period' => VatPeriod::Quarterly, 'turnover_over_40_msek' => false, 'f_skatt' => true, 'employer_registered' => false])->save();
    CurrentCompany::set($this->company);
});

/** @return list<array{category: DeadlineCategory, title: string, when: string, setting: string, settingLink: bool, next: ?Deadline, flag: ?string, authority: ?string}> */
function yearLines(object $test): array
{
    $company = $test->company->fresh();
    app()->forgetScopedInstances();

    return YearAtAGlance::lines($company, app(CompanyDeadlines::class)->upcoming($company, CarbonImmutable::today()->subDays(14)), CarbonImmutable::today());
}

it('gives every kind of date a chip, a filter and a short explanation', function (DeadlineType $type, string $chip, DeadlineFilter $filter) {
    $deadline = new Deadline('x:1', $type, 'Titel', CarbonImmutable::today(), '2026', 'Därför.');
    $category = DeadlineCategory::of($deadline);

    expect($category->label())->toBe($chip)
        ->and($category->filter())->toBe($filter)
        ->and($filter->matches($deadline))->toBeTrue()
        ->and($category->explanation())->not->toBe('')
        ->and($category->icon())->not->toBe('');
})->with([
    [DeadlineType::Vat, 'Moms', DeadlineFilter::Vat],
    [DeadlineType::EuSalesList, 'EU-försäljning', DeadlineFilter::Vat],
    [DeadlineType::PreliminaryTax, 'F-skatt', DeadlineFilter::Tax],
    [DeadlineType::IncomeTaxReturn, 'Inkomstdeklaration', DeadlineFilter::Tax],
    [DeadlineType::Agi, 'Arbetsgivare', DeadlineFilter::Employer],
    [DeadlineType::AnnualMeeting, 'Bolagsverket', DeadlineFilter::Bolagsverket],
    [DeadlineType::AnnualReport, 'Bolagsverket', DeadlineFilter::Bolagsverket],
    [DeadlineType::Closing, 'Bokslut', DeadlineFilter::Closing],
]);

it('says whether a date is a payment, a declaration or both', function () {
    $make = fn (DeadlineType $type, string $key): Deadline => new Deadline($key, $type, 'T', CarbonImmutable::today(), 'p', 'b');

    expect(DeadlineAction::of($make(DeadlineType::Vat, 'vat:1'), false))->toBe(DeadlineAction::Both)
        ->and(DeadlineAction::of($make(DeadlineType::PreliminaryTax, 'preliminary_tax:1'), false))->toBe(DeadlineAction::Pay)
        ->and(DeadlineAction::of($make(DeadlineType::IncomeTaxReturn, 'income_tax_return:1'), false))->toBe(DeadlineAction::Declare)
        ->and(DeadlineAction::of($make(DeadlineType::Agi, 'agi:2026-11'), false))->toBe(DeadlineAction::Both)
        ->and(DeadlineAction::of($make(DeadlineType::Agi, 'agi:2026-11'), true))->toBe(DeadlineAction::Declare)
        ->and(DeadlineAction::of($make(DeadlineType::Agi, 'agi-payment:2026-11'), true))->toBe(DeadlineAction::Pay)
        ->and(DeadlineAction::of($make(DeadlineType::AnnualMeeting, 'annual_meeting:1'), false))->toBeNull()
        ->and(DeadlineAction::Both->label())->toBe('Deklaration och betalning');
});

it('shows the chips, verb-first titles, the action and the explanation on the page', function () {
    $html = $this->actingAs($this->owner)->get(route('guide.dates', ['alla' => 1]))->assertOk()
        ->assertSee('Ditt år i korthet')
        ->assertSee('Så läser du sidan')
        ->assertSee('F-skatt är inte moms')
        ->assertSee('Betala F-skatt för december 2026')
        ->assertSee('Deklarera och betala moms för kvartal 3 2026')
        ->assertSee('Håll årsstämman för 2026')
        ->assertSee('Skicka årsredovisningen för 2026 till Bolagsverket')
        ->assertSee('Lämna inkomstdeklaration 2 för 2026')
        ->assertSee('Deklaration och betalning')
        ->assertSee('Betalning')
        ->assertSee('Vad är det här?')
        ->assertSee('F-skatt är företagets skatt i förskott (preliminärskatt): lika stora belopp varje månad')
        ->assertDontSee('Betala preliminärskatt för')
        ->getContent();

    foreach (['moms', 'f-skatt', 'inkomstdeklaration', 'bolagsverket', 'bokslut', 'rekommendation'] as $chip) {
        expect($html)->toContain('data-deadline-chip="'.$chip.'"');
    }

    expect($html)->not->toContain('data-deadline-chip="arbetsgivare"')
        // One "Vad är det här?" per kind, not per row.
        ->and(substr_count($html, 'data-deadline-explained="f-skatt"'))->toBe(1)
        ->and(substr_count($html, 'data-deadline-explained="moms"'))->toBe(1);
});

it('shows the F-skatt flag once, on Ditt år and the first month, and keeps a one-off flag', function () {
    $this->actingAs($this->owner);

    $page = Livewire::withQueryParams(['alla' => 1])->test(MyDates::class);
    $flagged = $page->viewData('flagged');
    $described = $page->viewData('described');
    $months = collect($page->viewData('shown'))->filter(fn (Deadline $deadline): bool => str_starts_with($deadline->key, 'preliminary_tax:'))->pluck('key')->all();

    expect(count($months))->toBeGreaterThan(10)
        ->and($flagged)->toBe([$months[0], 'annual_report:2026-12-31'])
        // The same reason is written on the first month only.
        ->and(array_values(array_intersect($described, $months)))->toBe([$months[0]]);

    $html = $this->get(route('guide.dates', ['alla' => 1]))->getContent();

    expect(substr_count($html, 'data-deadline-flag'))->toBe(2)
        ->and($html)->toContain('data-year-flag')
        ->and($html)->toContain('Första månaden och beloppet står i ert beslut om F-skatt från Skatteverket.');
});

it('filters by kind with GET links and counts, the summary following the filter, with the same queries', function () {
    $this->actingAs($this->owner);

    $all = Livewire::withQueryParams(['alla' => 1])->test(MyDates::class);
    $counts = $all->viewData('counts');

    expect($counts['moms'] + $counts['skatt'] + $counts['arbetsgivare'] + $counts['bolagsverket'] + $counts['bokslut'])->toBe($all->viewData('windowCount'))
        ->and($counts['arbetsgivare'])->toBe(0)
        ->and($counts['bolagsverket'])->toBe(2);

    $vat = Livewire::withQueryParams(['visa' => 'moms', 'alla' => 1])->test(MyDates::class);
    expect(collect($vat->viewData('shown'))->every(fn (Deadline $deadline): bool => $deadline->type === DeadlineType::Vat))->toBeTrue()
        ->and(count($vat->viewData('shown')))->toBe($counts['moms']);

    $this->get(route('guide.dates', ['visa' => 'skatt']))->assertOk()
        ->assertSee('Nästa datum: Skatt')
        ->assertSee('Betala F-skatt för november 2026')
        ->assertDontSee('Deklarera och betala moms för kvartal 3 2026')
        ->assertSee(route('guide.dates', ['visa' => 'moms']), false)
        ->assertSee(route('guide.dates', ['visa' => 'skatt', 'alla' => 1]));

    // An unknown filter shows everything.
    $this->get(route('guide.dates', ['visa' => 'okand']))->assertOk()->assertSee('Deklarera och betala moms för kvartal 3 2026');

    // Nothing for the filter in six months: say so and link back.
    $this->get(route('guide.dates', ['visa' => 'bolagsverket']))->assertOk()->assertSee('Inga datum under Bolagsverket de kommande sex månaderna');

    $queries = [];

    foreach (['', 'moms', 'skatt', 'bolagsverket'] as $filter) {
        $this->get(route('guide.dates', array_filter(['visa' => $filter])));
        DB::flushQueryLog();
        DB::enableQueryLog();
        $this->get(route('guide.dates', array_filter(['visa' => $filter])))->assertOk();
        $queries[$filter] = count(DB::getQueryLog());
        DB::disableQueryLog();
    }

    expect(array_unique(array_values($queries)))->toHaveCount(1);
});

it('links a line of Ditt år to its filter, over fifteen months when the next date is further than six', function () {
    $this->actingAs($this->owner)->get(route('guide.dates'))->assertOk()
        ->assertSee(route('guide.dates', ['visa' => 'bolagsverket', 'alla' => 1]))
        ->assertSee(route('guide.dates', ['visa' => 'skatt']), false)
        ->assertSee('Ändra under Företagets uppgifter')
        ->assertSee(route('teams.show', $this->company->id), false);
});

it('describes VAT by the period the company reports', function (VatPeriod $period, bool $large, string $title, string $when) {
    $this->company->forceFill(['vat_period' => $period, 'turnover_over_40_msek' => $large])->save();
    $vat = collect(yearLines($this))->firstWhere('category', DeadlineCategory::Vat);

    expect($vat['title'])->toBe($title)
        ->and($vat['when'])->toBe($when)
        ->and($vat['next'])->not->toBeNull()
        ->and($vat['setting'])->toContain('Momsredovisning');
})->with([
    'quarterly' => [VatPeriod::Quarterly, false, 'Moms – fyra gånger om året, en gång per kvartal (ni redovisar per kvartal)', 'Deklarera och betala senast den 12:e i andra månaden efter kvartalet (17 augusti för april–juni).'],
    'monthly' => [VatPeriod::Monthly, false, 'Moms – tolv gånger om året, en gång per månad (ni redovisar per månad)', 'Deklarera och betala senast den 12:e i andra månaden efter (17:e i januari och augusti).'],
    'monthly, over 40 million' => [VatPeriod::Monthly, true, 'Moms – tolv gånger om året, en gång per månad (ni redovisar per månad)', 'Deklarera och betala senast den 26:e i månaden efter (27:e i december).'],
    'yearly' => [VatPeriod::Yearly, false, 'Moms – en gång om året (ni redovisar per år)', 'Deklarera och betala en gång efter räkenskapsåret. Dagen beror på bolagsformen, räkenskapsåret och om företaget handlar med andra EU-länder.'],
]);

it('says when the company is not registered for VAT', function () {
    $this->company->forceFill(['vat_registered' => false, 'vat_period' => VatPeriod::None])->save();
    $vat = collect(yearLines($this))->firstWhere('category', DeadlineCategory::Vat);

    expect($vat['title'])->toBe('Moms – ingen momsredovisning')->and($vat['next'])->toBeNull();
});

it('lists the year of an AB and of a sole trader, with the employer line only for an employer', function () {
    $ab = collect(yearLines($this));

    expect($ab->pluck('category')->all())->toBe([DeadlineCategory::Vat, DeadlineCategory::PreliminaryTax, DeadlineCategory::IncomeTax, DeadlineCategory::Bolagsverket, DeadlineCategory::Closing])
        ->and($ab->firstWhere('category', DeadlineCategory::PreliminaryTax)['title'])->toBe('F-skatt – varje månad, oftast den 12:e (17:e i januari och augusti)')
        ->and($ab->firstWhere('category', DeadlineCategory::PreliminaryTax)['flag'])->toBe('Första månaden och beloppet står i ert beslut om F-skatt från Skatteverket.')
        ->and($ab->firstWhere('category', DeadlineCategory::IncomeTax)['title'])->toBe('Inkomstdeklaration – en gång om året')
        ->and($ab->firstWhere('category', DeadlineCategory::Bolagsverket)['title'])->toBe('Årsstämma och årsredovisning – en gång om året');

    $this->company->forceFill(['employer_registered' => true])->save();
    $employer = collect(yearLines($this))->firstWhere('category', DeadlineCategory::Employer);
    expect($employer['title'])->toBe('Arbetsgivardeklaration – varje månad, den 12:e (17:e i januari och augusti)')
        ->and($employer['next']?->date->toDateString())->toBe('2026-11-12');

    $this->company->forceFill(['legal_form' => LegalForm::Ef, 'employer_registered' => false, 'vat_period' => VatPeriod::Yearly])->save();
    $ef = collect(yearLines($this));

    expect($ef->pluck('category')->all())->toBe([DeadlineCategory::Vat, DeadlineCategory::PreliminaryTax, DeadlineCategory::IncomeTax, DeadlineCategory::Closing])
        ->and($ef->firstWhere('category', DeadlineCategory::IncomeTax)['title'])->toBe('Inkomstdeklaration – en gång om året, med NE-bilagan i din egen deklaration')
        ->and($ef->firstWhere('category', DeadlineCategory::PreliminaryTax)['when'])->toContain('I enskild firma ingår egenavgifterna.')
        ->and($ef->firstWhere('category', DeadlineCategory::Vat)['next']?->date->toDateString())->toBe('2027-05-12');
});

it('tells a trading partnership that the partners pay F-skatt, not the company', function () {
    $this->company->forceFill(['legal_form' => LegalForm::Hb])->save();
    $line = collect(yearLines($this))->firstWhere('category', DeadlineCategory::PreliminaryTax);

    expect($line['title'])->toBe('F-skatt – betalas av delägarna, inte av bolaget')->and($line['next'])->toBeNull();
});

it('remembers the reading help closed per user and company', function () {
    $this->actingAs($this->owner);

    expect(Livewire::test(MyDates::class)->viewData('helpHidden'))->toBeFalse();

    Livewire::test(MyDates::class)->call('rememberHelp', false);
    expect($this->owner->fresh()->hidesPanel(MyDates::HELP_PANEL, $this->company))->toBeTrue()
        ->and(Livewire::test(MyDates::class)->viewData('helpHidden'))->toBeTrue();

    Livewire::test(MyDates::class)->call('rememberHelp', true);
    expect($this->owner->fresh()->hidesPanel(MyDates::HELP_PANEL, $this->company))->toBeFalse();
});

it('prefixes the calendar events and the reminders with the kind', function () {
    $deadline = collect(app(CompanyDeadlines::class)->upcoming($this->company, CarbonImmutable::today()))->first(fn (Deadline $deadline): bool => $deadline->type === DeadlineType::PreliminaryTax);

    expect($deadline->summary())->toBe('F-skatt: Betala F-skatt för november 2026')
        ->and(DeadlineReminder::for($this->company, $deadline, 7)->title)->toStartWith('F-skatt: Betala F-skatt för november 2026. Sista dagen är om 7 dagar');
});
