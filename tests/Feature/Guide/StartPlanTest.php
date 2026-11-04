<?php

declare(strict_types=1);

use App\Actions\Guide\CompleteGuideStep;
use App\Actions\Guide\UncompleteGuideStep;
use App\Domain\Company\Industry;
use App\Domain\Company\VatPeriod;
use App\Domain\Overview\Overview;
use App\Domain\Overview\TodoProviders;
use App\Domain\Overview\Todos\GuideTodos;
use App\Livewire\Company\CompanyDetailsForm;
use App\Livewire\Company\CreateCompanyForm;
use App\Livewire\Guide\StartPlan;
use App\Models\AuditLog;
use App\Models\Company;
use App\Models\CompanyGuideStep;
use App\Models\User;
use App\Support\Guide\Guide;
use App\Support\Tenancy\CurrentCompany;
use App\Support\Toast;
use Carbon\CarbonImmutable;
use Database\Factories\CompanyFactory;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Tests\Support\Bookkeeping;

/*
 * The signed-in part of Företagsguiden (ADR-051): Min startplan, ticking
 * steps, the todo on Översikt, the tips on the pages of the app and the
 * company's guide settings. Reads the fixture guide, whose start plan has
 * five steps; an AB café that is VAT and employer registered gets them all.
 */

beforeEach(function () {
    config([
        'app.landing.enabled' => true,
        'app.guide.path' => base_path('tests/Fixtures/foretagsguiden/sv'),
        'app.guide.start_plan' => base_path('tests/Fixtures/foretagsguiden/startplan.json'),
    ]);
    Guide::flush();

    [$this->owner, $this->company] = Bookkeeping::company();
    $this->company->forceFill(['legal_form' => 'AB', 'industry' => Industry::Cafe, 'vat_registered' => true, 'vat_period' => VatPeriod::Quarterly, 'employer_registered' => true])->save();
    CurrentCompany::set($this->company);
});

it('shows the steps for the company with progress, links and who did what', function () {
    app(CompleteGuideStep::class)($this->owner, $this->company, 'registrera-foretaget');

    $this->actingAs($this->owner)->get(route('guide.plan'))->assertOk()
        ->assertSee('Min startplan')
        ->assertSee('1 av 5 klara')
        ->assertSee('Registrera livsmedelsanläggningen')
        ->assertSee('Läs mer')
        ->assertSee(route('guide.article', ['starta', 'registrera-foretaget']), false)
        ->assertSee('Gör det i NONOX')
        ->assertSee(route('vat.index'), false)
        ->assertSee('Klart '.now()->format('Y-m-d').' av '.$this->owner->name)
        ->assertSee('Företagsguiden är i beta.');
});

it('needs a signed-in user with a company', function () {
    $this->get(route('guide.plan'))->assertRedirect(route('login'));
    $this->actingAs(User::factory()->create())->get(route('guide.plan'))->assertRedirect(route('onboarding'));
});

it('ticks and reopens a step with a toast and an audit entry holding only the key', function () {
    $this->actingAs($this->owner);

    Livewire::test(StartPlan::class)
        ->call('toggle', 'betala-in-aktiekapitalet', true)
        ->assertDispatched('toast', message: 'Steget är markerat som klart.')
        ->assertSee('1 av 5 klara');

    $step = CompanyGuideStep::query()->sole();
    expect($step->step_key)->toBe('betala-in-aktiekapitalet')
        ->and($step->completed_by)->toBe($this->owner->id)
        ->and(AuditLog::query()->where('action', 'guide.step_completed')->sole()->payload)->toBe(['step_key' => 'betala-in-aktiekapitalet']);

    Livewire::test(StartPlan::class)
        ->call('toggle', 'betala-in-aktiekapitalet', false)
        ->assertDispatched('toast', message: 'Steget är öppnat igen.')
        ->assertSee('0 av 5 klara');

    expect(CompanyGuideStep::query()->count())->toBe(0)
        ->and(AuditLog::query()->where('action', 'guide.step_reopened')->sole()->payload)->toBe(['step_key' => 'betala-in-aktiekapitalet']);
});

it('ticks a step once, however often it is ticked', function () {
    app(CompleteGuideStep::class)($this->owner, $this->company, 'registrera-foretaget');
    app(CompleteGuideStep::class)($this->owner, $this->company, 'registrera-foretaget');

    expect(CompanyGuideStep::query()->count())->toBe(1)
        ->and(AuditLog::query()->where('action', 'guide.step_completed')->count())->toBe(1);
});

