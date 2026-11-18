<?php

declare(strict_types=1);

use App\Actions\Company\SetDimensionScope;
use App\Actions\Guide\IssueDeadlineFeed;
use App\Livewire\Accounting\ChartOfAccounts;
use App\Livewire\Accounting\VoucherList;
use App\Models\FiscalYear;
use App\Models\User;
use App\Modules\Connect\ConnectModule;
use App\Modules\ModuleManager;
use App\Support\Navigation\Navigation;
use App\Support\Tenancy\CurrentCompany;
use App\Support\Tenancy\DimensionScopeRegistry;
use App\Support\Tenancy\ScopeDecision;
use Illuminate\Routing\Route;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route as RouteFacade;
use Livewire\Livewire;
use Tests\Support\Bookkeeping;
use Tests\Support\Connect;
use Tests\Support\DimensionScopeFixture;

/*
 * ADR-081 gap 10, the scope suite: every page a limited member could open,
 * run as that member. A refused page answers 404 like another company's
 * record (AGENTS.md §13.2); an open page answers without an error.
 */
beforeEach(function () {
    $this->fixture = DimensionScopeFixture::make();
    $this->member = $this->fixture->member();
    $this->actingAs($this->member);
});

it('answers every signed-in page without parameters by its decision', function () {
    $routes = collect(RouteFacade::getRoutes()->getRoutes())
        ->filter(fn (Route $route): bool => in_array('GET', $route->methods(), true)
            && DimensionScopeRegistry::requiresMember($route)
            && $route->parameterNames() === []
            && ! str_starts_with($route->uri(), 'api/'));

    expect($routes->count())->toBeGreaterThan(50);

    // The same role without a limit, to tell the scope's answer from the page's own.
    $unlimited = Bookkeeping::member($this->fixture->company, 'bookkeeper');

    foreach ($routes as $route) {
        $decision = DimensionScopeRegistry::route(DimensionScopeRegistry::key($route));
        $uri = '/'.ltrim($route->uri(), '/');
        $response = $this->actingAs($this->member)->get($uri);

        // Without a filter the CSV file is the whole company's saldobalans: the page itself refuses it (DimensionScopeReportsTest).
        if ($route->getName() === 'accounting.reports.csv') {
            expect($response->getStatusCode())->toBe(404);

            continue;
        }

        match ($decision) {
            ScopeDecision::Refused, null => expect($response->getStatusCode())->toBe(404, "{$route->uri()} must be refused"),
            ScopeDecision::Landing => expect($response->headers->get('Location'))->toBe(route('accounting.reports')),
            // An open page answers as it does without the limit: a refused component inside it would turn the page into a 404.
            default => expect($response->getStatusCode())->toBe($this->actingAs($unlimited)->get($uri)->getStatusCode(), "{$route->uri()} answered {$response->getStatusCode()} for a limited member"),
        };
    }
});

it('leaves an unlimited member alone', function () {
    $this->actingAs($this->fixture->owner)->get(route('vat.index'))->assertOk();
    $this->actingAs($this->fixture->owner)->get(route('dashboard'))->assertOk();
});

it('refuses another company\'s and the whole company\'s files by id', function () {
    $year = CurrentCompany::run($this->fixture->company, fn () => FiscalYear::query()->firstOrFail());

    $this->get(route('accounting.fiscalYears.sie', $year))->assertNotFound();
    $this->get(route('vat.file', $year))->assertNotFound();
});

it('shows a limited member only the pages that apply the scope', function () {
    $sections = app(Navigation::class)->for($this->member, $this->fixture->company, 'accounting.vouchers.index');
    $links = collect($sections)->flatMap(fn ($section) => [$section->route, ...array_map(fn ($link) => $link->route, $section->children)])->unique()->values()->all();

    expect($links)->each->toBeIn([...DimensionScopeRegistry::SCOPED_ROUTES, ...DimensionScopeRegistry::NEUTRAL_ROUTES])
        ->and($links)->toContain('accounting.vouchers.index', 'accounting.reports', 'payables.invoices.index');
});

