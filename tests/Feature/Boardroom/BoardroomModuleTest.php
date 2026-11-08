<?php

declare(strict_types=1);

use App\Domain\Company\LegalForm;
use App\Models\Company;
use App\Modules\Boardroom\Actions\RecordFirmAuthorisation;
use App\Modules\Boardroom\BoardroomModule;
use App\Modules\ModuleManager;
use App\Support\Tenancy\CurrentCompany;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Tests\Support\Boardroom;
use Tests\Support\Bookkeeping;

/*
 * The module itself (ADR-070 §1, owner decision 2): offered only to
 * aktiebolag, the four permissions and their roles, and the rule that an
 * accounting firm's consultant records entries only with the board's
 * authorisation (ABL 5 kap. 9 §).
 */

beforeEach(function () {
    [$this->owner, $this->company] = Bookkeeping::company();
    CurrentCompany::set($this->company);
});

it('is offered to an aktiebolag and to no other company form', function () {
    $modules = app(ModuleManager::class);

    expect($modules->enableCheck(BoardroomModule::KEY, $this->company)->allowed())->toBeTrue();

    Livewire::actingAs($this->owner)->test('modules.company-modules-tab', ['company' => $this->company])
        ->assertSee('Aktiebok och styrelserum');

    $this->company->forceFill(['legal_form' => LegalForm::Hb])->save();

    expect($modules->enableCheck(BoardroomModule::KEY, $this->company->fresh())->blockers)->toBe(['Aktiebok och styrelserum finns bara för aktiebolag.'])
        ->and(fn () => $modules->enable(BoardroomModule::KEY, $this->company->fresh(), $this->owner))->toThrow(ValidationException::class);

    Livewire::actingAs($this->owner)->test('modules.company-modules-tab', ['company' => $this->company->fresh()])
        ->assertDontSee('Aktiebok och styrelserum');
});

it('gives the four permissions to owner, admin and accountant, and the view permissions to the auditor', function () {
    Boardroom::enable($this->company, $this->owner);
    $company = $this->company->fresh();
    $all = BoardroomModule::PERMISSIONS;

    expect(collect($all)->every(fn (string $p): bool => $this->owner->hasTeamPermission($company, $p)))->toBeTrue();

    foreach (['admin', 'accountant'] as $role) {
        $user = Bookkeeping::member($company, $role);
        $fresh = $company->fresh();
        expect(collect($all)->every(fn (string $p): bool => $user->hasTeamPermission($fresh, $p)))->toBeTrue("{$role} lacks a permission");
    }

    $auditor = Bookkeeping::member($company, 'auditor');
    $company = $company->fresh();
    expect($auditor->hasTeamPermission($company, 'share-register:view'))->toBeTrue()
        ->and($auditor->hasTeamPermission($company, 'meetings:view'))->toBeTrue()
        ->and($auditor->hasTeamPermission($company, 'share-register:manage'))->toBeFalse()
        ->and($auditor->hasTeamPermission($company, 'meetings:manage'))->toBeFalse();

    foreach (['bookkeeper', 'approver'] as $role) {
        $user = Bookkeeping::member($company, $role);
        $fresh = $company->fresh();
        expect(collect($all)->contains(fn (string $p): bool => $user->hasTeamPermission($fresh, $p)))->toBeFalse("{$role} has a permission");
    }
});

it('keeps the register readable but closed for entries when the module is off', function () {
    Boardroom::enable($this->company, $this->owner);
    $holder = Boardroom::person($this->owner, $this->company);
    Boardroom::formation($this->owner, $this->company, $holder);

    app(ModuleManager::class)->disable(BoardroomModule::KEY, $this->company, $this->owner, confirmed: true);
    $company = $this->company->fresh();

    expect($this->owner->hasTeamPermission($company, 'share-register:view'))->toBeTrue()
        ->and($this->owner->hasTeamPermission($company, 'share-register:manage'))->toBeFalse();

    $this->actingAs($this->owner)->get('/aktiebok')->assertOk()->assertSee('Anna Andersson');
    $this->actingAs($this->owner)->get('/aktiebok/agare')->assertNotFound();
});

it('lets a firm consultant record entries only after the board\'s authorisation is recorded', function () {
    Boardroom::enable($this->company, $this->owner);
    $consultant = Bookkeeping::member($this->company, 'accountant');
    $holder = Boardroom::person($this->owner, $this->company);

    expect(fn () => Boardroom::formation($consultant, $this->company, $holder))->toThrow(AuthorizationException::class, 'Styrelsen har inte bemyndigat byrån');

    // A consultant cannot authorise the firm itself.
    expect(fn () => app(RecordFirmAuthorisation::class)($consultant, $this->company, ['decided_on' => '2026-11-01', 'reference' => 'Styrelseprotokoll 2026-11-01 § 4']))->toThrow(AuthorizationException::class);

    app(RecordFirmAuthorisation::class)($this->owner, $this->company, ['decided_on' => '2026-11-01', 'reference' => 'Styrelseprotokoll 2026-11-01 § 4']);

    $event = Boardroom::formation($consultant, $this->company, $holder);

    expect($event->user_id)->toBe($consultant->id);
});

it('refuses entries from a bookkeeper', function () {
    Boardroom::enable($this->company, $this->owner);
    $bookkeeper = Bookkeeping::member($this->company, 'bookkeeper');
    $holder = Boardroom::person($this->owner, $this->company);

    expect(fn () => Boardroom::formation($bookkeeper, $this->company, $holder))->toThrow(AuthorizationException::class);
});

it('is not offered to a company created as another form', function () {
    $hb = Company::factory()->create(['legal_form' => LegalForm::Hb]);

    expect(app(BoardroomModule::class)->isOfferedTo($hb))->toBeFalse();
});