it('lets only members with company:update tick steps, and only steps of the company\'s plan', function () {
    $bookkeeper = Bookkeeping::member($this->company, 'bookkeeper');

    expect(fn () => app(CompleteGuideStep::class)($bookkeeper, $this->company, 'registrera-foretaget'))->toThrow(AuthorizationException::class)
        ->and(fn () => app(UncompleteGuideStep::class)($bookkeeper, $this->company, 'registrera-foretaget'))->toThrow(AuthorizationException::class)
        ->and(fn () => app(CompleteGuideStep::class)($this->owner, $this->company, 'finns-inte'))->toThrow(ValidationException::class);

    $this->company->forceFill(['legal_form' => 'EF'])->save();
    expect(fn () => app(CompleteGuideStep::class)($this->owner, $this->company->refresh(), 'betala-in-aktiekapitalet'))->toThrow(ValidationException::class);

    $admin = Bookkeeping::member($this->company, 'admin');
    app(CompleteGuideStep::class)($admin, $this->company->fresh(), 'registrera-foretaget');

    $this->actingAs($bookkeeper)->get(route('guide.plan'))->assertOk()
        ->assertSee('bara ägare och administratörer kan markera steg som klara')
        ->assertSee('disabled', false);
});

it('reads the plan with the same number of queries whatever the progress', function () {
    $count = function (): int {
        DB::flushQueryLog();
        DB::enableQueryLog();
        $this->actingAs($this->owner->fresh())->get(route('guide.plan'))->assertOk();

        return count(DB::getQueryLog());
    };

    $before = $count();

    $users = [$this->owner, Bookkeeping::member($this->company, 'admin'), Bookkeeping::member($this->company, 'admin')];
    foreach (['registrera-foretaget', 'betala-in-aktiekapitalet', 'registrera-livsmedelsanlaggning'] as $index => $key) {
        app(CompleteGuideStep::class)($users[$index], $this->company->fresh(), $key);
    }
    $three = $count();

    foreach (['forsta-momsdeklarationen', 'anmal-arbetsgivare'] as $key) {
        app(CompleteGuideStep::class)($this->owner, $this->company, $key);
    }

    expect($three)->toBe($before)
        ->and($count())->toBe($before);
});

it('puts the start plan under Att göra until every step is done, unless the guide is off', function () {
    $todos = fn (User $user): array => (new GuideTodos)->todos($user, $this->company->refresh(), CarbonImmutable::now());

    app(CompleteGuideStep::class)($this->owner, $this->company, 'registrera-foretaget');

    expect($todos($this->owner))->toHaveCount(1)
        ->and($todos($this->owner)[0]->title)->toBe('Fortsätt din startplan (1 av 5 klara)')
        ->and($todos($this->owner)[0]->url)->toBe(route('guide.plan'))
        ->and($todos(Bookkeeping::member($this->company, 'bookkeeper')))->toBe([]);

    $this->company->forceFill(['guide_enabled' => false])->save();
    expect($todos($this->owner))->toBe([]);

    $this->company->forceFill(['guide_enabled' => true])->save();
    foreach (['betala-in-aktiekapitalet', 'registrera-livsmedelsanlaggning', 'forsta-momsdeklarationen', 'anmal-arbetsgivare'] as $key) {
        app(CompleteGuideStep::class)($this->owner, $this->company, $key);
    }

    expect($todos($this->owner))->toBe([])
        ->and(array_filter(app(TodoProviders::class)->installed(), fn ($provider): bool => $provider instanceof GuideTodos))->toHaveCount(1);
});

it('shows tips about the page in the app, without another query, and hides them on request', function () {
    $this->actingAs($this->owner)->get(route('vat.index'))->assertOk()
        ->assertSee('Tips från Företagsguiden')
        ->assertSee('Så lämnar du momsdeklarationen.')
        ->assertSee(route('guide.article', ['moms', 'momsdeklaration']), false);

    $count = function (): int {
        DB::flushQueryLog();
        DB::enableQueryLog();
        $this->actingAs($this->owner->fresh())->get(route('vat.index'))->assertOk();

        return count(DB::getQueryLog());
    };
    $with = $count();
    $this->company->forceFill(['guide_enabled' => false])->save();
    $without = $count();

    expect($with)->toBe($without);
    $this->actingAs($this->owner->fresh())->get(route('vat.index'))->assertOk()->assertDontSee('data-guide-tips', false)->assertDontSee('data-guide-link', false);

    $this->company->forceFill(['guide_enabled' => true])->save();
    $this->actingAs($this->owner)->from(route('vat.index'))->put(route('guide.tips'), ['visible' => 0])->assertRedirect(route('vat.index'));

    expect(array_column((array) session(Toast::SESSION_KEY, []), 'message'))->toBe(['Tipsen från Företagsguiden är dolda. Du kan visa dem igen under Min startplan.']);
    $this->actingAs($this->owner->refresh())->get(route('vat.index'))->assertOk()->assertDontSee('data-guide-tips', false)->assertSee('data-guide-link', false);

    $this->actingAs($this->owner)->put(route('guide.tips'), ['visible' => 1]);
    $this->actingAs($this->owner->refresh())->get(route('vat.index'))->assertOk()->assertSee('data-guide-tips', false);
});

