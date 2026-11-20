<?php

declare(strict_types=1);

use App\Domain\Company\CompanyRole;
use App\Models\CustomerPriceGroup;
use App\Models\PriceAgreement;
use App\Models\PriceList;
use App\Models\User;
use App\Modules\Crm\CrmModule;
use App\Modules\ModuleManager;
use App\Support\Tenancy\CurrentCompany;
use Laravel\Jetstream\Jetstream;
use Tests\Support\Bookkeeping;
use Tests\Support\Crm;

/*
 * ADR-082 §Permissions and AGENTS.md §4 (owner-approved 2026-11-20): the five
 * price permissions per role, the CRM seller's two only while CRM is on, and
 * the policies of price lists, customer price groups and price agreements.
 */

const PRICE_PERMISSIONS = ['prices:view', 'price-lists:manage', 'price-agreements:manage', 'prices:view-cost', 'prices:approve-below-floor'];

/** The table in ADR-082 §Permissions; a role not listed has none of them. */
const PRICE_ROLE_MATRIX = [
    'admin' => ['prices:view', 'price-lists:manage', 'price-agreements:manage', 'prices:view-cost', 'prices:approve-below-floor'],
    'accountant' => ['prices:view', 'price-lists:manage', 'price-agreements:manage', 'prices:view-cost', 'prices:approve-below-floor'],
    'bookkeeper' => ['prices:view', 'price-agreements:manage', 'prices:view-cost'],
    'approver' => [],
    'auditor' => ['prices:view', 'prices:view-cost'],
    'unit_manager' => [],
];

beforeEach(function () {
    [$this->owner, $this->company] = Bookkeeping::company();
    CurrentCompany::set($this->company);

    $this->list = PriceList::create(['code' => 'ATER', 'name' => 'Återförsäljare', 'created_by' => $this->owner->id]);
    $this->group = CustomerPriceGroup::create(['code' => 'ATER', 'name' => 'Återförsäljare', 'price_list_id' => $this->list->id]);
    $customer = Crm::customer($this->company, $this->owner);
    $this->agreement = PriceAgreement::create([
        'number' => 1, 'customer_id' => $customer->id, 'title' => 'Ramavtal', 'valid_from' => '2026-01-01', 'created_by' => $this->owner->id,
    ]);
});

it('adds the five price permissions to the core list from AGENTS.md §4', function () {
    expect(array_diff(PRICE_PERMISSIONS, CompanyRole::PERMISSIONS))->toBe([])
        ->and(array_diff(PRICE_PERMISSIONS, Jetstream::$permissions))->toBe([]);
});

it('gives each core role exactly the price permissions in the ADR table', function (string $role) {
    $member = Bookkeeping::member($this->company, $role);
    $company = $this->company->fresh();

    foreach (PRICE_PERMISSIONS as $permission) {
        expect($member->hasTeamPermission($company, $permission))->toBe(in_array($permission, PRICE_ROLE_MATRIX[$role], true), "{$role} {$permission}");
    }
})->with(array_keys(PRICE_ROLE_MATRIX));

it('gives the owner every price permission', function () {
    foreach (PRICE_PERMISSIONS as $permission) {
        expect($this->owner->hasTeamPermission($this->company, $permission))->toBeTrue($permission);
    }
});

it('gives the CRM seller prices:view and price-agreements:manage only while CRM is on', function () {
    $seller = Bookkeeping::member($this->company, CrmModule::ROLE);

    foreach (PRICE_PERMISSIONS as $permission) {
        expect($seller->hasTeamPermission($this->company->fresh(), $permission))->toBeFalse("CRM off: {$permission}");
    }

    Crm::enable($this->company, $this->owner);
    $seller = $seller->fresh();

    foreach (PRICE_PERMISSIONS as $permission) {
        expect($seller->hasTeamPermission($this->company->fresh(), $permission))
            ->toBe(in_array($permission, ['prices:view', 'price-agreements:manage'], true), "CRM on: {$permission}");
    }

    expect($seller->can('viewAny', [PriceAgreement::class, $this->company->fresh()]))->toBeTrue()
        ->and($seller->can('update', $this->agreement))->toBeTrue()
        ->and($seller->can('update', $this->list))->toBeFalse();

    app(ModuleManager::class)->disable(CrmModule::KEY, $this->company->fresh(), $this->owner, confirmed: true);
    $seller = $seller->fresh();

    expect($seller->hasTeamPermission($this->company->fresh(), 'prices:view'))->toBeFalse()
        ->and($seller->can('update', $this->agreement))->toBeFalse();
});

it('keeps the module roles\' core grants to core permissions and to roles of a module', function () {
    foreach (CompanyRole::MODULE_ROLE_PERMISSIONS as $role => $permissions) {
        expect(array_diff($permissions, CompanyRole::PERMISSIONS))->toBe([], $role)
            ->and(in_array($role, array_map(fn (CompanyRole $each): string => $each->value, CompanyRole::cases()), true))->toBeFalse($role)
            ->and(Jetstream::findRole($role)?->permissions ?? [])->toContain(...$permissions);
    }
});

it('lets the price list and price group policies follow prices:view and price-lists:manage', function (string $role) {
    $member = Bookkeeping::member($this->company, $role);
    $company = $this->company->fresh();
    $view = in_array('prices:view', PRICE_ROLE_MATRIX[$role], true);
    $manage = in_array('price-lists:manage', PRICE_ROLE_MATRIX[$role], true);

    foreach ([[PriceList::class, $this->list], [CustomerPriceGroup::class, $this->group]] as [$class, $record]) {
        expect($member->can('viewAny', [$class, $company]))->toBe($view, "{$role} viewAny {$class}")
            ->and($member->can('view', $record))->toBe($view, "{$role} view {$class}")
            ->and($member->can('create', [$class, $company]))->toBe($manage, "{$role} create {$class}")
            ->and($member->can('update', $record))->toBe($manage, "{$role} update {$class}");
    }
})->with(array_keys(PRICE_ROLE_MATRIX));

it('lets the price agreement policy follow prices:view and price-agreements:manage', function (string $role) {
    $member = Bookkeeping::member($this->company, $role);
    $company = $this->company->fresh();
    $view = in_array('prices:view', PRICE_ROLE_MATRIX[$role], true);
    $manage = in_array('price-agreements:manage', PRICE_ROLE_MATRIX[$role], true);

    expect($member->can('viewAny', [PriceAgreement::class, $company]))->toBe($view, $role)
        ->and($member->can('view', $this->agreement))->toBe($view, $role)
        ->and($member->can('create', [PriceAgreement::class, $company]))->toBe($manage, $role)
        ->and($member->can('update', $this->agreement))->toBe($manage, $role);
})->with(array_keys(PRICE_ROLE_MATRIX));

it('gives nothing to someone outside the company, even an owner of another company', function () {
    $outsider = User::factory()->withCompany()->create();

    foreach ([$this->list, $this->group, $this->agreement] as $record) {
        expect($outsider->can('view', $record))->toBeFalse()
            ->and($outsider->can('update', $record))->toBeFalse();
    }

    expect($outsider->can('create', [PriceList::class, $this->company]))->toBeFalse()
        ->and($outsider->hasTeamPermission($this->company, 'prices:view'))->toBeFalse();
});
