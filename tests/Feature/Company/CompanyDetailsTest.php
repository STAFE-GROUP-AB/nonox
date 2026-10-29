<?php

declare(strict_types=1);

use App\Actions\Company\UpdateCompanyDetails;
use App\Domain\Company\VatPeriod;
use App\Livewire\Company\CompanyDetailsForm;
use App\Models\Company;
use App\Models\User;
use App\Support\Luhn;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;

function detailsInput(array $overrides = []): array
{
    return [
        'name' => 'Nytt Namn AB',
        'address_line1' => 'Storgatan 1',
        'postal_code' => '111 22',
        'city' => 'Stockholm',
        'email' => null,
        'phone' => null,
        'website' => null,
        'bankgiro' => null,
        'plusgiro' => null,
        'vat_period' => 'QUARTERLY',
        'fiscal_year_start_month' => 7,
        'accounting_method' => 'KONTANTMETODEN',
        'reporting_framework' => 'K3',
        'f_skatt' => true,
        'employer_registered' => true,
        ...$overrides,
    ];
}

it('updates the company details and formats the bankgiro number', function () {
    $owner = User::factory()->withCompany()->create();
    $company = $owner->currentCompany();
    $orgNumber = $company->org_number;
    $bankgiro = '123456'.Luhn::checkDigit('123456');

    app(UpdateCompanyDetails::class)($owner, $company, detailsInput(['bankgiro' => $bankgiro]));

    $company->refresh();

    expect($company->name)->toBe('Nytt Namn AB')
        ->and($company->city)->toBe('Stockholm')
        ->and($company->fiscal_year_start_month)->toBe(7)
        ->and($company->bankgiro)->toBe(substr($bankgiro, 0, 3).'-'.substr($bankgiro, 3))
        ->and($company->org_number)->toBe($orgNumber);
});

it('ignores attempts to change the organisation number or legal form', function () {
    $owner = User::factory()->withCompany()->create();
    $company = $owner->currentCompany();
    $orgNumber = $company->org_number;

    app(UpdateCompanyDetails::class)($owner, $company, detailsInput([
        'org_number' => '559999-9991',
        'legal_form' => 'EF',
    ]));

    expect($company->refresh()->org_number)->toBe($orgNumber)
        ->and($company->legal_form->value)->toBe('AB');
});

it('drops the VAT number when the company stops reporting VAT', function () {
    $owner = User::factory()->withCompany()->create();
    $company = $owner->currentCompany();

    app(UpdateCompanyDetails::class)($owner, $company, detailsInput(['vat_period' => 'NONE']));

    expect($company->refresh()->vat_registered)->toBeFalse()
        ->and($company->vat_number)->toBeNull()
        ->and($company->vat_period)->toBe(VatPeriod::None);
});

it('rejects a bankgiro number with a wrong check digit', function () {
    $owner = User::factory()->withCompany()->create();
    $wrong = '123456'.((Luhn::checkDigit('123456') + 1) % 10);

    app(UpdateCompanyDetails::class)($owner, $owner->currentCompany(), detailsInput(['bankgiro' => $wrong]));
})->throws(ValidationException::class, 'Bankgironumret stämmer inte');

it('does not let a bookkeeper change the company details', function () {
    $company = Company::factory()->create();
    $bookkeeper = User::factory()->create();
    $company->users()->attach($bookkeeper, ['role' => 'bookkeeper']);

    app(UpdateCompanyDetails::class)($bookkeeper->fresh(), $company->fresh(), detailsInput());
})->throws(AuthorizationException::class);

it('saves from the settings page', function () {
    $owner = User::factory()->withCompany()->create();
    $company = $owner->currentCompany();
    $this->actingAs($owner);

    Livewire::test(CompanyDetailsForm::class, ['company' => $company])
        ->set('state.city', 'Göteborg')
        ->call('save')
        ->assertHasNoErrors()
        ->assertDispatched('toast', message: "Uppgifterna för {$company->name} är sparade.");

    expect($company->refresh()->city)->toBe('Göteborg');
});

it('shows the details read-only to members who may not change them', function () {
    $company = Company::factory()->create();
    $auditor = User::factory()->create();
    $company->users()->attach($auditor, ['role' => 'auditor']);
    $this->actingAs($auditor->fresh());

    Livewire::test(CompanyDetailsForm::class, ['company' => $company->fresh()])
        ->assertSee('bara ägare och administratörer kan ändra dem')
        ->assertDontSee('wire:click="save"', escape: false);
});
