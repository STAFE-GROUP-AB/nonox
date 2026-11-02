<?php

declare(strict_types=1);

use App\Modules\Inventory\InventoryModule;
use App\Modules\Inventory\InventorySettings;
use App\Modules\Inventory\Livewire\InventorySettingsForm;
use App\Modules\Inventory\Models\StockMovement;
use App\Modules\ModuleManager;
use App\Modules\ModuleRegistry;
use Illuminate\Support\Facades\Gate;
use Livewire\Livewire;
use Tests\Support\Bookkeeping;
use Tests\Support\Inventory;

/*
 * Lager as a module (ADR-039): off by default, 404 while off, its pages in
 * the sidebar while on, the Lagerpersonal role, and its settings.
 */

const INVENTORY_PAGES = ['inventory.overview', 'inventory.articles', 'inventory.locations', 'inventory.receipts', 'inventory.pickLists', 'inventory.counts', 'inventory.reports', 'inventory.settings'];

beforeEach(function () {
    [$this->owner, $this->company] = Bookkeeping::company();
    $this->actingAs($this->owner);
});

it('answers 404 until it is turned on, then shows every page with Swedish URLs', function () {
    foreach (INVENTORY_PAGES as $route) {
        $this->get(route($route))->assertNotFound();
    }

    Inventory::enable($this->company, $this->owner);

    foreach (INVENTORY_PAGES as $route) {
        expect(parse_url(route($route), PHP_URL_PATH))->toStartWith('/lager');
        $this->get(route($route))->assertOk();
    }

    $this->get(route('dashboard'))->assertSee(route('inventory.overview'), escape: false);
    expect(app(ModuleManager::class)->settings(InventoryModule::KEY, $this->company))->toEqual(InventorySettings::DEFAULTS);

    [$otherOwner] = Bookkeeping::company();
    $this->actingAs($otherOwner)->get(route('inventory.overview'))->assertNotFound();
});

dataset('inventory roles', [
    'owner' => ['owner', ['viewAny', 'count', 'manage', 'settings', 'seePrices']],
    'admin' => ['admin', ['viewAny', 'count', 'manage', 'settings', 'seePrices']],
    'accountant' => ['accountant', ['viewAny', 'count', 'manage', 'settings', 'seePrices']],
    'bookkeeper' => ['bookkeeper', ['viewAny', 'count', 'manage', 'seePrices']],
    'auditor' => ['auditor', ['viewAny', 'seePrices']],
    'approver' => ['approver', []],
    'lagerpersonal' => ['stock_clerk', ['viewAny', 'count']],
]);

it('allows and denies per role, and grants nothing while the module is off', function (string $role, array $allowed) {
    Inventory::enable($this->company, $this->owner);
    $user = $role === 'owner' ? $this->owner : Bookkeeping::member($this->company, $role);
    $gate = Gate::forUser($user);

    $abilities = array_values(array_filter(['viewAny', 'count', 'manage', 'settings', 'seePrices'], fn (string $ability): bool => $gate->allows($ability, [StockMovement::class, $this->company])));
    expect($abilities)->toBe($allowed);

    app(ModuleManager::class)->disable(InventoryModule::KEY, $this->company, $this->owner, confirmed: true);
    expect($user->fresh()->hasTeamPermission($this->company, 'inventory:view'))->toBeFalse();
})->with('inventory roles');

it('offers Lagerpersonal only while Lager is on, without prices or settings', function () {
    expect(app(ModuleRegistry::class)->moduleForRole('stock_clerk')?->key())->toBe(InventoryModule::KEY);

    $clerk = Bookkeeping::member($this->company, 'stock_clerk');
    expect($clerk->hasTeamPermission($this->company, 'inventory:view'))->toBeFalse();

    Inventory::enable($this->company, $this->owner);
    $clerk = $clerk->fresh();

    $this->actingAs($clerk)->get(route('inventory.pickLists'))->assertOk();
    $this->actingAs($clerk)->get(route('inventory.overview'))->assertOk()->assertDontSee('Lagervärde i dag');
    $this->actingAs($clerk)->get(route('invoicing.articles'))->assertForbidden();
    $this->actingAs($clerk)->get(route('dashboard'))->assertDontSee(route('inventory.settings'), escape: false);
});

it('saves the settings and refuses accounts outside their groups', function () {
    Inventory::enable($this->company, $this->owner);

    Livewire::test(InventorySettingsForm::class, ['company' => $this->company])
        ->set('settings.valuation_method', 'AVERAGE')
        ->set('settings.allow_negative_stock', true)
        ->set('settings.stock_account', 1410)
        ->set('settings.change_account', 4910)
        ->call('save')
        ->assertHasNoErrors()
        ->assertDispatched('toast', message: 'Inställningarna för lagret är sparade.');

    expect(InventorySettings::for($this->company))->toMatchArray(['valuation_method' => 'AVERAGE', 'allow_negative_stock' => true, 'stock_account' => 1410, 'change_account' => 4910]);

    Livewire::test(InventorySettingsForm::class, ['company' => $this->company])
        ->set('settings.change_account', 3001)
        ->call('save')
        ->assertHasErrors('settings.change_account');

    $clerk = Bookkeeping::member($this->company, 'stock_clerk');
    $this->actingAs($clerk);
    Livewire::test(InventorySettingsForm::class, ['company' => $this->company])->assertForbidden();
});
