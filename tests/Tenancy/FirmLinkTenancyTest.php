<?php

declare(strict_types=1);

use App\Actions\Company\AccountingFirmLink;
use App\Actions\Company\UpdateAccountingFirmSettings;
use App\Livewire\Company\FirmSettings;
use App\Models\User;
use App\Support\Branding\FirmBranding;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;

/*
 * AGENTS.md §13.2 for accounting-firm branding (ADR-030): the link between a
 * client and its firm gives no access either way, a firm cannot be linked to
 * a company it has no one in, and a client sees nothing of the firm's other
 * clients.
 */
beforeEach(function () {
    Cache::flush();
    $this->firmOwner = User::factory()->withCompany(fn ($factory) => $factory->state(['name' => 'Byrå AB']))->create();
    $this->firm = $this->firmOwner->currentCompany();
    app(UpdateAccountingFirmSettings::class)($this->firmOwner, $this->firm, true, true);

    $this->consultant = User::factory()->create();
    $this->firm->users()->attach($this->consultant, ['role' => 'accountant']);

    foreach (['A' => 'Kund A AB', 'B' => 'Hemlig Kund B AB'] as $key => $name) {
        $owner = User::factory()->withCompany(fn ($factory) => $factory->state(['name' => $name]))->create();
        $company = $owner->currentCompany();
        $company->users()->attach($this->consultant, ['role' => 'accountant']);
        app(AccountingFirmLink::class)->link($owner, $company, $this->firm->id);
        $this->{"owner{$key}"} = $owner;
        $this->{"company{$key}"} = $company;
    }
});

it('cannot link a firm to a company it has no one in, nor let the firm link itself', function () {
    $outsiderOwner = User::factory()->withCompany()->create();
    $outsider = $outsiderOwner->currentCompany();

    expect(fn () => app(AccountingFirmLink::class)->link($outsiderOwner, $outsider, $this->firm->id))->toThrow(ValidationException::class)
        ->and(fn () => app(AccountingFirmLink::class)->link($this->firmOwner, $outsider, $this->firm->id))->toThrow(AuthorizationException::class)
        ->and(fn () => app(AccountingFirmLink::class)->link($this->consultant, $this->companyA, $this->firm->id))->toThrow(AuthorizationException::class);

    expect($outsider->fresh()->accounting_firm_id)->toBeNull();
});

it('shows a client nothing of the firm\'s other clients', function () {
    $this->actingAs($this->ownerA->fresh());

    $this->get(route('dashboard'))->assertOk()->assertDontSee('Hemlig Kund B AB');
    $this->get(route('teams.show', ['company' => $this->companyA->id, 'flik' => 'varumarke']))->assertOk()->assertSee('Byrå AB')->assertDontSee('Hemlig Kund B AB');
    $this->get(route('teams.firm-logo', $this->companyB->id))->assertNotFound();
    $this->get(route('teams.show', $this->companyB->id))->assertNotFound();

    Livewire::test(FirmSettings::class, ['company' => $this->companyA])->assertDontSee('Hemlig Kund B AB');

    expect(FirmBranding::clientFacing($this->companyA->fresh())->title())->toBe('Byrå AB · för Kund A AB');
});

it('gives the firm\'s owner the clients\' names and nothing else', function () {
    $this->actingAs($this->firmOwner->fresh());

    Livewire::test(FirmSettings::class, ['company' => $this->firm])->assertSee('Kund A AB')->assertSee('Hemlig Kund B AB');

    $this->get(route('teams.show', $this->companyA->id))->assertNotFound();
    $this->get(route('dashboard'))->assertOk();
    expect($this->firmOwner->fresh()->companies()->pluck('id')->all())->toBe([$this->firm->id]);
});

it('refuses the firm owner a client page, a client\'s settings and its logo by id', function () {
    $this->actingAs($this->firmOwner->fresh());

    $this->put(route('current-team.update'), ['team_id' => $this->companyA->id])->assertNotFound();
    Livewire::test(FirmSettings::class, ['company' => $this->companyA])->assertForbidden();
    $this->get(route('teams.logo', $this->companyA->id))->assertNotFound();
});