it('hides the search and the cross-company links', function () {
    $this->get(route('accounting.vouchers.index'))
        ->assertOk()
        ->assertDontSee('spotlight-open', false)
        ->assertDontSee(route('companies.overview'), false)
        ->assertDontSee(route('notifications.index'), false);
});

it('refuses a Livewire component the registry does not name', function () {
    Livewire::test(ChartOfAccounts::class)->assertNotFound();
});

it('answers 404 from a page kept open after the scope changed', function () {
    $component = Livewire::test(VoucherList::class)->assertOk();

    app(SetDimensionScope::class)($this->fixture->owner, $this->fixture->company, $this->member->id, [$this->fixture->south->id], []);

    $component->call('$refresh')->assertNotFound();
});

it('carries the scope into the member\'s API key', function () {
    Connect::setUp();
    app(ModuleManager::class)->enable(ConnectModule::KEY, $this->fixture->company, $this->fixture->owner);
    $key = Connect::apiKey($this->member, $this->fixture->company, ['vouchers:view', 'reports:view']);
    $unlimited = Connect::apiKey(User::query()->findOrFail($this->fixture->owner->id), $this->fixture->company, ['vouchers:view', 'reports:view']);

    $this->withToken($unlimited)->getJson('/api/v1/company')->assertOk();

    $this->withToken($key)->getJson('/api/v1/vouchers')->assertNotFound();
    $this->withToken($key)->getJson("/api/v1/vouchers/{$this->fixture->vouchers['inside']->id}")->assertNotFound();
    $this->withToken($key)->getJson('/api/v1/company')->assertNotFound();
});

it('answers 404, not 403, for a voucher to a limited member without the permission', function () {
    $manager = Bookkeeping::member($this->fixture->company, 'unit_manager');
    app(SetDimensionScope::class)($this->fixture->owner, $this->fixture->company, $manager->id, [$this->fixture->north->id], []);

    $this->actingAs($manager->fresh())->get(route('accounting.vouchers.show', $this->fixture->vouchers['inside']))->assertNotFound();
    $this->actingAs($manager->fresh())->get(route('payables.invoices.show', $this->fixture->invoices['inside']))->assertNotFound();
});

it('closes the Mina datum calendar link of a member who is limited later', function () {
    $bookkeeper = Bookkeeping::member($this->fixture->company, 'bookkeeper');
    $link = app(IssueDeadlineFeed::class)($bookkeeper, $this->fixture->company);

    $this->get($link)->assertOk();

    app(SetDimensionScope::class)($this->fixture->owner, $this->fixture->company, $bookkeeper->id, [$this->fixture->north->id], []);

    $this->get($link)->assertNotFound();
});

it('reads no scope and no extra membership for the owner and an unlimited bookkeeper', function (string $who, string $route) {
    $user = $who === 'owner' ? $this->fixture->owner : Bookkeeping::member($this->fixture->company, 'bookkeeper');
    $this->actingAs($user);
    $this->get(route($route))->assertOk();

    // A fresh instance, so the memberships are not already loaded from the first request.
    $this->actingAs($user->fresh());
    DB::flushQueryLog();
    DB::enableQueryLog();
    $this->get(route($route))->assertOk();
    $queries = collect(DB::getQueryLog())->pluck('query');
    DB::disableQueryLog();

    expect($queries->filter(fn (string $sql): bool => str_contains($sql, 'membership_dimension_scopes'))->count())->toBe(0)
        // The memberships are read once, as before scopes existed (Jetstream's own check).
        ->and($queries->filter(fn (string $sql): bool => str_contains($sql, 'inner join "team_user"') && str_contains($sql, 'from "teams"'))->count())->toBe(1);
})->with(['owner', 'bookkeeper'])->with(['accounting.reports', 'accounting.vouchers.index']);
