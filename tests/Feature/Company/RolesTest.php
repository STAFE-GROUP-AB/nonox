<?php

declare(strict_types=1);

use App\Domain\Company\CompanyRole;
use App\Models\Company;
use App\Models\User;
use Laravel\Jetstream\Jetstream;

function memberWithRole(Company $company, CompanyRole $role): User
{
    $user = User::factory()->create();
    $company->users()->attach($user, ['role' => $role->value]);

    return $user->fresh();
}

it('registers the five member roles from AGENTS.md §4, the payroll and staff ledger modules\' two each and Planering\'s scheduler and Arbetsorder\'s field worker', function () {
    expect(collect(Jetstream::$roles)->keys()->sort()->values()->all())
        ->toBe(['accountant', 'admin', 'approver', 'auditor', 'bookkeeper', 'employee', 'field_worker', 'payroll_admin', 'sales', 'scheduler', 'staff_ledger_manager', 'staff_ledger_staff', 'stock_clerk']);
});

it('uses only permission keys from AGENTS.md §4', function (CompanyRole $role) {
    expect(array_diff($role->permissions(), CompanyRole::PERMISSIONS))->toBe([]);
})->with(CompanyRole::cases());

it('gives the owner every permission', function () {
    $owner = User::factory()->withCompany()->create();
    $company = $owner->currentCompany();

    foreach (CompanyRole::PERMISSIONS as $permission) {
        expect($owner->hasTeamPermission($company, $permission))->toBeTrue();
    }
});

it('gives an admin everything except archiving the company', function () {
    $company = Company::factory()->create();
    $admin = memberWithRole($company, CompanyRole::Admin);

    expect($admin->hasTeamPermission($company, 'company:archive'))->toBeFalse()
        ->and($admin->hasTeamPermission($company, 'members:manage'))->toBeTrue()
        ->and($admin->hasTeamPermission($company, 'company:update'))->toBeTrue();
});

it('lets a bookkeeper post but not lock periods or manage members', function () {
    $company = Company::factory()->create();
    $bookkeeper = memberWithRole($company, CompanyRole::Bookkeeper);

    expect($bookkeeper->hasTeamPermission($company, 'vouchers:post'))->toBeTrue()
        ->and($bookkeeper->hasTeamPermission($company, 'periods:lock'))->toBeFalse()
        ->and($bookkeeper->hasTeamPermission($company, 'fiscal-years:manage'))->toBeFalse()
        ->and($bookkeeper->hasTeamPermission($company, 'members:manage'))->toBeFalse();
});

it('lets an approver approve but not create', function () {
    $company = Company::factory()->create();
    $approver = memberWithRole($company, CompanyRole::Approver);

    expect($approver->hasTeamPermission($company, 'payments:approve'))->toBeTrue()
        ->and($approver->hasTeamPermission($company, 'supplier-invoices:approve'))->toBeTrue()
        ->and($approver->hasTeamPermission($company, 'payments:create'))->toBeFalse()
        ->and($approver->hasTeamPermission($company, 'vouchers:post'))->toBeFalse();
});

it('keeps the auditor read-only', function () {
    $company = Company::factory()->create();
    $auditor = memberWithRole($company, CompanyRole::Auditor);

    expect($auditor->hasTeamPermission($company, 'vouchers:view'))->toBeTrue()
        ->and($auditor->hasTeamPermission($company, 'export:sie'))->toBeTrue();

    foreach (['vouchers:create', 'vouchers:post', 'invoices:create', 'company:update', 'import:sie'] as $permission) {
        expect($auditor->hasTeamPermission($company, $permission))->toBeFalse();
    }
});

it('gives no permissions in a company the user does not belong to', function () {
    $company = Company::factory()->create();
    $outsider = User::factory()->withCompany()->create();

    expect($outsider->hasTeamPermission($company, 'vouchers:view'))->toBeFalse()
        ->and($outsider->can('update', $company))->toBeFalse()
        ->and($outsider->can('view', $company))->toBeFalse();
});

it('checks company:update in the policy', function () {
    $company = Company::factory()->create();

    $admin = memberWithRole($company, CompanyRole::Admin);
    $accountant = memberWithRole($company, CompanyRole::Accountant);

    expect($admin->can('update', $company->fresh()))->toBeTrue()
        ->and($accountant->can('update', $company->fresh()))->toBeFalse();
});

it('labels the roles in Swedish', function () {
    expect(CompanyRole::Accountant->label())->toBe('Redovisningskonsult')
        ->and(Jetstream::findRole('auditor')->name)->toBe('Revisor');
});
