<?php

declare(strict_types=1);

use App\Domain\Company\CompanyRole;
use App\Livewire\Accounting\ChartOfAccounts;
use App\Livewire\Accounting\VoucherList;
use App\Support\Tenancy\DimensionScope;
use App\Support\Tenancy\DimensionScopeRegistry;
use App\Support\Tenancy\ScopeDecision;
use Illuminate\Routing\Route;
use Illuminate\Support\Facades\Route as RouteFacade;
use Livewire\Component;

/*
 * ADR-081 gap 10: every page and API endpoint a signed-in member can reach has
 * a scope decision, so a new one cannot open to a member limited to cost
 * centres or projects by accident (the way StoredFilesTest fails a path column
 * that is missing). A new route in a refused area is refused; a new area fails
 * here until it is given a decision.
 */

it('has a scope decision for every route a signed-in member can reach', function () {
    $missing = collect(RouteFacade::getRoutes()->getRoutes())
        ->filter(fn (Route $route): bool => DimensionScopeRegistry::requiresMember($route))
        ->map(fn (Route $route): string => DimensionScopeRegistry::key($route))
        ->reject(fn (string $key): bool => DimensionScopeRegistry::route($key) instanceof ScopeDecision)
        ->unique()
        ->values()
        ->all();

    expect($missing)->toBe([], 'Give these routes a decision in DimensionScopeRegistry: '.implode(', ', $missing));
});

it('opens only routes that exist and that a member signs in for', function () {
    $routes = collect(RouteFacade::getRoutes()->getRoutes())
        ->filter(fn (Route $route): bool => DimensionScopeRegistry::requiresMember($route))
        ->map(fn (Route $route): string => DimensionScopeRegistry::key($route))
        ->unique()
        ->all();

    $opened = [...DimensionScopeRegistry::SCOPED_ROUTES, ...DimensionScopeRegistry::NEUTRAL_ROUTES, ...DimensionScopeRegistry::LANDING_ROUTES];

    expect(array_values(array_diff($opened, $routes)))->toBe([])
        ->and(count($opened))->toBe(count(array_unique($opened)));
});

it('never opens a whole-company page to a limited member', function (string $route) {
    expect(DimensionScopeRegistry::opens($route))->toBeFalse();
})->with([
    'accounting.ledgers', 'accounting.fiscalYears', 'accounting.fiscalYears.sie', 'accounting.fiscalYears.archive',
    'accounting.annualReport', 'accounting.closing', 'accounting.accounts', 'vat.index', 'vat.file',
    'dashboard.gettingStarted', 'companies.overview', 'group.overview', 'search', 'notifications.index',
    'invoicing.invoices.index', 'payables.batches.index', 'tasks.index', 'connect.index',
]);

it('refuses every API endpoint for a limited member', function () {
    $api = collect(RouteFacade::getRoutes()->getRoutes())
        ->filter(fn (Route $route): bool => str_starts_with($route->uri(), 'api/v1') && DimensionScopeRegistry::requiresMember($route))
        ->map(fn (Route $route): string => DimensionScopeRegistry::key($route));

    expect($api)->not->toBeEmpty()
        ->and($api->filter(fn (string $key): bool => DimensionScopeRegistry::opens($key))->values()->all())->toBe([]);
});

it('names only Livewire components that exist, and refuses any other', function () {
    foreach (array_keys(DimensionScopeRegistry::COMPONENTS) as $class) {
        expect(is_subclass_of($class, Component::class))->toBeTrue("{$class} is not a Livewire component");
    }

    expect(DimensionScopeRegistry::component(ChartOfAccounts::class))->toBe(ScopeDecision::Refused)
        ->and(DimensionScopeRegistry::component(VoucherList::class))->toBe(ScopeDecision::Scoped);
});

it('keeps only permissions whose pages apply the scope', function () {
    foreach (DimensionScope::SCOPE_AWARE_PERMISSIONS as $permission) {
        expect(in_array($permission, CompanyRole::PERMISSIONS, true))->toBeTrue("{$permission} is not a core permission");
    }

    expect(DimensionScope::SCOPE_AWARE_PERMISSIONS)->not->toContain('export:sie')
        ->not->toContain('export:archive')
        ->not->toContain('vat:prepare')
        ->not->toContain('vouchers:correct')
        ->not->toContain('payments:create')
        ->not->toContain('invoices:view');
});
