<?php

declare(strict_types=1);

use App\Actions\Company\ArchiveCompany;
use App\Actions\Company\TransferOwnership;
use App\Actions\Privacy\DeleteAccount;
use App\Livewire\Company\OwnershipSettings;
use App\Models\AuditLog;
use App\Models\Company;
use App\Models\User;
use App\Support\Tenancy\CurrentCompany;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Tests\Support\Bookkeeping;

beforeEach(function () {
    [$this->owner, $this->company] = Bookkeeping::company();
    CurrentCompany::set($this->company);
    $this->actingAs($this->owner);
    $this->accountant = Bookkeeping::member($this->company, 'accountant');
});

function roleIn(Company $company, User $user): ?string
{
    $role = DB::table('team_user')->where('team_id', $company->id)->where('user_id', $user->id)->value('role');

    return $role === null ? null : (string) $role;
}

it('makes the chosen member owner and keeps the previous owner as administrator', function () {
    $newOwner = app(TransferOwnership::class)($this->owner, $this->company, $this->accountant->id);

    $company = $this->company->fresh();

    expect($newOwner->id)->toBe($this->accountant->id)
        ->and($company->user_id)->toBe($this->accountant->id)
        ->and(roleIn($company, $this->accountant))->toBeNull()
        ->and(roleIn($company, $this->owner))->toBe('admin')
        ->and($this->accountant->fresh()->ownsTeam($company))->toBeTrue()
        ->and($this->owner->fresh()->ownsTeam($company))->toBeFalse()
        ->and($this->owner->fresh()->hasTeamPermission($company, 'members:manage'))->toBeTrue()
        ->and($this->owner->fresh()->hasTeamPermission($company, 'company:archive'))->toBeFalse()
        ->and($this->accountant->fresh()->hasTeamPermission($company, 'company:archive'))->toBeTrue();

    $entry = AuditLog::query()->where('action', 'company.ownership_transferred')->sole();

    expect($entry->company_id)->toBe($company->id)
        ->and($entry->user_id)->toBe($this->owner->id)
        ->and($entry->payload)->toMatchArray(['from_user_id' => $this->owner->id, 'to_user_id' => $this->accountant->id, 'previous_role_of_new_owner' => 'accountant']);
});

it('lets only the owner transfer', function () {
    $admin = Bookkeeping::member($this->company, 'admin');

    expect(fn () => app(TransferOwnership::class)($admin, $this->company, $this->accountant->id))->toThrow(AuthorizationException::class)
        ->and(fn () => app(TransferOwnership::class)($this->accountant, $this->company, $admin->id))->toThrow(AuthorizationException::class);

    [$stranger] = Bookkeeping::company();

    expect(fn () => app(TransferOwnership::class)($stranger, $this->company, $this->accountant->id))->toThrow(AuthorizationException::class)
        ->and($this->company->fresh()->user_id)->toBe($this->owner->id);
});

it('transfers only to a member with a company role', function () {
    [$outsider] = Bookkeeping::company();
    $employee = User::factory()->create();
    $this->company->users()->attach($employee, ['role' => 'employee']);

    expect(fn () => app(TransferOwnership::class)($this->owner, $this->company, $outsider->id))->toThrow(ValidationException::class, 'Välj någon som redan finns i företaget.')
        ->and(fn () => app(TransferOwnership::class)($this->owner, $this->company, $this->owner->id))->toThrow(ValidationException::class)
        ->and(fn () => app(TransferOwnership::class)($this->owner, $this->company, $employee->id))->toThrow(ValidationException::class, 'måste ha en roll i företaget');

    expect($this->company->fresh()->user_id)->toBe($this->owner->id)
        ->and(AuditLog::query()->where('action', 'company.ownership_transferred')->exists())->toBeFalse();
});

it('transfers from the owner tab with a confirmation and a toast after the redirect', function () {
    $this->get(route('teams.show', ['company' => $this->company->id, 'flik' => 'agarskap']))
        ->assertOk()
        ->assertSee('Överlåt ägarskapet')
        ->assertSee('Vill du överlåta')
        ->assertSee($this->accountant->name);

    Livewire::test(OwnershipSettings::class, ['company' => $this->company])
        ->set('newOwnerId', (string) $this->accountant->id)
        ->call('transfer')
        ->assertRedirect(route('teams.show', ['company' => $this->company->id]));

    expect($this->company->fresh()->user_id)->toBe($this->accountant->id)
        ->and(array_column((array) session('toasts', []), 'message'))->toBe([$this->accountant->name.' är nu ägare till '.$this->company->name.'. Du är kvar som administratör.']);

    $this->actingAs($this->owner->fresh())
        ->get(route('teams.show', ['company' => $this->company->id, 'flik' => 'agarskap']))
        ->assertOk()
        ->assertDontSee('Ägarskap och arkiv');
});

it('shows an error toast when no member is chosen', function () {
    Livewire::test(OwnershipSettings::class, ['company' => $this->company])
        ->call('transfer')
        ->assertDispatched('toast', type: 'error');
});

it('keeps the tab from members who are not the owner', function () {
    $admin = Bookkeeping::member($this->company, 'admin');
    $this->actingAs($admin);

    $this->get(route('teams.show', ['company' => $this->company->id, 'flik' => 'agarskap']))->assertOk()->assertDontSee('Ägarskap och arkiv');
    Livewire::test(OwnershipSettings::class, ['company' => $this->company])->assertForbidden();
});

it('can hand over an archived company, and the previous owner can then delete their account', function () {
    app(ArchiveCompany::class)($this->owner, $this->company, confirmedExport: true);
    app(TransferOwnership::class)($this->owner, $this->company->fresh(), $this->accountant->id);

    expect($this->company->fresh()->user_id)->toBe($this->accountant->id)
        ->and(app(DeleteAccount::class)->ownedCompanies($this->owner->fresh()))->toBe([])
        ->and(app(DeleteAccount::class)($this->owner->fresh()))->toBeIn([DeleteAccount::DELETED, DeleteAccount::PSEUDONYMISED]);
});

it('lets the previous owner delete their account after handing over a company in use', function () {
    app(TransferOwnership::class)($this->owner, $this->company, $this->accountant->id);

    expect(app(DeleteAccount::class)($this->owner->fresh()))->toBeIn([DeleteAccount::DELETED, DeleteAccount::PSEUDONYMISED])
        ->and(roleIn($this->company, $this->owner))->toBeNull()
        ->and($this->company->fresh()->user_id)->toBe($this->accountant->id);
});
