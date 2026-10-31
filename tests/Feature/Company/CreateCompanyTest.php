<?php

declare(strict_types=1);

use App\Actions\Company\CreateCompany;
use App\Domain\Company\LegalForm;
use App\Domain\Company\VatPeriod;
use App\Http\Controllers\LegalPageController;
use App\Livewire\Company\CreateCompanyForm;
use App\Models\Company;
use App\Models\User;
use App\Support\Luhn;
use Database\Factories\CompanyFactory;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;

function validCompanyInput(array $overrides = []): array
{
    return [
        'name' => 'Testbolaget AB',
        'org_number' => CompanyFactory::organisationNumber()->digits,
        'legal_form' => 'AB',
        'fiscal_year_start_month' => 1,
        'accounting_method' => 'FAKTURAMETODEN',
        'vat_registered' => true,
        'vat_period' => 'QUARTERLY',
        'reporting_framework' => 'K2',
        'f_skatt' => true,
        'employer_registered' => false,
        ...$overrides,
    ];
}

it('creates a company owned by the creator and switches to it', function () {
    $user = User::factory()->create();
    $input = validCompanyInput();

    $company = app(CreateCompany::class)->create($user, $input);

    $digits = $input['org_number'];

    expect($company->user_id)->toBe($user->id)
        ->and($company->personal_team)->toBeFalse()
        ->and($company->org_number)->toBe(substr($digits, 0, 6).'-'.substr($digits, 6))
        ->and($company->vat_number)->toBe("SE{$digits}01")
        ->and($company->legal_name)->toBe('Testbolaget AB')
        ->and($company->legal_form)->toBe(LegalForm::Ab)
        ->and($company->bas_version)->toBe(CreateCompany::BAS_VERSION)
        ->and($company->base_currency)->toBe('SEK')
        ->and($user->fresh()->current_team_id)->toBe($company->id)
        ->and($user->fresh()->ownsTeam($company))->toBeTrue();
});

it('accepts organisation numbers with century and hyphen', function () {
    $digits = CompanyFactory::organisationNumber()->digits;
    $input = validCompanyInput(['org_number' => '16'.substr($digits, 0, 6).'-'.substr($digits, 6)]);

    $company = app(CreateCompany::class)->create(User::factory()->create(), $input);

    expect($company->org_number)->toBe(substr($digits, 0, 6).'-'.substr($digits, 6));
});

it('rejects an organisation number with a wrong check digit', function () {
    $body = '559123456';
    $wrong = $body.((Luhn::checkDigit($body) + 1) % 10);

    app(CreateCompany::class)->create(User::factory()->create(), validCompanyInput(['org_number' => $wrong]));
})->throws(ValidationException::class);

it('rejects an organisation number that is already registered', function () {
    $existing = Company::factory()->create();

    app(CreateCompany::class)->create(User::factory()->create(), validCompanyInput([
        'org_number' => str_replace('-', '', $existing->org_number),
    ]));
})->throws(ValidationException::class, 'Det finns redan ett företag med det här organisationsnumret.');

it('lets another account register the same organisation number on a hosted installation, but not the same user twice', function () {
    config(['app.landing.hosted' => true]);
    $owner = User::factory()->create();
    $first = app(CreateCompany::class)->create($owner, validCompanyInput(['accept_agreement' => true]));
    $digits = str_replace('-', '', $first->org_number);

    $other = app(CreateCompany::class)->create(User::factory()->create(), validCompanyInput(['org_number' => $digits, 'accept_agreement' => true]));

    expect($other->org_number)->toBe($first->org_number)
        ->and($other->id)->not->toBe($first->id)
        ->and(fn () => app(CreateCompany::class)->create($owner, validCompanyInput(['org_number' => $digits, 'accept_agreement' => true])))
        ->toThrow(ValidationException::class, 'Det finns redan ett företag med det här organisationsnumret.');
});

it('stores no VAT number or VAT period when the company is not VAT registered', function () {
    $company = app(CreateCompany::class)->create(User::factory()->create(), validCompanyInput([
        'vat_registered' => false,
    ]));

    expect($company->vat_registered)->toBeFalse()
        ->and($company->vat_number)->toBeNull()
        ->and($company->vat_period)->toBe(VatPeriod::None);
});

it('requires the mandatory fields', function (string $field) {
    $input = validCompanyInput();
    unset($input[$field]);

    expect(fn () => app(CreateCompany::class)->create(User::factory()->create(), $input))
        ->toThrow(ValidationException::class);
})->with(['name', 'org_number', 'legal_form', 'accounting_method', 'vat_period', 'reporting_framework']);

it('creates a company from the form and opens the overview', function () {
    $this->actingAs($user = User::factory()->create());

    Livewire::test(CreateCompanyForm::class)
        ->set('name', 'Formbolaget AB')
        ->set('org_number', CompanyFactory::organisationNumber()->formatted())
        ->call('create')
        ->assertHasNoErrors()
        ->assertRedirect(route('dashboard'));

    expect($user->fresh()->currentCompany()?->name)->toBe('Formbolaget AB');
});

it('shows a Swedish error for an invalid organisation number in the form', function () {
    $this->actingAs(User::factory()->create());

    Livewire::test(CreateCompanyForm::class)
        ->set('name', 'Formbolaget AB')
        ->set('org_number', '123')
        ->call('create')
        ->assertHasErrors('org_number')
        ->assertSee('Organisationsnumret stämmer inte');
});

it('requires the terms and data processing agreement on the hosted service and records who accepted which version', function () {
    config(['app.landing.hosted' => true]);
    $user = User::factory()->create();
    $input = ['name' => 'Värdbolaget AB', 'org_number' => '556334-3689', 'legal_form' => 'AB', 'fiscal_year_start_month' => 1, 'accounting_method' => 'FAKTURAMETODEN', 'vat_registered' => true, 'vat_period' => 'QUARTERLY', 'reporting_framework' => 'K2', 'f_skatt' => true, 'employer_registered' => false];

    expect(fn () => app(CreateCompany::class)->create($user, $input))->toThrow(ValidationException::class);

    $company = app(CreateCompany::class)->create($user, [...$input, 'accept_agreement' => true]);

    expect($company->agreement_version)->toBe(LegalPageController::VERSION)
        ->and($company->agreement_accepted_by)->toBe($user->id)
        ->and($company->agreement_accepted_at)->not->toBeNull();
});