it('offers to show hidden tips again on Min startplan', function () {
    $this->owner->setPanelHidden('guide', $this->company, true);

    $this->actingAs($this->owner->refresh())->get(route('guide.plan'))->assertOk()
        ->assertSee('Visa tipsen igen');
});

it('saves the industry and the guide setting on the company details page, and asks for no start date', function () {
    $this->actingAs($this->owner);

    Livewire::test(CompanyDetailsForm::class, ['company' => $this->company])
        ->assertSee('Visa Företagsguiden')
        ->assertDontSee('Företaget startade')
        ->assertDontSee('startdatum')
        ->set('state.industry', 'restaurant')
        ->set('state.guide_enabled', false)
        ->set('state.started_on', '2026-03-01')
        ->call('save')
        ->assertHasNoErrors();

    $company = $this->company->fresh();
    expect($company->industry)->toBe(Industry::Restaurant)
        ->and($company->started_on)->toBeNull()
        ->and($company->guide_enabled)->toBeFalse()
        ->and(AuditLog::query()->where('action', 'company.updated')->latest('id')->first()->payload['after'])->toMatchArray(['industry' => 'restaurant', 'guide_enabled' => false])
        ->not->toHaveKey('started_on');

    Livewire::test(CompanyDetailsForm::class, ['company' => $company])
        ->set('state.industry', '')
        ->call('save')
        ->assertHasNoErrors();

    expect($company->fresh()->industry)->toBeNull();

    Livewire::test(CompanyDetailsForm::class, ['company' => $company])
        ->set('state.industry', 'bageri')
        ->call('save')
        ->assertHasErrors(['industry']);
});

it('asks for the industry, optionally, when a company is created, and not for a start date', function () {
    $this->actingAs(User::factory()->create());

    Livewire::test(CreateCompanyForm::class)
        ->assertSee('Bransch (frivilligt)')
        ->assertSee('Annan bransch')
        ->assertDontSee('Företaget startade')
        ->set('name', 'Kafé Linden AB')
        ->set('org_number', CompanyFactory::organisationNumber()->formatted())
        ->set('framework_confirmed', true)
        ->set('industry', 'cafe')
        ->call('create')
        ->assertHasNoErrors();

    $company = Company::query()->where('name', 'Kafé Linden AB')->sole();
    expect($company->industry)->toBe(Industry::Cafe)
        ->and($company->started_on)->toBeNull()
        ->and($company->refresh()->guide_enabled)->toBeTrue()
        ->and(AuditLog::query()->where('action', 'company.created')->latest('id')->first()->payload)->toMatchArray(['industry' => 'cafe'])
        ->not->toHaveKey('started_on');

    Livewire::test(CreateCompanyForm::class)
        ->set('name', 'Utan bransch AB')
        ->set('org_number', CompanyFactory::organisationNumber()->formatted())
        ->set('framework_confirmed', true)
        ->call('create')
        ->assertHasNoErrors();

    expect(Company::query()->where('name', 'Utan bransch AB')->sole()->industry)->toBeNull();
});

it('links Företagsguiden from the app menu while the company shows the guide', function () {
    $this->actingAs($this->owner)->get(route('dashboard'))->assertOk()
        ->assertSee('href="'.route('guide.plan').'"', false);
});

it('ticks a step once when two requests tick it at the same time', function () {
    // The other request's row is already there when this one inserts: no error, no second row, no second audit entry.
    DB::table('company_guide_steps')->insert(['company_id' => $this->company->id, 'step_key' => 'registrera-foretaget', 'completed_at' => now(), 'completed_by' => $this->owner->id, 'created_at' => now(), 'updated_at' => now()]);

    $this->actingAs($this->owner);
    Livewire::test(StartPlan::class)
        ->call('toggle', 'registrera-foretaget', true)
        ->assertDispatched('toast', message: 'Steget är markerat som klart.')
        ->assertSee('1 av 5 klara');

    expect(CompanyGuideStep::query()->count())->toBe(1)
        ->and(AuditLog::query()->where('action', 'guide.step_completed')->count())->toBe(0);
});

it('counts the start plan in Att göra from the plan as it is now, also right after it is edited', function () {
    $overview = app(Overview::class);
    $title = fn (): ?string => collect($overview->for($this->owner, $this->company->refresh())['todos'])->firstWhere('key', 'guide-start-plan')['title'] ?? null;

    expect($title())->toBe('Fortsätt din startplan (0 av 5 klara)');

    $plan = json_decode((string) file_get_contents(base_path('tests/Fixtures/foretagsguiden/startplan.json')), true);
    array_pop($plan['steg']);
    $file = sys_get_temp_dir().'/guide-plan-'.bin2hex(random_bytes(4)).'.json';
    file_put_contents($file, json_encode($plan));
    config(['app.guide.start_plan' => $file]);
    Guide::flush();

    expect($title())->toBe('Fortsätt din startplan (0 av 4 klara)');
    unlink($file);
});
