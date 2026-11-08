<?php

declare(strict_types=1);

use App\Modules\Inventory\InventoryModule;
use App\Modules\Kitchen\KitchenModule;
use App\Modules\Kitchen\Livewire\MenuPage;
use App\Modules\Kitchen\Livewire\RecipePage;
use App\Modules\Kitchen\Livewire\RecipesPage;
use App\Modules\Kitchen\Livewire\VariancePage;
use App\Modules\Kitchen\Livewire\WastePage;
use App\Modules\Kitchen\Models\WasteEntry;
use App\Modules\ModuleManager;
use App\Modules\ModuleRegistry;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Laravel\Jetstream\Jetstream;
use Livewire\Livewire;
use Tests\Support\Bookkeeping;
use Tests\Support\Inventory;
use Tests\Support\Kitchen;

/*
 * Kök as a module (ADR-073 §1, §11, ADR-017): off by default, needs Lager,
 * its pages answer 404 while it is off, the permissions per role, and
 * Kökspersonal registering waste without ever seeing a price.
 */

beforeEach(function () {
    ['owner' => $this->owner, 'company' => $this->company, 'places' => $this->places] = Kitchen::company();
    $this->actingAs($this->owner);
    $this->fx = Kitchen::fixtures($this->company, $this->owner, $this->places['bin1']);
});

it('is installed, needs Lager, and cannot be turned on without it', function () {
    expect(app(ModuleRegistry::class)->get(KitchenModule::KEY)->name())->toBe('Kök')
        ->and(app(ModuleRegistry::class)->get(KitchenModule::KEY)->dependencies())->toBe([InventoryModule::KEY])
        ->and(Jetstream::findRole(KitchenModule::ROLE)?->name)->toBe('Kökspersonal');

    [$owner, $company] = Bookkeeping::company();
    expect(app(ModuleManager::class)->isEnabled(KitchenModule::KEY, $company))->toBeFalse();
    expect(fn () => app(ModuleManager::class)->enable(KitchenModule::KEY, $company, $owner))->toThrow(ValidationException::class);

    Inventory::enable($company, $owner);
    app(ModuleManager::class)->enable(KitchenModule::KEY, $company, $owner);
    expect(app(ModuleManager::class)->isEnabled(KitchenModule::KEY, $company))->toBeTrue();
});

it('answers 404 on every page while the module is off', function () {
    foreach (['kitchen.recipes.index', 'kitchen.menu', 'kitchen.waste', 'kitchen.meals', 'kitchen.variance', 'kitchen.settings'] as $route) {
        $this->get(route($route))->assertOk();
    }

    $this->get(route('kitchen.recipes.show', $this->fx['articles']['dish']->id))->assertOk()->assertSee('Pasta med svamp');

    app(ModuleManager::class)->disable(KitchenModule::KEY, $this->company, $this->owner, confirmed: true);

    foreach (['kitchen.recipes.index', 'kitchen.menu', 'kitchen.waste', 'kitchen.meals', 'kitchen.variance'] as $route) {
        $this->get(route($route))->assertNotFound();
    }
});

it('gives each role its permissions', function (string $role, array $abilities) {
    $user = Bookkeeping::member($this->company, $role);
    $this->company->unsetRelation('users');
    $gate = Gate::forUser($user);

    foreach (['viewAny', 'register', 'manage', 'seeCosts'] as $ability) {
        expect($gate->allows($ability, [WasteEntry::class, $this->company]))->toBe(in_array($ability, $abilities, true), "{$role} {$ability}");
    }
})->with([
    'admin' => ['admin', ['viewAny', 'register', 'manage', 'seeCosts']],
    'accountant' => ['accountant', ['viewAny', 'register', 'manage', 'seeCosts']],
    'bookkeeper' => ['bookkeeper', ['viewAny', 'register', 'manage', 'seeCosts']],
    'auditor' => ['auditor', ['viewAny', 'seeCosts']],
    'approver' => ['approver', []],
    'kitchen staff' => ['kitchen_staff', ['viewAny', 'register']],
]);

