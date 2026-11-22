<?php

declare(strict_types=1);

use App\Actions\Signing\ManageSigningProviders;
use App\Domain\Signing\Pdf\FakePdfEngine;
use App\Domain\Signing\Verification\SignerChecks;
use App\Livewire\Company\SigningProviderSettings;
use App\Models\Company;
use App\Models\InstanceSetting;
use App\Models\SigningProvider;
use App\Models\User;
use App\Support\Instance\InstanceSettings;
use App\Support\Tenancy\CompanyScope;
use App\Support\Tenancy\CurrentCompany;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Livewire\Livewire;
use Tests\Support\Bookkeeping;

/*
 * AGENTS.md §13.2 for external signing providers (ADR-046 amendment, gap
 * 12, S17): company A never sees B's provider, never offers it to a sender,
 * and finds none by id (404, not 403).
 */

/** @return array{0: User, 1: Company, 2: SigningProvider} */
function s17TenancyCompany(string $name): array
{
    [$owner, $company] = Bookkeeping::company();

    $provider = CurrentCompany::run($company, fn (): SigningProvider => app(ManageSigningProviders::class)->add($owner, $company, [
        'driver' => 'fake',
        'name' => "{$name} underskrift",
        'base_url' => 'https://underskrift.example/csc/v2',
        'client_id' => "{$name}-klient",
        'client_secret' => "{$name}-hemlighet",
        'claimed_level' => 'QES',
    ]));

    return [$owner, $company, $provider];
}

beforeEach(function () {
    config(['nonox.signing.providers.allow_fake' => true]);
    FakePdfEngine::install();
    InstanceSetting::query()->updateOrCreate(['key' => InstanceSettings::SIGNING_PROVIDERS_ENABLED], ['value' => true]);
    InstanceSettings::current()->forget();
    [$this->ownerA, $this->companyA, $this->providerA] = s17TenancyCompany('Alfa');
    [$this->ownerB, $this->companyB, $this->providerB] = s17TenancyCompany('Beta');
    CurrentCompany::set($this->companyA);
    $this->actingAs($this->ownerA);
});

afterEach(function () {
    InstanceSettings::current()->forget();
});

it('never shows or offers another company\'s provider', function () {
    expect(SigningProvider::query()->pluck('id')->all())->toBe([$this->providerA->id])
        ->and(array_column(SignerChecks::providerOptions(), 'value'))->toBe(["PROVIDER:{$this->providerA->id}"])
        ->and(CompanyScope::bypass(fn () => SigningProvider::query()->count()))->toBe(2);

    Livewire::test(SigningProviderSettings::class, ['company' => $this->companyA])
        ->assertSee('Alfa underskrift')
        ->assertDontSee('Beta underskrift');
});

it('answers 404 for another company\'s provider id, and leaves it untouched', function () {
    expect(fn () => app(ManageSigningProviders::class)->retire($this->ownerA, $this->companyA, $this->providerB->id))->toThrow(ModelNotFoundException::class);

    Livewire::test(SigningProviderSettings::class, ['company' => $this->companyA])
        ->call('retire', $this->providerB->id)
        ->assertNotFound();

    expect(CompanyScope::bypass(fn () => SigningProvider::query()->find($this->providerB->id)?->retired_at))->toBeNull();
});

it('refuses a member of A on B\'s settings', function () {
    Livewire::test(SigningProviderSettings::class, ['company' => $this->companyB])->assertForbidden();
});
