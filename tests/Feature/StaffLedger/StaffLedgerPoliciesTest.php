<?php

declare(strict_types=1);

use App\Modules\ModuleManager;
use App\Modules\StaffLedger\Models\Entry;
use App\Modules\StaffLedger\Models\Location;
use App\Modules\StaffLedger\Models\Person;
use App\Support\Tenancy\CurrentCompany;
use Illuminate\Support\Facades\Gate;
use Tests\Support\Bookkeeping;
use Tests\Support\StaffLedger;

beforeEach(function () {
    [$this->owner, $this->company] = Bookkeeping::company();
    CurrentCompany::set($this->company);
    StaffLedger::enable($this->company, $this->owner);
});

/*
 * Allowed abilities per role: view (ledger, people, locations), manage
 * (people, locations, kiosks, manual entries, corrections), sensitive (full
 * personal numbers), control (control visit), settings, self (own check-in).
 */
dataset('roles', [
    'owner' => ['owner', ['view', 'manage', 'sensitive', 'control', 'settings']],
    'admin' => ['admin', ['view', 'manage', 'sensitive', 'control', 'settings']],
    'accountant' => ['accountant', ['view', 'control']],
    'bookkeeper' => ['bookkeeper', []],
    'approver' => ['approver', []],
    'auditor' => ['auditor', ['view']],
    'staff ledger manager' => ['staff_ledger_manager', ['view', 'manage', 'sensitive', 'control']],
    'staff ledger staff' => ['staff_ledger_staff', ['self']],
    'employee' => ['employee', ['self']],
    'scheduler' => ['scheduler', ['self']],
]);

it('allows and denies per role', function (string $role, array $allowed) {
    $user = $role === 'owner' ? $this->owner : Bookkeeping::member($this->company, $role);
    $gate = Gate::forUser($user);

    $abilities = [
        'view' => $gate->allows('viewAny', [Entry::class, $this->company]) && $gate->allows('viewAny', [Person::class, $this->company]) && $gate->allows('viewAny', [Location::class, $this->company]),
        'manage' => $gate->allows('manage', [Person::class, $this->company]) && $gate->allows('manage', [Location::class, $this->company]) && $gate->allows('record', [Entry::class, $this->company]) && $gate->allows('correct', [Entry::class, $this->company]),
        'sensitive' => $gate->allows('viewSensitive', [Person::class, $this->company]),
        'control' => $gate->allows('controlVisit', [Entry::class, $this->company]),
        'settings' => $user->hasTeamPermission($this->company, 'staff-ledger:settings'),
        'self' => $user->hasTeamPermission($this->company, 'staff-ledger:self'),
    ];

    expect(array_keys(array_filter($abilities)))->toBe($allowed);
})->with('roles');

it('lets a person check in on their phone only as themselves', function () {
    $staff = Bookkeeping::member($this->company, 'staff_ledger_staff');
    $colleague = Bookkeeping::member($this->company, 'staff_ledger_staff');
    CurrentCompany::set($this->company);
    $person = StaffLedger::person($this->owner, $this->company, overrides: ['user_id' => $staff->id]);

    expect(Gate::forUser($staff)->allows('checkInAs', $person))->toBeTrue()
        ->and(Gate::forUser($colleague)->allows('checkInAs', $person))->toBeFalse()
        ->and(Gate::forUser($this->owner)->allows('checkInAs', $person))->toBeFalse();
});

it('denies everything to members of another company', function () {
    [, $other] = Bookkeeping::company();
    $stranger = Bookkeeping::member($other, 'admin');

    expect(Gate::forUser($stranger)->allows('viewAny', [Entry::class, $this->company]))->toBeFalse()
        ->and(Gate::forUser($stranger)->allows('controlVisit', [Entry::class, $this->company]))->toBeFalse();
});

it('keeps only the archive permissions while the module is off', function () {
    app(ModuleManager::class)->disable('staff_ledger', $this->company, $this->owner, confirmed: true);

    expect($this->owner->hasTeamPermission($this->company, 'staff-ledger:view'))->toBeTrue()
        ->and($this->owner->hasTeamPermission($this->company, 'staff-ledger:control-visit'))->toBeTrue()
        ->and($this->owner->hasTeamPermission($this->company, 'staff-ledger:manage'))->toBeFalse()
        ->and(Bookkeeping::member($this->company, 'staff_ledger_staff')->hasTeamPermission($this->company, 'staff-ledger:self'))->toBeFalse();
});