it('lets the owner do everything', function () {
    foreach (['viewAny', 'register', 'manage', 'seeCosts'] as $ability) {
        expect(Gate::forUser($this->owner)->allows($ability, [WasteEntry::class, $this->company]))->toBeTrue();
    }
});

it('shows Kökspersonal recipes, the menu and the waste page without any price or cost', function () {
    $staff = Bookkeeping::member($this->company, KitchenModule::ROLE);
    $this->company->unsetRelation('users');
    $this->actingAs($staff);

    $recipe = $this->get(route('kitchen.recipes.show', $this->fx['articles']['dish']->id))->assertOk()->assertSee('Champinjoner');
    $menu = $this->get(route('kitchen.menu'))->assertOk()->assertSee('Pasta med svamp');
    $list = $this->get(route('kitchen.recipes.index'))->assertOk();
    $waste = $this->get(route('kitchen.waste'))->assertOk()->assertSee('Registrera svinn');

    foreach ([$recipe, $menu, $list, $waste] as $response) {
        $html = (string) $response->getContent();
        expect($html)->not->toContain('23,85')->not->toContain('108,40')->not->toContain('Portionskostnad')->not->toContain('Kostnad per portion')->not->toContain('Kalkyl');
    }

    // The scanner's data carries the Svinn tile and no price.
    $data = $this->getJson(route('inventory.scanner.data'))->assertOk()->json();
    expect($data['can'])->toMatchArray(['count' => false, 'waste' => true])
        ->and($data['extensions']['waste']['dishes'])->not->toBeEmpty()
        ->and(json_encode($data))->not->toContain('price')->not->toContain('cost');

    $this->get(route('kitchen.variance'))->assertForbidden();
    Livewire::test(RecipePage::class, ['recept' => $this->fx['articles']['dish']->id])->call('edit')->assertForbidden();
    $this->get(route('inventory.overview'))->assertForbidden();
});

it('gives Kökspersonal only the kitchen\'s places in the scanner, and no receipts, pick lists or counts (review P3.2)', function () {
    app(ModuleManager::class)->updateSettings(KitchenModule::KEY, $this->company, $this->owner, ['location_id' => $this->places['bin1']->id]);
    $staff = Bookkeeping::member($this->company, KitchenModule::ROLE);
    $this->company->unsetRelation('users');
    $this->actingAs($staff);

    $data = $this->getJson(route('inventory.scanner.data'))->assertOk()->json();

    expect(array_column($data['locations'], 'id'))->toBe([$this->places['bin1']->id])
        ->and(array_unique(array_column($data['levels'], 1)))->toBe([$this->places['bin1']->id])
        ->and($data['receipts'])->toBe([])->and($data['pick_lists'])->toBe([])->and($data['counts'])->toBe([]);
});

it('shows costs and margins to those who manage the kitchen', function () {
    $this->get(route('kitchen.recipes.show', $this->fx['articles']['dish']->id))->assertOk()
        ->assertSee('Kalkyl – inte lagervärdering')
        ->assertSee('23,85')
        ->assertSee('14,1 %');

    Livewire::test(MenuPage::class)->assertSee('Pasta med svamp')->assertSee('23,85');
    Livewire::test(RecipesPage::class)->assertSee('Vitlökssmör')->assertSee('108,40');
});

it('keeps an approver out of the kitchen', function () {
    $approver = Bookkeeping::member($this->company, 'approver');
    $this->company->unsetRelation('users');
    $this->actingAs($approver);

    $this->get(route('kitchen.recipes.index'))->assertForbidden();
    $this->get(route('kitchen.waste'))->assertForbidden();
});

it('shows the variance page only to kitchen:manage, and empty states without data', function () {
    Livewire::test(VariancePage::class)->assertSee('Det behövs två inventeringar');
    Livewire::test(WastePage::class)->assertSee('Inget svinn registrerat');
});
